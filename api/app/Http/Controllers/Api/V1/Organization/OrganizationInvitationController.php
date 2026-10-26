<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\AcceptInvitationRequest;
use App\Http\Resources\UserResource;
use App\Services\OrganizationTeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/** ACC-08 — public side of an invitation link: read it (prefill) and register into the organisation. */
class OrganizationInvitationController extends Controller
{
    public function __construct(private readonly OrganizationTeamService $team) {}

    /** GET /auth/invitations/{token} */
    public function show(string $token): JsonResponse
    {
        $invitation = $this->team->findByToken($token);
        $type = $this->team->memberType($invitation->organization);

        return $this->ok([
            'email' => $invitation->email,
            'name' => $invitation->name,
            'organization' => ['name' => $invitation->organization->name],
            'type' => $type->value,
            'type_label' => $type->label(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    /** POST /auth/invitations/{token}/accept — same session behaviour as POST /auth/register. */
    public function accept(AcceptInvitationRequest $request, string $token): JsonResponse
    {
        $user = $this->team->accept($token, $request->validated(), $request);

        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }
        $request->setUserResolver(fn () => $user);

        return response()->json([
            'data' => (new UserResource($user->load(['membership', 'investorProfile', 'organization'])))->resolve($request),
            'message' => __('messages.organization.joined', ['organization' => $user->organization?->name]),
        ], 201);
    }
}
