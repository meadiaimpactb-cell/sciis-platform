<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SavedReport;
use App\Models\User;
use App\Notifications\ScheduledReportNotification;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\Reporting\ReportDatasets;
use App\Services\Reporting\ReportFiles;
use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * RPT-05 — e-mails every due scheduled report (weekly: Sunday 07:00, monthly: the 1st 07:00, Riyadh time) as an
 * xlsx or PDF attachment. The file is built once per recipient with that recipient's own permissions, so a
 * confidential column only reaches people allowed to see it. Recipients who lost `reports.view` (or were
 * suspended) are skipped; a schedule whose author may no longer export is paused. Each send is audited.
 */
class SendScheduledReportsCommand extends Command
{
    protected $signature = 'sciis:scheduled-reports {--dry-run : Only list what would be sent}';

    protected $description = 'Send due scheduled reports by e-mail (RPT-05)';

    public function handle(ReportDatasets $datasets, ReportFiles $files, NotificationService $notifications, AuditLogger $audit, SettingsService $settings): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $due = SavedReport::query()->due()->with('creator')->orderBy('next_run_at')->get();
        $allowedRoles = $settings->array('reports.allowed_export_roles', ['super_admin', 'reports_viewer']);
        $sent = 0;

        foreach ($due as $report) {
            $author = $report->creator;
            if ($author === null || ! $author->isActive() || ! $author->can('reports.export') || ! $author->hasAnyRole($allowedRoles)) {
                $this->warn(sprintf('paused  %s — author may no longer export', $report->name));
                if (! $dryRun) {
                    $report->fill(['next_run_at' => null])->save();
                    $audit->log('saved_report.schedule_paused', $report, null, ['reason' => 'author_cannot_export']);
                }

                continue;
            }

            $recipients = User::query()->staff()->active()->permission('reports.view')
                ->whereIn('id', array_map('intval', (array) ($report->schedule_recipients ?? [])))->get();
            $format = $report->schedule_format === 'pdf' ? 'pdf' : 'xlsx';
            $this->line(sprintf('send    %s (%s) → %d recipient(s)', $report->name, $format, $recipients->count()));

            if (! $dryRun) {
                foreach ($recipients as $recipient) {
                    $result = $datasets->run($recipient, $report->dataset, (array) $report->definition);
                    $content = $files->datasetFile($format, $result, $report->name, [
                        ['label' => __('reporting.pdf.dataset'), 'value' => __('reporting.datasets.'.$report->dataset)],
                        ['label' => __('reporting.pdf.rows'), 'value' => (string) $result['total']],
                    ]);
                    $filename = sprintf('report-%s-%s.%s', substr($report->uuid, 0, 8), now()->format('Ymd'), $format);
                    $notifications->send($recipient, new ScheduledReportNotification($report, $content, $filename, ReportFiles::MIME[$format], count($result['rows']), $result['total']));
                    $audit->log('report.scheduled_sent', $report, null, ['recipient_id' => $recipient->id, 'format' => $format, 'rows' => count($result['rows']), 'total' => $result['total']]);
                    $sent++;
                }

                $report->fill(['last_sent_at' => now(), 'next_run_at' => SavedReport::nextRunAfter((string) $report->schedule_frequency)])->save();
            }
        }

        $this->info(sprintf('%d scheduled report(s) due, %d e-mail(s) sent.', $due->count(), $sent));

        return self::SUCCESS;
    }
}
