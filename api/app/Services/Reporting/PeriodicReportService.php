<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Exceptions\DomainException;
use App\Models\PeriodicReport;
use App\Models\User;
use App\Notifications\PeriodicReportNotification;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Services\ReportService;
use Illuminate\Support\Carbon;

/**
 * Journey 11.10 — periodic report approval before publishing:
 *   generate (draft snapshot of the KPIs for the period) → approve (`reports.approve`) → publish to an audience
 *   (staff = every staff account in the portal; public = also the public API, aggregates only). An approved report
 *   can be returned to draft with a note; a published one can be withdrawn back to approved.
 * Every step is audited; approvers are notified of new drafts, the author of returns, and staff of publications.
 */
final class PeriodicReportService
{
    /** KPIs safe for the public audience — aggregated counts and rates only (no record lists, no internal SLAs). */
    public const PUBLIC_KEYS = [
        'registrations_by_type', 'memberships_by_status', 'opportunities_funnel', 'opportunities_by_sector_stage_region',
        'eligibility_rate', 'interest_rate', 'connection_rate',
    ];

    public function __construct(
        private readonly ReportService $reports,
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    /** @return array{0: Carbon, 1: Carbon} start/end (dates) of the period containing `$anchor`. */
    public static function period(string $type, Carbon $anchor): array
    {
        $a = $anchor->copy()->startOfDay();

        return match ($type) {
            'monthly' => [$a->copy()->startOfMonth(), $a->copy()->endOfMonth()->startOfDay()],
            'annual' => [$a->copy()->startOfYear(), $a->copy()->endOfYear()->startOfDay()],
            default => [$a->copy()->firstOfQuarter(), $a->copy()->lastOfQuarter()->startOfDay()],
        };
    }

    public static function defaultTitle(string $type, Carbon $start): string
    {
        return match ($type) {
            'monthly' => __('reporting.periodic.title_monthly', ['month' => $start->format('Y-m')]),
            'annual' => __('reporting.periodic.title_annual', ['year' => $start->format('Y')]),
            default => __('reporting.periodic.title_quarterly', ['q' => $start->quarter, 'year' => $start->format('Y')]),
        };
    }

    /** Draft (or refreshed draft) snapshot for the period containing `$anchor`. */
    public function generate(string $type, Carbon $anchor, User $actor, ?string $title = null, ?string $summary = null): PeriodicReport
    {
        [$start, $end] = self::period($type, $anchor);

        // whereDate: DATE columns hold a bare date on MySQL but a full timestamp string on SQLite.
        $report = PeriodicReport::query()->where('period_type', $type)->whereDate('period_start', $start->toDateString())->first()
            ?? new PeriodicReport(['period_type' => $type, 'period_start' => $start->toDateString()]);
        if ($report->exists && $report->status !== PeriodicReport::DRAFT) {
            throw new DomainException(__('reporting.errors.periodic_locked'), 'periodic_report_locked', 409);
        }
        $isNew = ! $report->exists;

        $report->fill([
            'period_end' => $end->toDateString(),
            'title' => filled($title) ? $title : ($report->title ?: self::defaultTitle($type, $start)),
            'summary' => $summary ?? $report->summary,
            'snapshot' => $this->snapshot($start, $end),
            'status' => PeriodicReport::DRAFT,
            'audience' => $report->audience ?: 'staff',
            'generated_by' => $actor->id,
            'generated_at' => now(),
        ])->save();

        $this->audit->log($isNew ? 'periodic_report.generated' : 'periodic_report.regenerated', $report, null, ['period_type' => $type, 'period_start' => $start->toDateString(), 'period_end' => $end->toDateString()], $actor);

        $approvers = User::query()->staff()->active()->permission('reports.approve')->whereKeyNot($actor->id)->get();
        $this->notifications->sendToMany($approvers, new PeriodicReportNotification($report, 'awaiting'));

        return $report;
    }

    /** @param array{title?: string|null, summary?: string|null, audience?: string|null} $data */
    public function update(PeriodicReport $report, array $data, User $actor): PeriodicReport
    {
        $this->expect($report, [PeriodicReport::DRAFT]);
        $before = $report->only(['title', 'summary', 'audience']);
        $report->fill(array_filter($data, fn ($v): bool => $v !== null))->save();
        $this->audit->log('periodic_report.updated', $report, $before, $report->only(['title', 'summary', 'audience']), $actor);

        return $report;
    }

    public function approve(PeriodicReport $report, User $actor, ?string $note = null): PeriodicReport
    {
        $this->expect($report, [PeriodicReport::DRAFT]);
        $report->fill(['status' => PeriodicReport::APPROVED, 'approved_by' => $actor->id, 'approved_at' => now(), 'review_note' => $note])->save();
        $this->audit->log('periodic_report.approved', $report, ['status' => PeriodicReport::DRAFT], ['status' => PeriodicReport::APPROVED, 'note' => $note], $actor);

        return $report;
    }

    public function returnToDraft(PeriodicReport $report, User $actor, string $note): PeriodicReport
    {
        $this->expect($report, [PeriodicReport::APPROVED]);
        $report->fill(['status' => PeriodicReport::DRAFT, 'approved_by' => null, 'approved_at' => null, 'review_note' => $note])->save();
        $this->audit->log('periodic_report.returned', $report, ['status' => PeriodicReport::APPROVED], ['status' => PeriodicReport::DRAFT, 'note' => $note], $actor);

        $this->notifications->send($report->generator, new PeriodicReportNotification($report, 'returned'));

        return $report;
    }

    public function publish(PeriodicReport $report, User $actor, ?string $audience = null): PeriodicReport
    {
        $this->expect($report, [PeriodicReport::APPROVED]);
        $report->fill(['status' => PeriodicReport::PUBLISHED, 'audience' => $audience ?? $report->audience, 'published_by' => $actor->id, 'published_at' => now()])->save();
        $this->audit->log('periodic_report.published', $report, ['status' => PeriodicReport::APPROVED], ['status' => PeriodicReport::PUBLISHED, 'audience' => $report->audience], $actor);

        $this->notifications->sendToMany(User::query()->staff()->active()->whereKeyNot($actor->id)->get(), new PeriodicReportNotification($report, 'published'));

        return $report;
    }

    public function unpublish(PeriodicReport $report, User $actor): PeriodicReport
    {
        $this->expect($report, [PeriodicReport::PUBLISHED]);
        $report->fill(['status' => PeriodicReport::APPROVED, 'published_by' => null, 'published_at' => null])->save();
        $this->audit->log('periodic_report.unpublished', $report, ['status' => PeriodicReport::PUBLISHED], ['status' => PeriodicReport::APPROVED], $actor);

        return $report;
    }

    /**
     * Snapshot of every phase-1 KPI over the period (the numbers are frozen once approved).
     *
     * @return array<string, mixed>
     */
    private function snapshot(Carbon $start, Carbon $end): array
    {
        $from = $start->toDateString();
        $to = $end->copy()->endOfDay()->toDateTimeString();

        $kpis = array_map(function (string $key) use ($from, $to): array {
            $r = $this->reports->build($key, $from, $to);

            return ['key' => $key, 'title' => $r['title'], 'kind' => $r['kind'], 'unit' => $r['unit'], 'series' => $r['series'], 'columns' => $r['columns'], 'rows' => $r['rows']];
        }, ReportService::P1_KEYS);

        return ['kpis' => $kpis, 'period' => ['from' => $from, 'to' => $end->toDateString()], 'computed_at' => now()->toIso8601String()];
    }

    /** @param array<int, string> $statuses */
    private function expect(PeriodicReport $report, array $statuses): void
    {
        if (! in_array($report->status, $statuses, true)) {
            throw new DomainException(__('reporting.errors.periodic_state'), 'periodic_report_state', 409);
        }
    }
}
