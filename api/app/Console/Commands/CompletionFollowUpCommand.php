<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\OpportunityStatus;
use App\Models\CompletionRequest;
use App\Models\Opportunity;
use App\Notifications\CompletionReminderNotification;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\OpportunityStateMachine;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Journey 11.3 — daily completion follow-up:
 *  1. reminds the owner before / on the due date of an open completion request (`sla.completion_reminder_days`,
 *     each threshold once per request);
 *  2. closes a `needs_completion` opportunity whose completion request is overdue by `sla.completion_auto_close_days`
 *     (0 = off) through the state machine (needs_completion → closed, system actor), with a reason the owner sees.
 */
class CompletionFollowUpCommand extends Command
{
    protected $signature = 'sciis:completion-follow-up {--dry-run : Only list what would be sent / closed}';

    protected $description = 'Remind owners of completion due dates and auto-close long-overdue completions (journey 11.3)';

    public function handle(SettingsService $settings, NotificationService $notifications, OpportunityStateMachine $stateMachine, AuditLogger $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $thresholds = $settings->completionReminderDays();
        $closeAfter = $settings->completionAutoCloseDays();
        $today = now()->startOfDay();

        $open = CompletionRequest::query()
            ->open()
            ->whereNotNull('due_at')
            ->with('opportunity.owner')
            ->whereHas('opportunity', fn ($q) => $q->whereNotIn('status', array_map(fn (OpportunityStatus $s): string => $s->value, OpportunityStatus::terminal())))
            ->orderBy('due_at')
            ->get();

        // 1. Owner reminders.
        $reminded = 0;
        foreach ($open as $request) {
            $daysLeft = (int) $today->diffInDays($request->due_at->copy()->startOfDay(), false);
            $sent = array_map('intval', (array) ($request->reminders_sent ?? []));
            $reached = array_values(array_filter($thresholds, fn (int $t): bool => $daysLeft >= 0 && $daysLeft <= $t));
            if ($reached === [] || in_array(min($reached), $sent, true)) {
                continue;
            }

            $this->line(sprintf('remind  %s — due %s (%d days left)', $request->opportunity->reference_no ?? $request->opportunity->name, $request->due_at->toDateString(), $daysLeft));
            if ($dryRun) {
                continue;
            }

            $autoCloseOn = $closeAfter > 0 && $request->opportunity->status === OpportunityStatus::NeedsCompletion
                ? $request->due_at->copy()->addDays($closeAfter)
                : null;
            $notifications->send($request->opportunity->owner, new CompletionReminderNotification($request, $autoCloseOn));
            $request->reminders_sent = array_values(array_unique(array_merge($sent, $reached)));
            $request->save();
            $audit->log('completion.reminder_sent', $request, null, ['days_left' => $daysLeft, 'thresholds' => $reached], null);
            $reminded++;
        }

        // 2. Auto-close (needs_completion only).
        $closed = 0;
        if ($closeAfter > 0) {
            $cutoff = now()->subDays($closeAfter);
            $overdue = $open->filter(fn (CompletionRequest $r): bool => $r->opportunity->status === OpportunityStatus::NeedsCompletion && $r->due_at->lt($cutoff))
                ->unique('opportunity_id');

            foreach ($overdue as $request) {
                $opportunity = $request->opportunity;
                $this->line(sprintf('close   %s — completion due %s', $opportunity->reference_no ?? $opportunity->name, $request->due_at->toDateString()));
                if ($dryRun) {
                    continue;
                }

                $this->autoClose($opportunity, $request->due_at, $closeAfter, $stateMachine, $audit);
                $closed++;
            }
        }

        $this->info(sprintf('%s%d reminders, %d auto-closed.', $dryRun ? '[dry-run] ' : '', $reminded, $closed));

        return self::SUCCESS;
    }

    private function autoClose(Opportunity $opportunity, Carbon $dueAt, int $closeAfter, OpportunityStateMachine $stateMachine, AuditLogger $audit): void
    {
        $reason = __('messages.opportunity.auto_closed_completion', ['due' => $dueAt->toDateString()]);

        // The owner is notified by the state machine (OpportunityStatusChanged → status-changed notification with the reason).
        $stateMachine->systemTransition($opportunity, OpportunityStatus::Closed, $reason, ['auto_close' => 'completion_overdue', 'due_at' => $dueAt->toIso8601String(), 'after_days' => $closeAfter]);

        // Nothing is "required now" from a closed file.
        $opportunity->completionRequests()->open()->update(['resolved_at' => now()]);

        $audit->log('opportunity.auto_closed', $opportunity, null, ['reason' => 'completion_overdue', 'due_at' => $dueAt->toIso8601String(), 'after_days' => $closeAfter], null);
    }
}
