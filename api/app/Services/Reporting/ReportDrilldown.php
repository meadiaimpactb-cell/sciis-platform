<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Exceptions\DomainException;
use App\Models\User;
use App\Services\ReportService;

/**
 * RPT-03 — the records behind one bar of a KPI chart. Each drillable report maps its segment to a dataset query
 * (ReportDatasets), so the list obeys the same column sensitivity and record-link rules as the report builder.
 * `overdue_items` is already a record list: its rows are filtered by kind.
 */
final class ReportDrilldown
{
    public const LIMIT = 200;

    public function __construct(private readonly ReportService $reports, private readonly ReportDatasets $datasets) {}

    /**
     * @param  array{0: string|null, 1: string|null, 2: string|null, 3: string|null}  $filters  from, to, sector, region
     * @return array<string, mixed>
     */
    public function run(User $user, string $key, string $segment, array $filters): array
    {
        $dataset = ReportService::DRILL[$key] ?? null;
        if ($dataset === null) {
            // Unknown keys → the report's own 404; known but not drillable → 422.
            $this->reports->build($key, ...$filters);
            throw new DomainException(__('reporting.errors.not_drillable'), 'report_not_drillable', 422);
        }

        [$from, $to, $sector, $region] = $filters;

        if ($key === 'overdue_items') {
            return $this->overdue($user, $segment, $filters);
        }

        $f = match ($key) {
            'registrations_by_type' => ['investor_type' => $segment, 'date_field' => 'created_at'],
            'memberships_by_status' => ['status' => $segment, 'date_field' => 'updated_at'],
            'opportunities_funnel' => ['status' => ReportService::FUNNEL_STAGES[$segment] ?? ($segment === 'submitted' ? null : ['-'])],
            'opportunities_by_sector_stage_region' => ['sector' => $segment],
            'acquisition_channels' => ['how_heard' => $segment, 'date_field' => 'created_at'],
        };
        $f += ['from' => $from, 'to' => $to];
        if (in_array($key, ReportService::OPPORTUNITY_SCOPED, true)) {
            $f['sector'] ??= $sector;
            $f['region'] = $region;
        }

        $result = $this->datasets->run($user, $dataset, ['filters' => array_filter($f, fn ($v): bool => $v !== null && $v !== '')], self::LIMIT);

        return $result + ['report' => $key, 'segment' => $segment, 'limit' => self::LIMIT];
    }

    /** @param array{0: string|null, 1: string|null, 2: string|null, 3: string|null} $filters */
    private function overdue(User $user, string $segment, array $filters): array
    {
        $report = $this->reports->build('overdue_items', ...$filters);
        $rows = array_values(array_filter($report['rows'], fn (array $r): bool => ($r['kind'] ?? null) === $segment));
        $canOpen = $this->datasets->hasDomainAccess($user, 'opportunities');
        $columns = array_values(array_diff($report['columns'], ['kind']));

        return [
            'dataset' => 'opportunities',
            'title' => $report['title'],
            'columns' => array_map(fn (string $c): array => ['key' => $c, 'label' => __('reports.columns.'.$c)], $columns),
            'rows' => array_map(function (array $r) use ($columns, $canOpen): array {
                $row = array_intersect_key($r, array_flip($columns));
                if ($canOpen && isset($r['uuid'])) {
                    $row['_link'] = ['type' => 'opportunity', 'id' => $r['uuid']];
                }

                return $row;
            }, array_slice($rows, 0, self::LIMIT)),
            'total' => count($rows),
            'grouped' => false,
            'truncated' => count($rows) > self::LIMIT,
            'series' => [],
            'report' => 'overdue_items',
            'segment' => $segment,
            'limit' => self::LIMIT,
        ];
    }
}
