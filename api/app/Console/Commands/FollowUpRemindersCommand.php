<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Opportunity;
use App\Services\AuditLogger;
use App\Services\FollowUpService;
use Illuminate\Console\Command;

/**
 * PRJ-02 / PRJ-03 — daily follow-up pass over the projects in negotiation / execution / operation:
 *  1. recomputes the execution status (delays appear as days pass; a newly stalled project is escalated);
 *  2. reminds the owner of the next unreported period before its due date (`follow_up.report_reminder_days`)
 *     and after it was missed (`follow_up.report_overdue_reminder_days`), each threshold once per period.
 */
class FollowUpRemindersCommand extends Command
{
    protected $signature = 'sciis:follow-up-reminders {--dry-run : Only list what would be sent}';

    protected $description = 'Refresh execution statuses and remind owners of due / overdue progress reports (PRJ-02, PRJ-03)';

    public function handle(FollowUpService $followUps, AuditLogger $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $before = $followUps->reminderDays();
        $after = $followUps->overdueReminderDays();
        $today = today();
        $reminded = 0;
        $refreshed = 0;

        $opportunities = Opportunity::query()
            ->whereIn('status', FollowUpService::values(FollowUpService::followUpStatuses()))
            ->with('owner')
            ->orderBy('id')
            ->get();

        foreach ($opportunities as $opportunity) {
            $followUp = $followUps->ensure($opportunity);
            if (! $dryRun) {
                $followUp = $followUps->refreshStatus($opportunity, $followUp);
                $refreshed++;
            }

            $period = $followUps->openPeriods($opportunity, $followUp)[0] ?? null;
            if ($period === null) {
                continue;
            }

            $daysLeft = (int) $today->diffInDays($period['due_on'], false);
            $start = $period['start']->toDateString();
            if ($daysLeft >= 0) {
                $reached = array_values(array_filter($before, fn (int $t): bool => $daysLeft <= $t));
                $key = $reached === [] ? null : "due:{$start}:".min($reached);
                $notice = 'report_due';
            } else {
                $reached = array_values(array_filter($after, fn (int $t): bool => -$daysLeft >= $t));
                $key = $reached === [] ? null : "overdue:{$start}:".max($reached);
                $notice = 'report_overdue';
            }

            $sent = array_map('strval', (array) ($followUp->reminders_sent ?? []));
            if ($key === null || in_array($key, $sent, true)) {
                continue;
            }

            $this->line(sprintf('%-15s %s — period %s, due %s', $notice, $opportunity->reference_no ?? $opportunity->name, $start, $period['due_on']->toDateString()));
            if ($dryRun) {
                continue;
            }

            $followUps->notifyOwner($opportunity, $notice, [
                'period' => $start.' — '.$period['end']->toDateString(),
                'date' => $period['due_on']->toDateString(),
                'days' => abs($daysLeft),
            ]);
            $followUp->forceFill(['reminders_sent' => array_values(array_unique([...$sent, $key]))])->save();
            $audit->log('follow_up.report_reminder_sent', $followUp, null, ['key' => $key, 'days_left' => $daysLeft], null);
            $reminded++;
        }

        $this->info(sprintf('%s%d statuses refreshed, %d reminders sent.', $dryRun ? '[dry-run] ' : '', $refreshed, $reminded));

        return self::SUCCESS;
    }
}
