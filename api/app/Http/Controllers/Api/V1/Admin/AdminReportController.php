<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exports\ReportExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Services\AuditLogger;
use App\Services\ReportService;
use App\Services\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** RPT-01/02 (reports + audited xlsx export), AUD-03 (audit log browser). */
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
            'available' => array_map(fn (string $k): array => ['key' => $k, 'title' => __('reports.'.$k), 'phase' => 1], ReportService::P1_KEYS),
            'phase2' => array_map(fn (string $k): array => ['key' => $k, 'title' => __('reports.'.$k), 'phase' => 2], ReportService::P2_KEYS),
        ]);
    }

    /** GET /admin/reports/{key} */
    public function show(Request $request, string $key): JsonResponse
    {
        $this->assertCan($request, 'reports.view');
        $report = $this->reports->build($key, $request->input('from'), $request->input('to'));
        $this->audit->log('report.viewed', null, null, ['key' => $key], $request->user());

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

        $report = $this->reports->build($key, $request->input('from'), $request->input('to'));
        $this->audit->log('report.export', null, null, ['key' => $key, 'rows' => count($report['rows'])], $request->user());

        return Excel::download(new ReportExport($report), sprintf('%s-%s.xlsx', $key, now()->format('Ymd-His')));
    }

    /** GET /admin/audit-logs — AUD-03 */
    public function auditLogs(Request $request): JsonResponse
    {
        $this->assertCan($request, 'audit.view');

        $paginator = AuditLog::query()
            ->with('user')
            ->when($request->filled('user'), fn (Builder $q) => $q->where('user_id', (int) $request->input('user')))
            ->when($request->filled('action'), fn (Builder $q) => $q->where('action', 'like', $request->input('action').'%'))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('auditable_type', $request->input('type')))
            ->when($request->filled('id'), fn (Builder $q) => $q->where('auditable_id', (int) $request->input('id')))
            ->when($request->filled('from'), fn (Builder $q) => $q->where('created_at', '>=', $request->input('from')))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('created_at', '<=', $request->input('to')))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => AuditLogResource::collection($paginator->items())->resolve($request),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'total' => $paginator->total()],
        ]);
    }

    private function assertCan(Request $request, string $permission): void
    {
        if (! $request->user()->can($permission)) {
            abort(403, __('messages.errors.forbidden'));
        }
    }
}
