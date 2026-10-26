<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Investor;

use App\Http\Controllers\Controller;
use App\Http\Requests\Investor\SubmitMembershipRequest;
use App\Http\Requests\Investor\UpdateProfileRequest;
use App\Http\Resources\InvestorProfileResource;
use App\Http\Resources\MembershipResource;
use App\Models\InvestorProfile;
use App\Services\InvestorProfileService;
use App\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** INV-01 (profile sections + completion %), INV-02 (membership submission). */
class InvestorProfileController extends Controller
{
    public function __construct(private readonly InvestorProfileService $profiles, private readonly MembershipService $memberships) {}

    /** GET /investor/profile */
    public function show(Request $request): JsonResponse
    {
        $profile = $this->profile($request);

        return $this->ok((new InvestorProfileResource($profile))->resolve($request));
    }

    /** PUT /investor/profile — sections: basics, interests, experience, declarations. */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $profile = $this->profiles->apply($this->profile($request), $request->validated(), $request->user());

        return $this->ok((new InvestorProfileResource($profile))->resolve($request));
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
