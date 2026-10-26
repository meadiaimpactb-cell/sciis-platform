<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DocumentKind;
use App\Enums\Sensitivity;
use App\Enums\UserStatus;
use App\Exceptions\DomainException;
use App\Models\Interest;
use App\Models\MessageThread;
use App\Models\MessageThreadRead;
use App\Models\Nomination;
use App\Models\Opportunity;
use App\Models\ThreadMessage;
use App\Models\User;
use App\Notifications\MessageReceivedNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * MTG-03 — in-platform messages tied to a record, always between the council and ONE party:
 *  - `opportunity` file ↔ its owner (and entity colleagues: read-only unless "can act");
 *  - `interest` / `nomination` ↔ the investor (and institutional colleagues, same rule).
 * The owner never reaches an investor thread and the investor never reaches an opportunity thread (MSG-01 anonymity);
 * staff-only internal notes stay in `internal_notes`. Attachments go through DocumentService (scan, encryption,
 * signed URLs, access log). Every message is audited (no body in the trail).
 */
class MessagingService
{
    public const TYPES = ['opportunity' => Opportunity::class, 'interest' => Interest::class, 'nomination' => Nomination::class];

    public const MAX_MESSAGES = 200;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DocumentService $documents,
        private readonly NotificationService $notifications,
    ) {}

    // ---------------------------------------------------------------- subjects & access

    public function resolveSubject(string $type, string $key): Opportunity|Interest|Nomination
    {
        $subject = match ($type) {
            'opportunity' => Opportunity::query()->where('uuid', $key)->first(),
            'interest' => ctype_digit($key) ? Interest::query()->find((int) $key) : null,
            'nomination' => ctype_digit($key) ? Nomination::query()->find((int) $key) : null,
            default => null,
        };
        if ($subject === null) {
            throw new DomainException(__('messages.errors.not_found'), 'not_found', 404);
        }

        return $subject;
    }

    public function typeOf(Model $subject): string
    {
        return (string) array_search($subject::class, self::TYPES, true);
    }

    public function keyOf(Model $subject): string
    {
        return $subject instanceof Opportunity ? $subject->uuid : (string) $subject->getKey();
    }

    public function canView(User $user, Model $subject): bool
    {
        if ($user->isStaff()) {
            return $this->staffMay($user, $subject);
        }

        return match (true) {
            $subject instanceof Opportunity => $user->isEntrepreneur() && ($subject->isOwnedBy($user) || $subject->isSharedWithColleague($user)),
            $subject instanceof Interest, $subject instanceof Nomination => $user->isInvestor() && in_array($subject->investor_user_id, $user->organizationPeerIds(), true),
            default => false,
        };
    }

    public function canPost(User $user, Model $subject): bool
    {
        if (! $this->canView($user, $subject) || ! $user->isActive()) {
            return false;
        }
        if ($user->isStaff()) {
            return true;
        }

        return $subject instanceof Opportunity ? $subject->isOwnedBy($user) : $user->canActForOrganization();
    }

    /** Staff side: file correspondence needs opportunities.transition, investor correspondence nominations.manage. */
    private function staffMay(User $user, Model $subject): bool
    {
        return $subject instanceof Opportunity ? $user->can('opportunities.transition') : $user->can('nominations.manage');
    }

    /** Threads the user may read (inbox / unread counts). */
    public function accessibleThreads(User $user): Builder
    {
        $query = MessageThread::query();

        if ($user->isStaff()) {
            $types = [];
            if ($user->can('opportunities.transition')) {
                $types[] = 'opportunity';
            }
            if ($user->can('nominations.manage')) {
                array_push($types, 'interest', 'nomination');
            }

            return $types === [] ? $query->whereRaw('1 = 0') : $query->whereIn('subject_type', $types);
        }

        if ($user->isEntrepreneur()) {
            return $query->where('subject_type', 'opportunity')
                ->whereIn('subject_id', Opportunity::query()->ofAccount($user)->select('id'));
        }

        if ($user->isInvestor()) {
            $peers = $user->organizationPeerIds();

            return $query->where(function (Builder $q) use ($peers): void {
                $q->where(fn (Builder $i) => $i->where('subject_type', 'interest')->whereIn('subject_id', Interest::query()->whereIn('investor_user_id', $peers)->select('id')))
                    ->orWhere(fn (Builder $n) => $n->where('subject_type', 'nomination')->whereIn('subject_id', Nomination::query()->whereIn('investor_user_id', $peers)->select('id')));
            });
        }

        return $query->whereRaw('1 = 0');
    }

    /** Messages of the other side the user has not read yet (applied to a thread_messages query). */
    public function scopeUnreadFor(Builder $messages, User $user): Builder
    {
        return $messages
            ->where('author_side', $user->isStaff() ? ThreadMessage::SIDE_PARTY : ThreadMessage::SIDE_STAFF)
            ->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $user->id))
            ->whereNotExists(function ($read) use ($user): void {
                $read->select(DB::raw(1))->from('message_thread_reads')
                    ->whereColumn('message_thread_reads.thread_id', 'thread_messages.thread_id')
                    ->where('message_thread_reads.user_id', $user->id)
                    ->whereColumn('message_thread_reads.last_read_message_id', '>=', 'thread_messages.id');
            });
    }

    public function unreadCount(User $user): int
    {
        return $this->scopeUnreadFor(ThreadMessage::query()->whereIn('thread_id', $this->accessibleThreads($user)->select('id')), $user)->count();
    }

    public function threadFor(Model $subject): ?MessageThread
    {
        return MessageThread::query()->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey())->first();
    }

    public function markRead(MessageThread $thread, User $user): void
    {
        $lastId = (int) $thread->messages()->max('id');
        MessageThreadRead::query()->updateOrCreate(['thread_id' => $thread->id, 'user_id' => $user->id], ['last_read_message_id' => $lastId, 'last_read_at' => now()]);
    }

    // ---------------------------------------------------------------- posting

    /** @param array<int, UploadedFile> $files */
    public function post(User $author, Model $subject, ?string $body, array $files = []): ThreadMessage
    {
        if (! $this->canPost($author, $subject)) {
            throw new DomainException(__('messages.errors.forbidden'), 'forbidden', 403);
        }
        $body = $body !== null ? trim($body) : null;
        if (($body === null || $body === '') && $files === []) {
            throw new DomainException(__('messages.messages.empty'), 'message_empty', 422, ['body' => [__('messages.messages.empty')]]);
        }
        foreach ($files as $file) {
            $this->documents->validateFile($file);
        }

        $message = DB::transaction(function () use ($author, $subject, $body, $files): ThreadMessage {
            $thread = MessageThread::query()->firstOrCreate(['subject_type' => $subject->getMorphClass(), 'subject_id' => $subject->getKey()]);
            $message = $thread->messages()->create([
                'user_id' => $author->id,
                'author_side' => $author->isStaff() ? ThreadMessage::SIDE_STAFF : ThreadMessage::SIDE_PARTY,
                'body' => $body !== '' ? $body : null,
            ]);
            foreach ($files as $file) {
                $this->documents->upload($file, $author, $message, DocumentKind::MessageAttachment, $file->getClientOriginalName(), Sensitivity::Confidential);
            }
            $thread->forceFill(['last_message_at' => now()])->save();
            $this->markRead($thread, $author);

            $this->audit->log('message.sent', $message, null, [
                'thread' => $thread->uuid,
                'subject_type' => $thread->subject_type,
                'subject_id' => $thread->subject_id,
                'side' => $message->author_side,
                'length' => mb_strlen((string) $message->body),
                'attachments' => count($files),
            ], $author);

            return $message;
        });

        $message->load(['thread', 'author', 'attachments']);
        $this->notifyRecipients($message, $subject, $author);

        return $message;
    }

    /** Recipients of a new message: the other side of the record (never the author). @return Collection<int, User> */
    public function recipients(ThreadMessage $message, Model $subject, User $author): Collection
    {
        $users = $message->author_side === ThreadMessage::SIDE_STAFF ? $this->partyUsers($subject) : $this->staffUsers($message, $subject);

        return $users->reject(fn (User $u): bool => $u->id === $author->id)->unique('id')->values();
    }

    /** @return Collection<int, User> */
    private function partyUsers(Model $subject): Collection
    {
        if ($subject instanceof Opportunity) {
            return User::query()->active()->whereNull('organization_disabled_at')
                ->where(function (Builder $q) use ($subject): void {
                    $q->where('id', $subject->owner_user_id);
                    if ($subject->organization_id !== null) {
                        $q->orWhere(fn (Builder $c) => $c->where('organization_id', $subject->organization_id)->entrepreneurs());
                    }
                })->get();
        }

        $investor = $subject instanceof Interest || $subject instanceof Nomination ? $subject->investor : null;
        if ($investor === null) {
            return collect();
        }

        return User::query()->active()->whereIn('id', $investor->organizationPeerIds())->get();
    }

    /** Staff already in the thread + the file's reviewer / nominator; else the permission holders. @return Collection<int, User> */
    private function staffUsers(ThreadMessage $message, Model $subject): Collection
    {
        $ids = ThreadMessage::query()->where('thread_id', $message->thread_id)->where('author_side', ThreadMessage::SIDE_STAFF)->whereNotNull('user_id')->distinct()->pluck('user_id')->all();
        $ids[] = match (true) {
            $subject instanceof Opportunity => $subject->assigned_reviewer_id,
            $subject instanceof Nomination => $subject->nominated_by,
            $subject instanceof Interest => $subject->nomination?->nominated_by,
            default => null,
        };
        $permission = $subject instanceof Opportunity ? 'opportunities.transition' : 'nominations.manage';

        $staff = User::query()->staff()->where('status', UserStatus::Active->value)->whereIn('id', array_filter($ids))->get()
            ->filter(fn (User $u): bool => $u->can($permission));
        if ($staff->isNotEmpty()) {
            return $staff->values();
        }

        return User::query()->staff()->where('status', UserStatus::Active->value)->permission($permission)->limit(10)->get();
    }

    private function notifyRecipients(ThreadMessage $message, Model $subject, User $author): void
    {
        $recipients = $this->recipients($message, $subject, $author);
        foreach ($recipients as $user) {
            $this->notifications->send($user, new MessageReceivedNotification($message, $this->subjectArray($subject, null), $author->isStaff()));
        }
    }

    // ---------------------------------------------------------------- serialization

    /** @return array<string, mixed> */
    public function subjectArray(Model $subject, ?User $viewer): array
    {
        $opportunity = $subject instanceof Opportunity ? $subject : $subject->opportunity;
        $counterpart = null;
        if ($viewer?->isStaff()) {
            $counterpart = $subject instanceof Opportunity ? $subject->owner?->name : $subject->investor?->name;
        }

        return [
            'type' => $this->typeOf($subject),
            'key' => $this->keyOf($subject),
            'title' => (string) $opportunity?->name,
            'reference_no' => $opportunity?->reference_no,
            'opportunity_uuid' => $opportunity?->uuid,
            'counterpart' => $counterpart,
        ];
    }

    /** @return array<string, mixed> */
    public function messageArray(ThreadMessage $message, User $viewer): array
    {
        return [
            'uuid' => $message->uuid,
            'body' => $message->body,
            'author' => [
                'name' => $message->author?->name ?? __('messages.messages.former_user'),
                'side' => $message->author_side,
                'is_me' => $message->user_id === $viewer->id,
            ],
            'created_at' => $message->created_at?->toIso8601String(),
            'attachments' => $message->attachments->map(fn ($d): array => [
                'uuid' => $d->uuid,
                'title' => $d->title,
                'mime' => $d->mime,
                'size_bytes' => $d->size_bytes,
                'downloadable' => $d->isDownloadable(),
                'scan_pending' => $d->scan_result?->value === 'pending',
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function summaryArray(MessageThread $thread, User $viewer): array
    {
        $latest = $thread->latestMessage;

        return [
            'uuid' => $thread->uuid,
            'subject' => $thread->subject ? $this->subjectArray($thread->subject, $viewer) : null,
            'last_message_at' => $thread->last_message_at?->toIso8601String(),
            'last_message' => $latest ? [
                'excerpt' => $latest->body !== null ? Str::limit(preg_replace('/\s+/u', ' ', $latest->body) ?? '', 140) : null,
                'author_side' => $latest->author_side,
                'created_at' => $latest->created_at?->toIso8601String(),
            ] : null,
            'unread_count' => (int) ($thread->unread_count ?? 0),
            'messages_count' => (int) ($thread->messages_count ?? 0),
        ];
    }
}
