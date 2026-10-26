<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\ChecksReportAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reporting\GeneratePeriodicReportRequest;
use App\Http\Requests\Admin\Reporting\PeriodicDecisionRequest;
use App\Http\Requests\Admin\Reporting\UpdatePeriodicReportRequest;
use App\Models\PeriodicReport;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Reporting\PeriodicReportService;
use App\Services\Reporting\ReportFiles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * Journey 11.10 — periodic report approval before publishing.
 *  - `reports.view`: generate / regenerate a draft, edit it, see every status;
 *  - `reports.approve`: approve, return to draft, publish (audience staff | public), withdraw publication;
 *  - every staff account: read and download published reports (the "all staff" audience).
 */
class AdminPeriodicReportController extends Controller
{
    use ChecksReportAccess;

    public function __construct(
        private readonly PeriodicReportService $service,
        private readonly ReportFiles $files,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /admin/periodic-reports */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $list = PeriodicReport::query()->with(['generator', 'approver', 'publisher'])
            ->when(! $this->managesDrafts($user), fn (Builder $q) => $q->published())
            ->orderByDesc('period_start')->orderBy('period_type')
            ->get();

        return $this->ok([
            'items' => $list->map(fn (PeriodicReport $r): array => $this->toArray($r, $user, false))->all(),
            'can_generate' => $user->can('reports.view'),
            'can_approve' => $user->can('reports.approve'),
        ]);
    }

    /** GET /admin/periodic-reports/{uuid} */
    public function show(Request $request, PeriodicReport $periodicReport): JsonResponse
    {
        $this->assertVisible($request->user(), $periodicReport);

        return $this->ok($this->toArray($periodicReport->load(['generator', 'approver', 'publisher']), $request->user(), true));
    }

    /** POST /admin/periodic-reports — generate (or refresh) the draft of a period. */
    public function store(GeneratePeriodicReportRequest $request): JsonResponse
    {
        $this->assertCan($request, 'reports.view');
        $anchor = $request->validated('anchor_date') ? Carbon::parse($request->validated('anchor_date')) : now();

        $report = $this->service->generate((string) $request->validated('period_type'), $anchor, $request->user(), $request->validated('title'), $request->validated('summary'));

        return response()->json(['data' => $this->toArray($report->load(['generator']), $request->user(), true)], 201);
    }

    /** PATCH /admin/periodic-reports/{uuid} — draft title / summary / audience. */
    public function update(UpdatePeriodicReportRequest $request, PeriodicReport $periodicReport): JsonResponse
    {
        $this->assertCan($request, 'reports.view');
        $report = $this->service->update($periodicReport, $request->validated(), $request->user());

        return $this->ok($this->toArray($report->load(['generator', 'approver', 'publisher']), $request->user(), true));
    }

    /** POST /admin/periodic-reports/{uuid}/approve */
    public function approve(PeriodicDecisionRequest $request, PeriodicReport $periodicReport): JsonResponse
    {
        $this->assertCan($request, 'reports.approve');

        return $this->ok($this->toArray($this->service->approve($periodicReport, $request->user(), $request->validated('note'))->load(['generator', 'approver']), $request->user(), true));
    }

    /** POST /admin/periodic-reports/{uuid}/return — back to draft with a note. */
    public function returnToDraft(PeriodicDecisionRequest $request, PeriodicReport $periodicReport): JsonResponse
    {
        $this->assertCan($request, 'reports.approve');

        return $this->ok($this->toArray($this->service->returnToDraft($periodicReport, $request->user(), (string) $request->validated('note'))->load(['generator']), $request->user(), true));
    }

    /** POST /admin/periodic-reports/{uuid}/publish */
    public function publish(PeriodicDecisionRequest $request, PeriodicReport $periodicReport): JsonResponse
    {
        $this->assertCan($request, 'reports.approve');

        return $this->ok($this->toArray($this->service->publish($periodicReport, $request->user(), $request->validated('audience'))->load(['generator', 'approver', 'publisher']), $request->user(), true));
    }

    /** POST /admin/periodic-reports/{uuid}/unpublish */
    public function unpublish(Request $request, PeriodicReport $periodicReport): JsonResponse
    {
        $this->assertCan($request, 'reports.approve');

        return $this->ok($this->toArray($this->service->unpublish($periodicReport, $request->user())->load(['generator', 'approver']), $request->user(), true));
    }

    /** GET /admin/periodic-reports/{uuid}/export.pdf — published: any staff; drafts: `reports.view`. Audited. */
    public function pdf(Request $request, PeriodicReport $periodicReport): Response
    {
        $this->assertVisible($request->user(), $periodicReport);
        $this->audit->log('periodic_report.downloaded', $periodicReport, null, ['status' => $periodicReport->status], $request->user());

        return response($this->files->periodicPdf($periodicReport->load('approver')), 200, [
            'Content-Type' => ReportFiles::MIME['pdf'],
            'Content-Disposition' => sprintf('attachment; filename="periodic-%s-%s.pdf"', $periodicReport->period_type, $periodicReport->period_start->format('Y-m')),
        ]);
    }

    // ---------------------------------------------------------------- helpers

    private function managesDrafts(User $user): bool
    {
        return $user->can('reports.view') || $user->can('reports.approve');
    }

    private function assertVisible(User $user, PeriodicReport $report): void
    {
        if (! $report->isPublished() && ! $this->managesDrafts($user)) {
            abort(404);
        }
    }

    /** @return array<string, mixed> */
    private function toArray(PeriodicReport $r, User $user, bool $withSnapshot): array
    {
        $person = fn (?User $u): ?array => $u ? ['id' => $u->id, 'name' => $u->name] : null;

        return [
            'uuid' => $r->uuid,
            'period_type' => $r->period_type,
            'period_start' => $r->period_start->toDateString(),
            'period_end' => $r->period_end->toDateString(),
            'title' => $r->title,
            'summary' => $r->summary,
            'status' => $r->status,
            'audience' => $r->audience,
            'review_note' => $this->managesDrafts($user) ? $r->review_note : null,
            'generated_by' => $person($r->generator),
            'generated_at' => $r->generated_at?->toIso8601String(),
            'approved_by' => $person($r->approver),
            'approved_at' => $r->approved_at?->toIso8601String(),
            'published_by' => $person($r->publisher),
            'published_at' => $r->published_at?->toIso8601String(),
            'kpis' => $withSnapshot ? array_map(fn (array $k): array => array_intersect_key($k, array_flip(['key', 'title', 'kind', 'unit', 'series'])), (array) ($r->snapshot['kpis'] ?? [])) : null,
            'actions' => [
                'edit' => $r->status === PeriodicReport::DRAFT && $user->can('reports.view'),
                'approve' => $r->status === PeriodicReport::DRAFT && $user->can('reports.approve'),
                'return' => $r->status === PeriodicReport::APPROVED && $user->can('reports.approve'),
                'publish' => $r->status === PeriodicReport::APPROVED && $user->can('reports.approve'),
                'unpublish' => $r->status === PeriodicReport::PUBLISHED && $user->can('reports.approve'),
            ],
        ];
    }
}
