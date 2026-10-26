<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Investor;

use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Investor\SavedFilterRequest;
use App\Models\Opportunity;
use App\Models\SavedFilter;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\OpportunityFilters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * OPP-08 — the investor's saved catalogue searches (name / apply / delete), OPP-07 — alert switches: `notify` per
 * search, plus the single "any new opportunity matching my profile" switch. Only the owner sees or changes them.
 */
class SavedFilterController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** GET /investor/saved-filters → {filters: [...], profile_alerts, max} */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = SavedFilter::query()->searches()->where('user_id', $user->id)->orderByDesc('id')->get();

        return $this->ok([
            'filters' => $filters->map(fn (SavedFilter $f): array => $this->row($f, $user))->values()->all(),
            'profile_alerts' => SavedFilter::query()->profileSwitch()->where('user_id', $user->id)->exists(),
            'max' => SavedFilter::MAX_PER_USER,
        ]);
    }

    /** POST /investor/saved-filters {name, filters, notify} */
    public function store(SavedFilterRequest $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->filters();
        if (! OpportunityFilters::hasCriteria($filters)) {
            throw new DomainException(__('messages.discovery.filter_criteria_required'), 'filter_criteria_required', 422, ['filters' => [__('messages.discovery.filter_criteria_required')]]);
        }
        if (SavedFilter::query()->searches()->where('user_id', $user->id)->count() >= SavedFilter::MAX_PER_USER) {
            throw new DomainException(__('messages.discovery.filters_limit', ['max' => SavedFilter::MAX_PER_USER]), 'saved_filters_limit', 422, ['name' => [__('messages.discovery.filters_limit', ['max' => SavedFilter::MAX_PER_USER])]]);
        }

        $filter = SavedFilter::query()->create([
            'user_id' => $user->id,
            'kind' => SavedFilter::KIND_FILTER,
            'name' => trim((string) $request->validated('name')),
            'filters' => $filters,
            'notify' => (bool) $request->validated('notify', false),
        ]);
        $this->audit->log('saved_filter.created', $filter, null, ['name' => $filter->name, 'notify' => $filter->notify], $user);

        return response()->json(['data' => $this->row($filter, $user), 'message' => __('messages.discovery.filter_saved')], 201);
    }

    /** PATCH /investor/saved-filters/{id} {name?, notify?} */
    public function update(SavedFilterRequest $request, SavedFilter $savedFilter): JsonResponse
    {
        $this->ensureOwned($request->user(), $savedFilter);
        $before = $savedFilter->only(['name', 'notify']);
        $data = $request->safe()->only(['name', 'notify']);
        if (isset($data['name'])) {
            $data['name'] = trim((string) $data['name']);
        }
        $savedFilter->fill($data)->save();
        $this->audit->log('saved_filter.updated', $savedFilter, $before, $savedFilter->only(['name', 'notify']), $request->user());

        return $this->ok($this->row($savedFilter, $request->user()));
    }

    /** DELETE /investor/saved-filters/{id} */
    public function destroy(Request $request, SavedFilter $savedFilter): JsonResponse
    {
        $this->ensureOwned($request->user(), $savedFilter);
        $this->audit->log('saved_filter.deleted', $savedFilter, ['name' => $savedFilter->name], null, $request->user());
        $savedFilter->delete();

        return $this->message(__('messages.discovery.filter_deleted'));
    }

    /** PUT /investor/alerts/profile {enabled} — OPP-07 "any new opportunity matching my profile". */
    public function profileAlerts(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $user = $request->user();
        $enabled = (bool) $data['enabled'];

        if ($enabled) {
            SavedFilter::query()->firstOrCreate(
                ['user_id' => $user->id, 'kind' => SavedFilter::KIND_PROFILE],
                ['name' => 'profile', 'filters' => [], 'notify' => true],
            );
        } else {
            SavedFilter::query()->profileSwitch()->where('user_id', $user->id)->delete();
        }
        $this->audit->log('saved_filter.profile_alerts', $user, null, ['enabled' => $enabled], $user);

        return response()->json([
            'data' => ['profile_alerts' => $enabled],
            'message' => __($enabled ? 'messages.discovery.profile_alerts_on' : 'messages.discovery.profile_alerts_off'),
        ]);
    }

    /** @return array<string, mixed> */
    private function row(SavedFilter $filter, User $user): array
    {
        $filters = (array) $filter->filters;

        return [
            'id' => $filter->id,
            'name' => $filter->name,
            'filters' => (object) $filters,
            'notify' => (bool) $filter->notify,
            // How many opportunities the search finds right now (same visibility as the catalogue).
            'count' => OpportunityFilters::apply(Opportunity::query()->visibleTo($user), $filters, $user)->count(),
            'created_at' => $filter->created_at?->toIso8601String(),
            'updated_at' => $filter->updated_at?->toIso8601String(),
        ];
    }

    /** Someone else's (or the profile switch row) → 404, never revealing it exists. */
    private function ensureOwned(User $user, SavedFilter $filter): void
    {
        abort_unless((int) $filter->user_id === (int) $user->id && $filter->kind === SavedFilter::KIND_FILTER, 404);
    }
}
