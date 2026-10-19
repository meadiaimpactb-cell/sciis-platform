<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Investor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Investor\SubmitMembershipRequest;
use App\Http\Requests\Investor\UpdateProfileRequest;
use App\Http\Resources\InvestorProfileResource;
use App\Http\Resources\MembershipResource;
use App\Models\InvestorProfile;
use App\Services\AuditLogger;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** INV-01 (profile sections + completion %), INV-02 (membership submission). */
class InvestorProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly MembershipService $memberships) {}

    /** GET /investor/profile */
    public function show(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        return $this->ok((new InvestorProfileResource($profile))->resolve($request));
    }

    /** PUT /investor/profile — sections: basics, interests, experience, declarations. */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $profile = $this->profile($request);
        $data = $request->validated();

        DB::transaction(function () use ($profile, $data): void {
            $before = $profile->only(InvestorProfile::COMPLETION_FIELDS);

            $profile->fill(array_merge(
                (array) ($data['basics'] ?? []),
                collect((array) ($data['interests'] ?? []))->except(['sector_ids', 'stage_ids', 'investment_type_ids', 'region_ids', 'cause_ids'])->all(),
                (array) ($data['experience'] ?? []),
                (array) ($data['declarations'] ?? []),
            ));
            $profile->save();

            $interests = (array) ($data['interests'] ?? []);
            foreach (['sector_ids' => 'sectors', 'stage_ids' => 'stages', 'investment_type_ids' => 'investmentTypes', 'region_ids' => 'regions', 'cause_ids' => 'causes'] as $key => $relation) {
                if (array_key_exists($key, $interests)) {
                    $profile->{$relation}()->sync((array) $interests[$key]);
                }
            }

            $profile->completion_percent = $profile->computeCompletion();
            $profile->save();

            $this->audit->log('investor_profile.updated', $profile, $before, $profile->only(InvestorProfile::COMPLETION_FIELDS), $profile->user);
        });

        return $this->ok((new InvestorProfileResource($profile->refresh()))->resolve($request));
    }

    /** POST /investor/membership/submit — INV-02 */
    public function submitMembership(SubmitMembershipRequest $request): JsonResponse
    {
        $membership = $this->memberships->submit($request->user(), (bool) $request->validated('accept_terms'));

        return response()->json(['data' => (new MembershipResource($membership))->resolve($request), 'message' => __('messages.membership.submitted')], 201);
    }

    /** GET /investor/membership */
    public function membership(Request $request): JsonResponse
    {
        return $this->ok((new MembershipResource($this->memberships->ensure($request->user())))->resolve($request));
    }

    private function profile(Request $request): InvestorProfile
    {
        return InvestorProfile::query()->firstOrCreate(['user_id' => $request->user()->id]);
    }
}
