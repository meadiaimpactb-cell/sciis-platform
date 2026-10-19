<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Opportunity;
use App\Models\User;
use App\Notifications\SlaOverdueNotification;
use App\Services\AuditLogger;
use Illuminate\Console\Command;

/**
 * ADM-03 — daily SLA reminders. Overdue items go to the assigned reviewer (or all reviewers)
 * and to super_admins; evaluation overdue goes to the assigned evaluators as well.
 */
class SendSlaRemindersCommand extends Command
{
    protected $signature = 'sciis:sla-reminders {--dry-run : Only list overdue items}';

    protected $description = 'Send SLA overdue reminders to staff (ADM-03)';

    public function handle(AuditLogger $audit): int
    {
        $overdue = Opportunity::query()->overdue()->with(['assignedReviewer', 'evaluationAssignments.evaluator'])->get();

        if ($overdue->isEmpty()) {
            $this->info('No overdue items.');

            return self::SUCCESS;
        }

        $this->table(['ref', 'name', 'status', 'kind'], $overdue->map(fn (Opportunity $o): array => [$o->reference_no, $o->name, $o->status->value, $o->overdueKind()])->all());

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        // Group by recipient.
        $byUser = [];
        $admins = User::query()->staff()->active()->role('super_admin')->get();

        foreach ($overdue as $opportunity) {
            $recipients = collect($admins);
            if ($opportunity->assignedReviewer) {
                $recipients->push($opportunity->assignedReviewer);
            } elseif ($opportunity->overdueKind() === 'review') {
                $recipients = $recipients->merge(User::query()->staff()->active()->role('reviewer')->get());
            }
            if ($opportunity->overdueKind() === 'evaluation') {
                $recipients = $recipients->merge($opportunity->evaluationAssignments->map->evaluator->filter());
            }
            foreach ($recipients->unique('id') as $user) {
                $byUser[$user->id]['user'] = $user;
                $byUser[$user->id]['items'][] = $opportunity;
            }
        }

        foreach ($byUser as $entry) {
            $entry['user']->notify(SlaOverdueNotification::fromOpportunities($entry['items']));
        }

        $audit->log('sla.reminders_sent', null, null, ['overdue' => $overdue->count(), 'recipients' => count($byUser)]);
        $this->info(sprintf('Sent reminders for %d items to %d staff.', $overdue->count(), count($byUser)));

        return self::SUCCESS;
    }
}
