<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\DomainException;
use App\Http\Controllers\Api\V1\Admin\Concerns\ChecksReportAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reporting\ReportDefinitionRequest;
use App\Http\Requests\Admin\Reporting\SavedReportRequest;
use App\Http\Requests\Admin\Reporting\ScheduleReportRequest;
use App\Models\SavedReport;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Reporting\ReportDatasets;
use App\Services\Reporting\ReportFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * RPT-04 custom report builder (catalog → preview → export → save) and RPT-05 schedules. Every staff member with
 * `reports.view` builds and previews; downloads and schedules follow the export rule (ChecksReportAccess).
 * Saved reports are shared with every `reports.view` holder; only the author (or `settings.manage`) changes them.
 */
class AdminReportBuilderController extends Controller
{
    use ChecksReportAccess;

    private const PREVIEW_ROWS = 100;

    public function __construct(
        private readonly ReportDatasets $datasets,
        private readonly ReportFiles $files,
        private readonly AuditLogger $audit,
    ) {}

    /** GET /admin/report-builder — datasets, columns (with what this user may pick), filters, grouping. */
    public function catalog(Request $request): JsonResponse
    {
        $this->assertCan($request, 'reports.view');

        return $this->ok([
            'datasets' => $this->datasets->catalog($request->user()),
            'can_export' => $this->canExport($request),
            'max_export_rows' => $this->datasets->maxExportRows(),
            'preview_rows' => self::PREVIEW_ROWS,
        ]);
    }

    /** POST /admin/report-builder/preview */
    public function preview(ReportDefinitionRequest $request): JsonResponse
    {
        $this->assertCan($request, 'reports.view');

        return $this->ok($this->datasets->run($request->user(), (string) $request->validated('dataset'), $request->definition(), self::PREVIEW_ROWS));
    }

    /** GET /admin/report-builder/export?format=xlsx|pdf&dataset=…&columns[]=…&filters[…]=… */
    public function export(ReportDefinitionRequest $request): Response
    {
        $this->assertCan($request, 'reports.view');
        $this->assertCanExport($request);

        $dataset = (string) $request->validated('dataset');

        return $this->download($request->user(), $dataset, $request->definition(), (string) $request->validated('format', 'xlsx'), __('reporting.datasets.'.$dataset), null);
    }

    /** GET /admin/saved-reports */
    public function index(Request $request): JsonResponse
    {
        $this->assertCan($request, 'reports.view');
        $user = $request->user();

        $list = SavedReport::query()->with('creator')->latest('updated_at')->get();
        $names = User::query()->whereIn('id', $list->pluck('schedule_recipients')->flatten()->filter()->unique()->all())->pluck('name', 'id');

        return $this->ok(['items' => $list->map(fn (SavedReport $r): array => $this->savedArray($r, $user, $names->all()))->all()]);
    }

    /** POST /admin/saved-reports */
    public function store(SavedReportRequest $request): JsonResponse
    {
        $this->assertCan($request, 'reports.view');
        $definition = $this->normalisedDefinition($request->user(), (string) $request->validated('dataset'), (array) $request->validated('definition'));

        $report = SavedReport::query()->create([
            'name' => $request->validated('name'),
            'dataset' => $request->validated('dataset'),
            'definition' => $definition,
            'created_by' => $request->user()->id,
        ]);
        $this->audit->log('saved_report.created', $report, null, ['name' => $report->name, 'dataset' => $report->dataset], $request->user());

        return response()->json(['data' => $this->savedArray($report->load('creator'), $request->user())], 201);
    }

    /** PATCH /admin/saved-reports/{uuid} */
    public function update(SavedReportRequest $request, SavedReport $savedReport): JsonResponse
    {
        $this->assertCan($request, 'reports.view');
        $this->assertOwner($request, $savedReport);

        $dataset = (string) ($request->validated('dataset') ?? $savedReport->dataset);
        $before = $savedReport->only(['name', 'dataset', 'definition']);
        $savedReport->fill(array_filter([
            'name' => $request->validated('name'),
            'dataset' => $dataset,
            'definition' => $request->has('definition') ? $this->normalisedDefinition($request->user(), $dataset, (array) $request->validated('definition')) : null,
        ], fn ($v): bool => $v !== null))->save();
        $this->audit->log('saved_report.updated', $savedReport, $before, $savedReport->only(['name', 'dataset', 'definition']), $request->user());

        return $this->ok($this->savedArray($savedReport->load('creator'), $request->user()));
    }

    /** DELETE /admin/saved-reports/{uuid} */
    public function destroy(Request $request, SavedReport $savedReport): JsonResponse
    {
        $this->assertCan($request, 'reports.view');
        $this->assertOwner($request, $savedReport);

        $this->audit->log('saved_report.deleted', $savedReport, $savedReport->only(['name', 'dataset', 'schedule_frequency']), null, $request->user());
        $savedReport->delete();

        return response()->json(null, 204);
    }

    /** GET /admin/saved-reports/{uuid}/run — preview with the stored definition. */
    public function run(Request $request, SavedReport $savedReport): JsonResponse
    {
        $this->assertCan($request, 'reports.view');

        return $this->ok($this->datasets->run($request->user(), $savedReport->dataset, (array) $savedReport->definition, self::PREVIEW_ROWS) + ['name' => $savedReport->name]);
    }

    /** GET /admin/saved-reports/{uuid}/export?format=xlsx|pdf */
    public function exportSaved(Request $request, SavedReport $savedReport): Response
    {
        $this->assertCan($request, 'reports.view');
        $this->assertCanExport($request);
        $format = $request->input('format') === 'pdf' ? 'pdf' : 'xlsx';

        return $this->download($request->user(), $savedReport->dataset, (array) $savedReport->definition, $format, $savedReport->name, $savedReport);
    }

    /** PUT /admin/saved-reports/{uuid}/schedule — RPT-05 (`frequency: null` turns the schedule off). */
    public function schedule(ScheduleReportRequest $request, SavedReport $savedReport): JsonResponse
    {
        $this->assertCan($request, 'reports.view');
        $this->assertOwner($request, $savedReport);
        // E-mailing a file is an export: same rule as a download.
        $this->assertCanExport($request);

        $frequency = $request->validated('frequency');
        $recipients = array_values(array_unique(array_map('intval', (array) $request->validated('recipients', []))));
        if ($frequency !== null) {
            $valid = User::query()->staff()->active()->permission('reports.view')->whereIn('id', $recipients)->count();
            if ($valid !== count($recipients)) {
                throw new DomainException(__('reporting.errors.recipients'), 'report_recipients_invalid', 422, ['recipients' => [__('reporting.errors.recipients')]]);
            }
        }

        $before = $savedReport->only(['schedule_frequency', 'schedule_format', 'schedule_recipients']);
        $savedReport->fill([
            'schedule_frequency' => $frequency,
            'schedule_format' => $frequency !== null ? $request->validated('format') : $savedReport->schedule_format,
            'schedule_recipients' => $frequency !== null ? $recipients : null,
            'next_run_at' => $frequency !== null ? SavedReport::nextRunAfter($frequency) : null,
        ])->save();
        $this->audit->log($frequency !== null ? 'saved_report.scheduled' : 'saved_report.unscheduled', $savedReport, $before, $savedReport->only(['schedule_frequency', 'schedule_format', 'schedule_recipients']), $request->user());

        return $this->ok($this->savedArray($savedReport->load('creator'), $request->user()));
    }

    /** GET /admin/report-recipients — staff who may receive scheduled reports (`reports.view`). */
    public function recipients(Request $request): JsonResponse
    {
        $this->assertCan($request, 'reports.view');

        $users = User::query()->staff()->active()->permission('reports.view')->orderBy('name')->get(['id', 'name']);

        return $this->ok(['items' => $users->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all()]);
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed> $definition */
    private function download(User $user, string $dataset, array $definition, string $format, string $title, ?SavedReport $saved): Response
    {
        $result = $this->datasets->run($user, $dataset, $definition);
        $this->audit->log('report.export', $saved, null, [
            'dataset' => $dataset, 'format' => $format, 'rows' => count($result['rows']), 'total' => $result['total'],
            'truncated' => $result['truncated'], 'columns' => array_column($result['columns'], 'key'), 'filters' => $definition['filters'] ?? [], 'group_by' => $definition['group_by'] ?? null,
        ], $user);

        $meta = [
            ['label' => __('reporting.pdf.dataset'), 'value' => __('reporting.datasets.'.$dataset)],
            ['label' => __('reporting.pdf.rows'), 'value' => (string) $result['total']],
        ];
        if (filled($definition['group_by'] ?? null)) {
            $meta[] = ['label' => __('reporting.pdf.grouped_by'), 'value' => $this->datasets->columnLabel((string) $definition['group_by'])];
        }

        $content = $this->files->datasetFile($format, $result, $title, $meta);
        $slug = $saved?->uuid ? 'report-'.substr($saved->uuid, 0, 8) : $dataset;

        return response($content, 200, [
            'Content-Type' => ReportFiles::MIME[$format],
            'Content-Disposition' => sprintf('attachment; filename="%s-%s.%s"', $slug, now()->format('Ymd-His'), $format),
        ]);
    }

    /**
     * Validates the definition by running it once (unknown columns / grouping → 422) and keeps only known keys.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function normalisedDefinition(User $user, string $dataset, array $definition): array
    {
        $clean = [
            'columns' => array_values(array_map('strval', (array) ($definition['columns'] ?? []))),
            'filters' => array_filter((array) ($definition['filters'] ?? []), fn ($v): bool => $v !== null && $v !== '' && $v !== []),
            'group_by' => filled($definition['group_by'] ?? null) ? (string) $definition['group_by'] : null,
            'sort' => filled($definition['sort'] ?? null) ? (string) $definition['sort'] : null,
            'dir' => in_array($definition['dir'] ?? null, ['asc', 'desc'], true) ? $definition['dir'] : null,
        ];
        $this->datasets->run($user, $dataset, $clean, 1);

        return $clean;
    }

    private function assertOwner(Request $request, SavedReport $report): void
    {
        $user = $request->user();
        if ((int) $report->created_by !== (int) $user->id && ! $user->can('settings.manage')) {
            throw new DomainException(__('reporting.errors.saved_forbidden'), 'saved_report_forbidden', 403);
        }
    }

    /**
     * @param  array<int, string>  $names  recipient id => name
     * @return array<string, mixed>
     */
    private function savedArray(SavedReport $r, User $user, array $names = []): array
    {
        $recipients = array_map('intval', (array) ($r->schedule_recipients ?? []));
        if ($names === [] && $recipients !== []) {
            $names = User::query()->whereIn('id', $recipients)->pluck('name', 'id')->all();
        }

        return [
            'uuid' => $r->uuid,
            'name' => $r->name,
            'dataset' => $r->dataset,
            'dataset_label' => __('reporting.datasets.'.$r->dataset),
            'definition' => $r->definition,
            'created_by' => $r->creator ? ['id' => $r->creator->id, 'name' => $r->creator->name] : null,
            'can_edit' => (int) $r->created_by === (int) $user->id || $user->can('settings.manage'),
            'schedule' => [
                'frequency' => $r->schedule_frequency,
                'format' => $r->schedule_format,
                'recipients' => array_map(fn (int $id): array => ['id' => $id, 'name' => $names[$id] ?? null], $recipients),
                'next_run_at' => $r->next_run_at?->toIso8601String(),
                'last_sent_at' => $r->last_sent_at?->toIso8601String(),
            ],
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }
}
