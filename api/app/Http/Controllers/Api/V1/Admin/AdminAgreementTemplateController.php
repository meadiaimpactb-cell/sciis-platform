<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agreements\AgreementTemplateRequest;
use App\Http\Resources\AgreementTemplateResource;
use App\Models\AgreementTemplate;
use App\Services\Agreements\AgreementPlaceholders;
use App\Services\Agreements\AgreementTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** AGR-01 — agreement templates: list (one family per key with its versions), create, new version, activate / deactivate. */
class AdminAgreementTemplateController extends Controller
{
    public function __construct(private readonly AgreementTemplateService $templates) {}

    /** GET /admin/agreement-templates[?active=1] */
    public function index(Request $request): JsonResponse
    {
        $this->assertCan($request);

        $rows = AgreementTemplate::query()->with('creator')
            ->when($request->boolean('active'), fn ($q) => $q->active())
            ->orderBy('key')->orderByDesc('version')->get();

        $families = $rows->groupBy('key')->map(function ($versions) use ($request): array {
            $latest = $versions->first();
            $active = $versions->firstWhere('is_active', true);

            return [
                'key' => $latest->key,
                'latest' => (new AgreementTemplateResource($latest))->resolve($request),
                'active' => $active ? (new AgreementTemplateResource($active))->resolve($request) : null,
                'versions' => $versions->map(fn (AgreementTemplate $t): array => [
                    'id' => $t->id, 'version' => $t->version, 'title' => $t->title, 'is_active' => $t->is_active,
                    'change_note' => $t->change_note, 'created_by' => $t->creator?->name, 'created_at' => $t->created_at?->toIso8601String(),
                ])->values()->all(),
            ];
        })->values()->all();

        return $this->ok([
            'templates' => $families,
            'placeholders' => array_map(fn (string $key): array => ['key' => $key, 'label' => __("agreements.placeholders.{$key}")], AgreementPlaceholders::AUTO),
        ]);
    }

    /** GET /admin/agreement-templates/{id} — one version with both texts. */
    public function show(Request $request, AgreementTemplate $template): JsonResponse
    {
        $this->assertCan($request);

        return $this->ok((new AgreementTemplateResource($template->load('creator')))->resolve($request));
    }

    /** POST /admin/agreement-templates */
    public function store(AgreementTemplateRequest $request): JsonResponse
    {
        $this->assertCan($request);
        $template = $this->templates->create($request->validated(), $request->user());

        return $this->ok((new AgreementTemplateResource($template->load('creator')))->resolve($request), 201);
    }

    /** POST /admin/agreement-templates/{id}/versions — editing always writes a new (inactive) version. */
    public function revise(AgreementTemplateRequest $request, AgreementTemplate $template): JsonResponse
    {
        $this->assertCan($request);
        $version = $this->templates->revise($template, $request->validated(), $request->user());

        return $this->ok((new AgreementTemplateResource($version->load('creator')))->resolve($request), 201);
    }

    /** POST /admin/agreement-templates/{id}/activate */
    public function activate(Request $request, AgreementTemplate $template): JsonResponse
    {
        $this->assertCan($request);

        return $this->ok((new AgreementTemplateResource($this->templates->activate($template, $request->user())->load('creator')))->resolve($request));
    }

    /** POST /admin/agreement-templates/{id}/deactivate */
    public function deactivate(Request $request, AgreementTemplate $template): JsonResponse
    {
        $this->assertCan($request);

        return $this->ok((new AgreementTemplateResource($this->templates->deactivate($template, $request->user())->load('creator')))->resolve($request));
    }

    private function assertCan(Request $request): void
    {
        if (! $request->user()->can('agreements.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }
    }
}
