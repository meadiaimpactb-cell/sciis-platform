<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\MembershipStatus;
use App\Enums\OpportunityStatus;
use App\Enums\UserType;
use App\Exports\DatasetExport;
use App\Exports\ReportExport;
use App\Models\PeriodicReport;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/**
 * RPT-02/04/05/06 — turns computed results into files: KPI reports (ReportService) and dataset results
 * (ReportDatasets) as xlsx or PDF, and periodic reports (journey 11.10) as PDF. Server-side labels mirror the web.
 */
final class ReportFiles
{
    public const MIME = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pdf' => 'application/pdf',
    ];

    public function __construct(private readonly ReportPdf $pdf) {}

    /** @param array<string, mixed> $report ReportService::build() output */
    public function kpiXlsx(array $report): string
    {
        return Excel::raw(new ReportExport($report), ExcelFormat::XLSX);
    }

    /**
     * @param  array<string, mixed>  $report  ReportService::build() output
     * @param  array<int, array{label: string, value: string}>  $meta
     */
    public function kpiPdf(array $report, array $meta = []): string
    {
        return $this->pdf->render((string) $report['title'], $meta, [$this->kpiSection($report, false)]);
    }

    /**
     * @param  array<string, mixed>  $result  ReportDatasets::run() output
     * @param  array<int, array{label: string, value: string}>  $meta
     */
    public function datasetFile(string $format, array $result, string $title, array $meta = []): string
    {
        if ($format === 'pdf') {
            $keys = array_column($result['columns'], 'key');
            $section = [
                'heading' => null,
                'columns' => array_column($result['columns'], 'label'),
                'rows' => array_map(fn (array $r): array => array_map(fn (string $k) => $r[$k] ?? null, $keys), $result['rows']),
                'bars' => $result['grouped'] ? array_slice(array_map(fn (array $s): array => ['label' => $s['label'], 'value' => $s['value']], $result['series']), 0, 15) : [],
                'note' => $result['truncated'] ? __('reporting.pdf.truncated', ['shown' => count($result['rows']), 'total' => $result['total']]) : null,
            ];

            return $this->pdf->render($title, $meta, [$section]);
        }

        return Excel::raw(new DatasetExport($result, $title), ExcelFormat::XLSX);
    }

    /** Journey 11.10 — the approved snapshot; the public version carries aggregates only (no record rows). */
    public function periodicPdf(PeriodicReport $report, bool $public = false): string
    {
        $kpis = (array) ($report->snapshot['kpis'] ?? []);
        if ($public) {
            $kpis = array_values(array_filter($kpis, fn (array $k): bool => in_array($k['key'] ?? '', PeriodicReportService::PUBLIC_KEYS, true)));
        }

        $meta = [
            ['label' => __('reporting.pdf.period'), 'value' => $report->period_start->toDateString().' — '.$report->period_end->toDateString()],
            ['label' => __('reporting.pdf.status'), 'value' => __('reporting.periodic.statuses.'.$report->status)],
        ];
        if ($report->approved_at !== null) {
            $meta[] = ['label' => __('reporting.pdf.approved'), 'value' => $report->approved_at->setTimezone('Asia/Riyadh')->format('Y-m-d').($public ? '' : ' · '.($report->approver?->name ?? ''))];
        }

        return $this->pdf->render(
            $report->title,
            $meta,
            array_map(fn (array $kpi): array => $this->kpiSection($kpi, $public), $kpis),
            $report->summary,
            ! $public,
        );
    }

    /** @param array<string, mixed> $report */
    private function kpiSection(array $report, bool $aggregatesOnly): array
    {
        $kind = (string) ($report['kind'] ?? 'table');
        $unit = match ($report['unit'] ?? null) {
            '%' => '%',
            'days' => __('reporting.metrics.days'),
            default => null,
        };
        $bars = in_array($kind, ['bars', 'funnel'], true)
            ? array_map(fn (array $s): array => ['label' => $this->seriesLabel((string) $s['label']), 'value' => (float) $s['value']], (array) ($report['series'] ?? []))
            : [];

        $columns = (array) ($report['columns'] ?? []);
        $rows = (array) ($report['rows'] ?? []);
        // Number KPIs: the metric table; bar KPIs: the bars already tell the story (rows only when they add records).
        if ($aggregatesOnly || ($bars !== [] && $kind !== 'table' && ($report['key'] ?? '') !== 'overdue_items')) {
            $columns = $kind === 'number' ? $columns : [];
        }

        return [
            'heading' => (string) ($report['title'] ?? ''),
            'columns' => array_map(fn (string $c): string => $this->columnLabel($c), $columns),
            'rows' => array_map(fn (array $r): array => array_map(fn (string $c) => $c === 'metric' ? $this->seriesLabel((string) ($r[$c] ?? '')) : ($r[$c] ?? null), $columns), $rows),
            'bars' => $bars,
            'unit' => $unit,
        ];
    }

    private function columnLabel(string $column): string
    {
        $key = 'reports.columns.'.$column;

        return __($key) !== $key ? __($key) : $column;
    }

    /** Enum values (user type, membership / opportunity status) and metric keys → labels; other labels pass through. */
    public function seriesLabel(string $label): string
    {
        foreach ([UserType::class, MembershipStatus::class, OpportunityStatus::class] as $enum) {
            $case = $enum::tryFrom($label);
            if ($case !== null) {
                return $case->label();
            }
        }
        $key = 'reporting.metrics.'.$label;

        return __($key) !== $key ? __($key) : $label;
    }
}
