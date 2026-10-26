<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Messages;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Messages\PostMessageRequest;
use App\Models\Document;
use App\Models\MessageThread;
use App\Models\ThreadMessage;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\MessagingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MTG-03 — record-bound messages. Party users (owners / investors) use `/messages…`; staff use the same actions under
 * `/admin/messages…` (staff middleware + enforced 2FA), so the party routes refuse staff accounts.
 */
class MessageController extends Controller
{
    public function __construct(private readonly MessagingService $messaging, private readonly DocumentService $documents) {}

    /** GET /messages | /admin/messages (?unread=1) — the inbox, latest activity first. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->viewer($request);
        $unread = fn (Builder $q) => $this->messaging->scopeUnreadFor($q, $user);

        $paginator = $this->messaging->accessibleThreads($user)
            ->with(['subject', 'latestMessage'])
            ->withCount(['messages', 'messages as unread_count' => $unread])
            ->when($request->boolean('unread'), fn (Builder $q) => $q->whereHas('messages', $unread))
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($paginator->items())->filter(fn (MessageThread $t): bool => $t->subject !== null)
                ->map(fn (MessageThread $t): array => $this->messaging->summaryArray($t, $user))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total(),
                'unread_total' => $this->messaging->unreadCount($user),
            ],
        ]);
    }

    /** GET /messages/unread-count */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->ok(['count' => $this->messaging->unreadCount($this->viewer($request))]);
    }

    /** GET /messages/{type}/{key} — the thread of a record (marks it read). */
    public function show(Request $request, string $type, string $key): JsonResponse
    {
        $user = $this->viewer($request);
        $subject = $this->messaging->resolveSubject($type, $key);
        $this->assertCanView($user, $subject);

        $thread = $this->messaging->threadFor($subject);
        $messages = $thread
            ? $thread->messages()->with(['author', 'attachments'])->latest('id')->limit(MessagingService::MAX_MESSAGES)->get()->reverse()->values()
            : collect();
        if ($thread !== null) {
            $this->messaging->markRead($thread, $user);
        }

        return $this->ok([
            'thread' => $thread ? ['uuid' => $thread->uuid] : null,
            'subject' => $this->messaging->subjectArray($subject, $user),
            'can_post' => $this->messaging->canPost($user, $subject),
            'messages' => $messages->map(fn (ThreadMessage $m): array => $this->messaging->messageArray($m, $user))->all(),
        ]);
    }

    /** POST /messages/{type}/{key} (multipart: body, files[]) */
    public function store(PostMessageRequest $request, string $type, string $key): JsonResponse
    {
        $user = $this->viewer($request);
        $subject = $this->messaging->resolveSubject($type, $key);
        $this->assertCanView($user, $subject);

        $files = array_values(array_filter((array) $request->file('files', [])));
        $message = $this->messaging->post($user, $subject, $request->validated('body'), $files);

        return response()->json(['data' => $this->messaging->messageArray($message, $user), 'message' => __('messages.messages.sent')], 201);
    }

    /** GET /messages/attachments/{document}/download-url — signed URL after the thread access check (DOC-02). */
    public function attachmentUrl(Request $request, Document $document): JsonResponse
    {
        $user = $this->viewer($request);
        $message = $document->documentable;
        if (! $message instanceof ThreadMessage || $message->thread?->subject === null) {
            throw new DomainException(__('messages.errors.not_found'), 'not_found', 404);
        }
        $this->assertCanView($user, $message->thread->subject);

        return $this->ok($this->documents->temporaryUrl($document, $user));
    }

    /** Staff go through `/admin/messages…` only. */
    private function viewer(Request $request): User
    {
        $user = $request->user();
        if ($user->isStaff() !== $request->routeIs('v1.admin.*')) {
            throw new DomainException(__('messages.errors.forbidden'), 'forbidden', 403);
        }

        return $user;
    }

    private function assertCanView(User $user, Model $subject): void
    {
        if (! $this->messaging->canView($user, $subject)) {
            throw new DomainException(__('messages.errors.forbidden'), 'forbidden', 403);
        }
    }
}
