<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\InviteMemberRequest;
use App\Http\Requests\Organization\TransferRepresentativeRequest;
use App\Http\Requests\Organization\UpdateMemberRequest;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\OrganizationTeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** ACC-08 — organisation team: list (every colleague), invite / disable / "can act" / transfer (representative). */
class OrganizationTeamController extends Controller
{
    public function __construct(private readonly OrganizationTeamService $team) {}

    /** GET /organization/team */
    public function show(Request $request): JsonResponse
    {
        return $this->ok($this->team->team($request->user()));
    }

    /** POST /organization/invitations {email, name?, can_act?} */
    public function invite(InviteMemberRequest $request): JsonResponse
    {
        $invitation = $this->team->invite($request->user(), $request->validated());

        return response()->json(['data' => $this->team->invitationArray($invitation), 'message' => __('messages.organization.invited', ['email' => $invitation->email])], 201);
    }

    /** POST /organization/invitations/{invitation}/resend */
    public function resend(Request $request, OrganizationInvitation $invitation): JsonResponse
    {
        $invitation = $this->team->resend($request->user(), $invitation);

        return response()->json(['data' => $this->team->invitationArray($invitation), 'message' => __('messages.organization.invitation_resent')]);
    }

    /** DELETE /organization/invitations/{invitation} */
    public function revoke(Request $request, OrganizationInvitation $invitation): JsonResponse
    {
        $this->team->revoke($request->user(), $invitation);

        return $this->message(__('messages.organization.invitation_revoked'));
    }

    /** PATCH /organization/members/{member} {can_act?, disabled?} */
    public function updateMember(UpdateMemberRequest $request, User $member): JsonResponse
    {
        $member = $this->team->updateMember($request->user(), $member, $request->validated());
        $organization = $this->team->organizationOf($request->user());

        return $this->ok($this->team->memberArray($member, $organization, $request->user()));
    }

    /** POST /organization/representative {user_id} */
    public function transfer(TransferRepresentativeRequest $request): JsonResponse
    {
        $target = User::query()->findOrFail((int) $request->validated('user_id'));
        $this->team->transfer($request->user(), $target);
        $request->user()->unsetRelation('organization');

        return response()->json(['data' => $this->team->team($request->user()), 'message' => __('messages.organization.transferred', ['name' => $target->name])]);
    }
}
