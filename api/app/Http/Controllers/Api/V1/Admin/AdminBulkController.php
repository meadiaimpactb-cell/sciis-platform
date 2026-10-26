<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reporting\BulkMembershipsRequest;
use App\Http\Requests\Admin\Reporting\BulkOpportunitiesRequest;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Services\BulkActions;
use App\Services\Reporting\ReportDatasets;
use App\Services\Reporting\ReportFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * ADM-08 — bulk actions on the opportunities and memberships queues (max 100 records per request). Gated by the
 * queue's own permission + per-record policy; one audit row per record. Exports use the report datasets, so
 * sensitive personal fields (e-mail, phone, ids) are never included.
 */
class AdminBulkController extends Controller
{
    public function __construct(
        private readonly BulkActions $bulk,
        private readonly ReportDatasets $datasets,
        private readonly ReportFiles $files,
    ) {}

    /** POST /admin/bulk/opportunities/assign {uuids[], reviewer_user_id | auto: true} */
    public function assignOpportunities(BulkOpportunitiesRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Opportunity::class);
        $auto = $request->boolean('auto');

        return $this->ok($this->bulk->assignOpportunities($request->user(), $request->validated('uuids'), $auto ? null : (int) $request->validated('reviewer_user_id'), $auto));
    }

    /** POST /admin/bulk/opportunities/remind {uuids[]} */
    public function remindOpportunities(BulkOpportunitiesRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Opportunity::class);

        return $this->ok($this->bulk->remindOpportunities($request->user(), $request->validated('uuids')));
    }

    /** GET /admin/bulk/opportunities/export?uuids=a,b&format=xlsx|pdf */
    public function exportOpportunities(BulkOpportunitiesRequest $request): Response
    {
        $this->authorize('viewAny', Opportunity::class);
        $uuids = $request->validated('uuids');
        $records = Opportunity::query()->whereIn('uuid', $uuids)->whereNotNull('submitted_at')->get()->filter(fn (Opportunity $o): bool => $request->user()->can('view', $o));

        return $this->export('opportunities', $records->pluck('uuid')->all(), $records, 'opportunity.exported', (string) $request->validated('format', 'xlsx'), $request);
    }

    /** POST /admin/bulk/memberships/assign {ids[], reviewer_user_id} */
    public function assignMemberships(BulkMembershipsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Membership::class);

        return $this->ok($this->bulk->assignMemberships($request->user(), array_map('intval', $request->validated('ids')), (int) $request->validated('reviewer_user_id')));
    }

    /** POST /admin/bulk/memberships/remind {ids[]} */
    public function remindMemberships(BulkMembershipsRequest $request): JsonResponse
    {
        $this->authorize('viewAny', Membership::class);

        return $this->ok($this->bulk->remindMemberships($request->user(), array_map('intval', $request->validated('ids'))));
    }

    /** GET /admin/bulk/memberships/export?ids=1,2&format=xlsx|pdf */
    public function exportMemberships(BulkMembershipsRequest $request): Response
    {
        $this->authorize('viewAny', Membership::class);
        $records = Membership::query()->whereIn('id', array_map('intval', $request->validated('ids')))->get();

        return $this->export('memberships', $records->modelKeys(), $records, 'membership.exported', (string) $request->validated('format', 'xlsx'), $request);
    }

    /**
     * @param  array<int, int|string>  $ids
     * @param  \Illuminate\Support\Collection<int, \Illuminate\Database\Eloquent\Model>  $records
     */
    private function export(string $dataset, array $ids, $records, string $action, string $format, BulkOpportunitiesRequest|BulkMembershipsRequest $request): Response
    {
        $result = $this->datasets->run($request->user(), $dataset, ['filters' => ['ids' => $ids === [] ? ['0'] : array_map('strval', $ids)]]);
        $this->bulk->auditExport($action, $records, $request->user(), $format);

        $title = __('reporting.datasets.'.$dataset);
        $content = $this->files->datasetFile($format, $result, $title, [['label' => __('reporting.pdf.rows'), 'value' => (string) $result['total']]]);

        return response($content, 200, [
            'Content-Type' => ReportFiles::MIME[$format],
            'Content-Disposition' => sprintf('attachment; filename="%s-selection-%s.%s"', $dataset, now()->format('Ymd-His'), $format),
        ]);
    }
}
