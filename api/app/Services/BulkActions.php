<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\InterestStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\DomainException;
use App\Models\AuditLog;
use App\Models\CompletionRequest;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\User;
use App\Notifications\CompletionReminderNotification;
use App\Notifications\PendingRequestReminderNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * ADM-08 — bulk actions on the opportunities and memberships queues. Each record is authorised and audited on
 * its own (one audit row per record, `bulk: true`), and records that cannot take the action are reported back as
 * `skipped` with a reason instead of failing the whole batch.
 */
final class BulkActions
{
    /** A record reminded within this window is skipped (no reminder spam from repeated clicks). */
    private const REMIND_COOLDOWN_HOURS = 24;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
        private readonly SettingsService $settings,
        private readonly WorkloadAssignment $workload,
    ) {}

    /**
     * Assign a reviewer to many files — a chosen reviewer (must hold `opportunities.transition`) or `auto`
     * (ADM-09 strategy, workload updated as the batch progresses).
     *
     * @param  array<int, string>  $uuids
     * @return array{updated: int, skipped: array<int, array{id: string, reason: string, label: string}>}
     */
    public function assignOpportunities(User $actor, array $uuids, ?int $reviewerId, bool $auto = false): array
    {
        $reviewer = null;
        if (! $auto) {
            $reviewer = User::query()->staff()->active()->find($reviewerId);
            if ($reviewer === null || ! $reviewer->can('opportunities.transition')) {
                throw new DomainException(__('reporting.errors.reviewer_invalid'), 'reviewer_invalid', 422, ['reviewer_user_id' => [__('reporting.errors.reviewer_invalid')]]);
            }
        } elseif ($this->workload->reviewerPool()->isEmpty()) {
            throw new DomainException(__('reporting.errors.no_pool'), 'reviewer_pool_empty', 422);
        }

        $workloads = null;
        $lastId = null;
        $updated = 0;
        $skipped = [];
        $found = Opportunity::query()->whereIn('uuid', $uuids)->whereNotNull('submitted_at')->get()->keyBy('uuid');

        foreach ($uuids as $uuid) {
            /** @var Opportunity|null $o */
            $o = $found->get($uuid);
            $reason = match (true) {
                $o === null => 'not_found',
                ! $actor->can('assign', $o) => 'forbidden',
                $o->status->isTerminal() => 'terminal',
                default => null,
            };
            if ($reason !== null) {
                $skipped[] = $this->skip($uuid, $reason);

                continue;
            }

            $target = $auto ? $this->workload->pickReviewer($workloads, $lastId) : $reviewer;
            if ($target === null) {
                $skipped[] = $this->skip($uuid, 'no_reviewer');

                continue;
            }

            $before = ['assigned_reviewer_id' => $o->assigned_reviewer_id];
            $o->assigned_reviewer_id = $target->id;
            $o->review_due_at ??= now()->addDays($this->settings->slaDays('review'));
            $o->save();
            $this->audit->log('opportunity.assigned', $o, $before, ['assigned_reviewer_id' => $target->id, 'bulk' => true, 'auto' => $auto], $actor);
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Remind owners who have a pending request: an open completion request (journey 11.3 reminder) and/or
     * investor access requests awaiting their decision.
     *
     * @param  array<int, string>  $uuids
     * @return array{updated: int, skipped: array<int, array{id: string, reason: string, label: string}>}
     */
    public function remindOpportunities(User $actor, array $uuids): array
    {
        $updated = 0;
        $skipped = [];
        $found = Opportunity::query()->with('owner')->whereIn('uuid', $uuids)->whereNotNull('submitted_at')->get()->keyBy('uuid');

        foreach ($uuids as $uuid) {
            /** @var Opportunity|null $o */
            $o = $found->get($uuid);
            if ($o === null || ! $actor->can('view', $o)) {
                $skipped[] = $this->skip($uuid, $o === null ? 'not_found' : 'forbidden');

                continue;
            }
            if ($this->recentlyReminded('opportunity.reminder_sent', $o)) {
                $skipped[] = $this->skip($uuid, 'recently_reminded');

                continue;
            }

            $kinds = [];
            $completion = CompletionRequest::query()->open()->where('opportunity_id', $o->id)->latest('id')->first();
            if ($completion !== null) {
                $this->notifications->send($o->owner, new CompletionReminderNotification($completion));
                $kinds[] = 'completion';
            }
            $pendingAccess = $o->interests()->where('status', InterestStatus::AwaitingOwnerApproval->value)->count();
            if ($pendingAccess > 0) {
                $this->notifications->send($o->owner, new PendingRequestReminderNotification('access_requests', $o, null, $pendingAccess));
                $kinds[] = 'access_requests';
            }

            if ($kinds === []) {
                $skipped[] = $this->skip($uuid, 'no_pending_request');

                continue;
            }

            $this->audit->log('opportunity.reminder_sent', $o, null, ['kinds' => $kinds, 'recipient_id' => $o->owner_user_id, 'bulk' => true], $actor);
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * @param  array<int, int>  $ids
     * @return array{updated: int, skipped: array<int, array{id: string, reason: string, label: string}>}
     */
    public function assignMemberships(User $actor, array $ids, int $reviewerId): array
    {
        $reviewer = User::query()->staff()->active()->find($reviewerId);
        if ($reviewer === null || ! $reviewer->can('memberships.review')) {
            throw new DomainException(__('reporting.errors.reviewer_invalid'), 'reviewer_invalid', 422, ['reviewer_user_id' => [__('reporting.errors.reviewer_invalid')]]);
        }

        $updated = 0;
        $skipped = [];
        $found = Membership::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            /** @var Membership|null $m */
            $m = $found->get($id);
            if ($m === null || ! $actor->can('review', $m)) {
                $skipped[] = $this->skip((string) $id, $m === null ? 'not_found' : 'forbidden');

                continue;
            }

            $before = ['assigned_reviewer_id' => $m->assigned_reviewer_id];
            $m->assigned_reviewer_id = $reviewer->id;
            $m->save();
            $this->audit->log('membership.assigned', $m, $before, ['assigned_reviewer_id' => $reviewer->id, 'bulk' => true], $actor);
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Remind investors with a pending membership step: file sent back for completion, or never submitted.
     *
     * @param  array<int, int>  $ids
     * @return array{updated: int, skipped: array<int, array{id: string, reason: string, label: string}>}
     */
    public function remindMemberships(User $actor, array $ids): array
    {
        $updated = 0;
        $skipped = [];
        $found = Membership::query()->with('user')->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($ids as $id) {
            /** @var Membership|null $m */
            $m = $found->get($id);
            if ($m === null || ! $actor->can('review', $m)) {
                $skipped[] = $this->skip((string) $id, $m === null ? 'not_found' : 'forbidden');

                continue;
            }
            $kind = match ($m->status) {
                MembershipStatus::NeedsCompletion => 'membership_completion',
                MembershipStatus::Registered => 'membership_submit',
                default => null,
            };
            if ($kind === null) {
                $skipped[] = $this->skip((string) $id, 'no_pending_request');

                continue;
            }
            if ($this->recentlyReminded('membership.reminder_sent', $m)) {
                $skipped[] = $this->skip((string) $id, 'recently_reminded');

                continue;
            }

            $this->notifications->send($m->user, new PendingRequestReminderNotification($kind, null, $m));
            $this->audit->log('membership.reminder_sent', $m, null, ['kind' => $kind, 'recipient_id' => $m->user_id, 'bulk' => true], $actor);
            $updated++;
        }

        return ['updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * One `*.exported` audit row per exported record.
     *
     * @param  Collection<int, Model>  $records
     */
    public function auditExport(string $action, Collection $records, User $actor, string $format): void
    {
        foreach ($records as $record) {
            $this->audit->log($action, $record, null, ['format' => $format, 'bulk' => true], $actor);
        }
    }

    private function recentlyReminded(string $action, Model $record): bool
    {
        return AuditLog::query()->where('action', $action)
            ->where('auditable_type', $record->getMorphClass())->where('auditable_id', $record->getKey())
            ->where('created_at', '>=', now()->subHours(self::REMIND_COOLDOWN_HOURS))
            ->exists();
    }

    /** @return array{id: string, reason: string, label: string} */
    private function skip(string $id, string $reason): array
    {
        return ['id' => $id, 'reason' => $reason, 'label' => __('reporting.skip.'.$reason)];
    }
}
