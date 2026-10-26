<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\InterestStatus;
use App\Models\Interest;
use App\Models\Opportunity;
use App\Models\User;
use App\Notifications\SlaOverdueNotification;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * ADM-03 — daily SLA reminders. Overdue items go to super_admins plus the people who own the stage:
 * review/completion/evaluation → assigned reviewer (or all reviewers for review), evaluation → also the assigned evaluators,
 * matching/connection → matching officers (`nominations.manage`), negotiation → matching + agreements officers,
 * overdue owner decisions on access requests → matching officers.
 */
class SendSlaRemindersCommand extends Command
{
    protected $signature = 'sciis:sla-reminders {--dry-run : Only list overdue items}';

    protected $description = 'Send SLA overdue reminders to staff (ADM-03)';

    /** @var array<string, Collection<int, User>> */
    private array $byPermission = [];

    public function handle(AuditLogger $audit): int
    {
        $overdue = Opportunity::query()->overdue()->with(['assignedReviewer', 'evaluationAssignments.evaluator'])->get();
        $ownerDecisions = Interest::query()
            ->with('opportunity')
            ->where('status', InterestStatus::AwaitingOwnerApproval->value)
            ->where('owner_decision_due_at', '<', now())
            ->whereHas('opportunity')
            ->get();

        if ($overdue->isEmpty() && $ownerDecisions->isEmpty()) {
            $this->info('No overdue items.');

            return self::SUCCESS;
        }

        $rows = $overdue->map(fn (Opportunity $o): array => [$o->reference_no, $o->name, $o->status->value, $o->overdueKind()])
            ->concat($ownerDecisions->map(fn (Interest $i): array => [$i->opportunity->reference_no, $i->opportunity->name, $i->status->value, 'owner_decision']));
        $this->table(['ref', 'name', 'status', 'kind'], $rows->all());

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        // Group by recipient.
        $byUser = [];
        $admins = User::query()->staff()->active()->role('super_admin')->get();
        $add = function (Collection $recipients, array $item) use (&$byUser): void {
            foreach ($recipients->filter()->unique('id') as $user) {
                $byUser[$user->id]['user'] = $user;
                $byUser[$user->id]['items'][] = $item;
            }
        };

        foreach ($overdue as $opportunity) {
            $kind = $opportunity->overdueKind();
            $recipients = collect($admins);
            if (in_array($kind, ['review', 'completion', 'evaluation'], true)) {
                $recipients = $opportunity->assignedReviewer
                    ? $recipients->push($opportunity->assignedReviewer)
                    : ($kind === 'review' ? $recipients->merge(User::query()->staff()->active()->role('reviewer')->get()) : $recipients);
            }
            if ($kind === 'evaluation') {
                $recipients = $recipients->merge($opportunity->evaluationAssignments->map->evaluator->filter());
            }
            if (in_array($kind, ['matching', 'connection', 'negotiation'], true)) {
                $recipients = $recipients->merge($this->staffWith('nominations.manage'));
            }
            if ($kind === 'negotiation') {
                $recipients = $recipients->merge($this->staffWith('agreements.manage'));
            }
            $add($recipients, SlaOverdueNotification::itemFor($opportunity));
        }

        foreach ($ownerDecisions as $interest) {
            $add(collect($admins)->merge($this->staffWith('nominations.manage')), SlaOverdueNotification::itemForOwnerDecision($interest));
        }

        foreach ($byUser as $entry) {
            $entry['user']->notify(new SlaOverdueNotification($entry['items']));
        }

        $total = $overdue->count() + $ownerDecisions->count();
        $audit->log('sla.reminders_sent', null, null, ['overdue' => $total, 'owner_decisions' => $ownerDecisions->count(), 'recipients' => count($byUser)]);
        $this->info(sprintf('Sent reminders for %d items to %d staff.', $total, count($byUser)));

        return self::SUCCESS;
    }

    /** Active staff holding a permission (directly or through a role), cached per run. */
    private function staffWith(string $permission): Collection
    {
        return $this->byPermission[$permission] ??= User::query()->staff()->active()->permission($permission)->get();
    }
}
