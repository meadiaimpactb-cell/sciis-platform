<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Models\PeriodicReport;
use App\Services\Reporting\PeriodicReportService;
use App\Services\Reporting\ReportFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Journey 11.10 — periodic reports published to the `public` audience: aggregated KPIs only (PUBLIC_KEYS series,
 * never record rows) and the public PDF. Drafts, approved-but-unpublished and staff-only reports are invisible (404).
 */
class PublicPeriodicReportController extends Controller
{
    /** GET /public/periodic-reports */
    public function index(): JsonResponse
    {
        $list = PeriodicReport::query()->published()->where('audience', 'public')->orderByDesc('period_start')->limit(24)->get();

        return response()->json(['data' => $list->map(fn (PeriodicReport $r): array => $this->toArray($r, false))->all()]);
    }

    /** GET /public/periodic-reports/{uuid} */
    public function show(string $uuid): JsonResponse
    {
        return $this->ok($this->toArray($this->find($uuid), true));
    }

    /** GET /public/periodic-reports/{uuid}/pdf */
    public function pdf(string $uuid, ReportFiles $files): Response
    {
        $report = $this->find($uuid);

        return response($files->periodicPdf($report, true), 200, [
            'Content-Type' => ReportFiles::MIME['pdf'],
            'Content-Disposition' => sprintf('inline; filename="sciis-%s-%s.pdf"', $report->period_type, $report->period_start->format('Y-m')),
        ]);
    }

    private function find(string $uuid): PeriodicReport
    {
        return PeriodicReport::query()->published()->where('audience', 'public')->where('uuid', $uuid)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function toArray(PeriodicReport $r, bool $withKpis): array
    {
        $kpis = array_values(array_filter((array) ($r->snapshot['kpis'] ?? []), fn (array $k): bool => in_array($k['key'] ?? '', PeriodicReportService::PUBLIC_KEYS, true)));

        return [
            'uuid' => $r->uuid,
            'period_type' => $r->period_type,
            'period_start' => $r->period_start->toDateString(),
            'period_end' => $r->period_end->toDateString(),
            'title' => $r->title,
            'summary' => $r->summary,
            'published_at' => $r->published_at?->toIso8601String(),
            'kpis' => $withKpis ? array_map(fn (array $k): array => array_intersect_key($k, array_flip(['key', 'title', 'kind', 'unit', 'series'])), $kpis) : null,
        ];
    }
}
