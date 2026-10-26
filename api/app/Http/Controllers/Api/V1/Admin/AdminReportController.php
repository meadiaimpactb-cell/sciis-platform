<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exports\AuditLogExport;
use App\Exports\ReportExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\TaxonomyTerm;
use App\Services\AuditLogger;
use App\Services\Reporting\ReportDrilldown;
use App\Services\Reporting\ReportFiles;
use App\Services\ReportService;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** RPT-01/02 (reports + audited xlsx export), AUD-03 (audit log browser + export). */
class AdminReportController extends Controller
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /admin/reports */
    public function index(Request $request): JsonResponse
    {
        $this->assertCan($request, 'reports.view');

        return $this->ok([
            'available' => array_map(fn (string $k): array => ['key' => $k, 'title' => __('reports.'.$k), 'phase' => 1, 'supports_sector_region' => in_array($k, ReportService::OPPORTUNITY_SCOPED, true)], ReportService::P1_KEYS),
            'phase2' => array_map(fn (string $k): array => ['key' => $k, 'title' => __('reports.'.$k), 'phase' => 2], ReportService::P2_KEYS),
        ]);
    }

    /** GET /admin/reports/{key}?from&to&sector&region — RPT-01 (filters accepted as `filter[x]` too). */
    public function show(Request $request, string $key): JsonResponse
    {
        $this->assertCan($request, 'reports.view');
        $report = $this->reports->build($key, ...$this->reportFilters($request));
        $this->audit->log('report.viewed', null, null, ['key' => $key, 'filters' => $report['filters'] + $report['period']], $request->user());

        return $this->ok($report);
    }

    /** GET /admin/reports/{key}/export.xlsx — RPT-02 (roles from settings; audited). */
    public function export(Request $request, string $key): BinaryFileResponse
    {
        $this->assertCan($request, 'reports.export');

        $allowed = $this->settings->array('reports.allowed_export_roles', ['super_admin', 'reports_viewer']);
        if (! $request->user()->hasAnyRole($allowed)) {
            abort(403, __('messages.reports.export_role'));
        }

        $report = $this->reports->build($key, ...$this->reportFilters($request));
        $this->audit->log('report.export', null, null, ['key' => $key, 'rows' => count($report['rows']), 'filters' => $report['filters'] + $report['period']], $request->user());

        return Excel::download(new ReportExport($report), sprintf('%s-%s.xlsx', $key, now()->format('Ymd-His')));
    }

    /** GET /admin/reports/{key}/export.pdf — RPT-06: branded Arabic PDF, same roles + audit as the xlsx export. */
    public function exportPdf(Request $request, string $key, ReportFiles $files): Response
    {
        $this->assertCan($request, 'reports.export');

        $allowed = $this->settings->array('reports.allowed_export_roles', ['super_admin', 'reports_viewer']);
        if (! $request->user()->hasAnyRole($allowed)) {
            abort(403, __('messages.reports.export_role'));
        }

        $report = $this->reports->build($key, ...$this->reportFilters($request));
        $this->audit->log('report.export', null, null, ['key' => $key, 'format' => 'pdf', 'rows' => count($report['rows']), 'filters' => $report['filters'] + $report['period']], $request->user());

        $meta = array_values(array_filter([
            ['label' => __('reporting.pdf.period'), 'value' => ($report['period']['from'] ?? null) || ($report['period']['to'] ?? null) ? trim(($report['period']['from'] ?? '…').' — '.($report['period']['to'] ?? '…')) : __('reporting.pdf.all')],
            $report['filters']['sector'] ? ['label' => __('reporting.columns.sector'), 'value' => (string) TaxonomyTerm::query()->find($report['filters']['sector'])?->label()] : null,
            $report['filters']['region'] ? ['label' => __('reporting.columns.regions'), 'value' => (string) TaxonomyTerm::query()->find($report['filters']['region'])?->label()] : null,
        ]));

        return response($files->kpiPdf($report, $meta), 200, [
            'Content-Type' => ReportFiles::MIME['pdf'],
            'Content-Disposition' => sprintf('attachment; filename="%s-%s.pdf"', $key, now()->format('Ymd-His')),
        ]);
    }

    /** GET /admin/reports/{key}/drilldown?segment=… — RPT-03: the records behind one bar (sensitivity-aware). */
    public function drilldown(Request $request, string $key, ReportDrilldown $drilldown): JsonResponse
    {
        $this->assertCan($request, 'reports.view');
        $segment = trim((string) $request->input('segment', ''));
        abort_if($segment === '', 422, __('reporting.errors.not_drillable'));

        $result = $drilldown->run($request->user(), $key, $segment, $this->reportFilters($request));
        $this->audit->log('report.drilldown', null, null, ['key' => $key, 'segment' => $segment, 'rows' => count($result['rows'])], $request->user());

        return $this->ok($result);
    }

    /** GET /admin/audit-logs — AUD-03 (filters accepted as `filter[x]` or top-level). */
    public function auditLogs(Request $request): JsonResponse
    {
        $this->assertCan($request, 'audit.view');

        $paginator = $this->auditQuery($request)->paginate($this->perPage($request));

        return response()->json([
            'data' => AuditLogResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
        ]);
    }

    /** GET /admin/audit-logs/export.xlsx — AUD-03 export (same filters; audited). */
    public function auditLogsExport(Request $request): BinaryFileResponse
    {
        $this->assertCan($request, 'audit.view');

        $query = $this->auditQuery($request);
        $this->audit->log('audit_log.export', null, null, ['filters' => $this->auditFilters($request), 'rows' => (clone $query)->count()], $request->user());

        return Excel::download(new AuditLogExport($query), sprintf('audit-logs-%s.xlsx', now()->format('Ymd-His')));
    }

    /** @return array{0: string|null, 1: string|null, 2: string|null, 3: string|null} from, to, sector, region */
    private function reportFilters(Request $request): array
    {
        $f = fn (string $key): ?string => ($v = $request->input('filter.'.$key, $request->input($key))) === null || $v === '' ? null : (string) $v;

        return [$f('from'), $f('to'), $f('sector'), $f('region')];
    }

    /** @return array<string, mixed> */
    private function auditFilters(Request $request): array
    {
        $f = fn (string $key): mixed => $request->input('filter.'.$key, $request->input($key));

        return array_filter(['user' => $f('user'), 'action' => $f('action'), 'type' => $f('type'), 'id' => $f('id'), 'from' => $f('from'), 'to' => $f('to')], fn ($v): bool => filled($v));
    }

    private function auditQuery(Request $request): Builder
    {
        $f = $this->auditFilters($request);
        // AUD-03 — date range: a bare date (YYYY-MM-DD, what the web date inputs send) covers the whole day.
        $from = $this->auditBound($f['from'] ?? null, false);
        $to = $this->auditBound($f['to'] ?? null, true);

        return AuditLog::query()
            ->with('user')
            // `user` matches by id, uuid, email or name.
            ->when(isset($f['user']), function (Builder $q) use ($f): void {
                $user = (string) $f['user'];
                if (ctype_digit($user)) {
                    $q->where('user_id', (int) $user);

                    return;
                }
                $q->whereHas('user', fn (Builder $u) => $u->where('uuid', $user)->orWhere('email', strtolower($user))->orWhere('name', 'like', '%'.$user.'%'));
            })
            ->when(isset($f['action']), fn (Builder $q) => $q->where('action', 'like', $f['action'].'%'))
            ->when(isset($f['type']), fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('auditable_type', $f['type'])->orWhere('auditable_type', 'like', '%\\'.ucfirst((string) $f['type']))))
            ->when(isset($f['id']), fn (Builder $q) => $q->where('auditable_id', (int) $f['id']))
            ->when($from !== null, fn (Builder $q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('created_at', '<=', $to))
            ->orderByDesc('id');
    }

    private function auditBound(mixed $value, bool $endOfDay): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }
        try {
            $date = Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) === 1 ? ($endOfDay ? $date->endOfDay() : $date->startOfDay()) : $date;
    }

    private function assertCan(Request $request, string $permission): void
    {
        if (! $request->user()->can($permission)) {
            abort(403, __('messages.errors.forbidden'));
        }
    }
}
