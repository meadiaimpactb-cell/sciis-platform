<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TaxonomyTermRequest;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Http\Resources\TaxonomyTermResource;
use App\Models\Setting;
use App\Models\TaxonomyTerm;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** ADM-03 (settings) + FRM-04 (taxonomies) — super_admin / settings.manage. */
class AdminSettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings, private readonly AuditLogger $audit) {}

    /** GET /admin/settings */
    public function index(Request $request): JsonResponse
    {
        $this->assertCan($request);

        $rows = Setting::query()->orderBy('group')->orderBy('key')->get()->map(fn (Setting $s): array => [
            'key' => $s->key, 'value' => $s->value, 'group' => $s->group, 'description' => $s->description, 'is_public' => $s->is_public, 'updated_at' => $s->updated_at?->toIso8601String(),
        ]);

        return $this->ok(['settings' => $rows->all(), 'features' => config('sciis.features')]);
    }

    /** PUT /admin/settings */
    public function update(UpdateSettingsRequest $request): JsonResponse
    {
        $this->assertCan($request);
        $this->settings->setMany($request->validated('settings'), $request->user());

        return $this->index($request);
    }

    /** GET /admin/taxonomies/{group} */
    public function taxonomy(Request $request, string $group): JsonResponse
    {
        $this->assertGroup($group);

        return response()->json(['data' => TaxonomyTermResource::collection(TaxonomyTerm::query()->group($group)->ordered()->get())->resolve($request)]);
    }

    /** POST /admin/taxonomies/{group} */
    public function storeTerm(TaxonomyTermRequest $request, string $group): JsonResponse
    {
        $this->assertCan($request);
        $this->assertGroup($group);

        $term = TaxonomyTerm::query()->create($request->validated() + ['group' => $group]);
        $this->audit->log('taxonomy.created', $term, null, $term->toArray(), $request->user());

        return response()->json(['data' => (new TaxonomyTermResource($term))->resolve($request)], 201);
    }

    /** PATCH /admin/taxonomies/{group}/{term} */
    public function updateTerm(TaxonomyTermRequest $request, string $group, TaxonomyTerm $term): JsonResponse
    {
        $this->assertCan($request);
        $this->assertGroup($group);
        if ($term->group !== $group) {
            throw new NotFoundHttpException;
        }

        $before = $term->toArray();
        $term->fill($request->validated())->save();
        $this->audit->log('taxonomy.updated', $term, $before, $term->getChanges(), $request->user());

        return $this->ok((new TaxonomyTermResource($term))->resolve($request));
    }

    /** DELETE /admin/taxonomies/{group}/{term} — soft: deactivates (terms are referenced by FK). */
    public function destroyTerm(Request $request, string $group, TaxonomyTerm $term): JsonResponse
    {
        $this->assertCan($request);
        $this->assertGroup($group);
        if ($term->group !== $group) {
            throw new NotFoundHttpException;
        }

        $term->is_active = false;
        $term->save();
        $this->audit->log('taxonomy.deactivated', $term, ['is_active' => true], ['is_active' => false], $request->user());

        return $this->message(__('messages.deleted'));
    }

    private function assertCan(Request $request): void
    {
        if (! $request->user()->can('settings.manage')) {
            abort(403, __('messages.errors.forbidden'));
        }
    }

    private function assertGroup(string $group): void
    {
        if (! in_array($group, TaxonomyTerm::GROUPS, true)) {
            throw new NotFoundHttpException;
        }
    }
}
