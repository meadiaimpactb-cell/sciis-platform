<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\TaxonomyTermResource;
use App\Models\TaxonomyTerm;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** FRM-04 — public read of taxonomies & public settings. */
class TaxonomyController extends Controller
{
    /** GET /taxonomies */
    public function index(Request $request): JsonResponse
    {
        $terms = TaxonomyTerm::query()->active()->ordered()->get()->groupBy('group');

        return $this->ok($terms->map(fn ($group) => TaxonomyTermResource::collection($group)->resolve($request))->all());
    }

    /** GET /taxonomies/{group} */
    public function show(Request $request, string $group): JsonResponse
    {
        if (! in_array($group, TaxonomyTerm::GROUPS, true)) {
            throw new NotFoundHttpException;
        }

        return response()->json(['data' => TaxonomyTermResource::collection(TaxonomyTerm::query()->group($group)->active()->ordered()->get())->resolve($request)]);
    }

    /** GET /settings/public */
    public function publicSettings(SettingsService $settings): JsonResponse
    {
        return $this->ok($settings->publicPayload());
    }
}
