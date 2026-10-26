<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConsentType;
use App\Enums\OtpChannel;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\DomainException;
use App\Models\Consent;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * ACC-08 — multi-user organisation accounts (institutional investors, entrepreneur entities): the representative
 * invites colleagues by e-mail, lists and disables them, grants "can act" and hands the representative role over.
 * Colleagues share the organisation's membership and records (read-only unless "can act"). Everything is audited.
 */
class OrganizationTeamService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OtpService $otp,
        private readonly SettingsService $settings,
    ) {}

    /** The caller's organisation (organisation account types only). */
    public function organizationOf(User $user): Organization
    {
        $organization = $user->organization_id !== null ? Organization::query()->find($user->organization_id) : null;
        if ($organization === null || ! in_array($user->type, [UserType::InvestorInstitutional, UserType::EntrepreneurEntity], true)) {
            throw new DomainException(__('messages.organization.required'), 'organization_required', 404);
        }

        return $organization;
    }

    public function assertRepresentative(User $user, Organization $organization): void
    {
        if ($organization->representative_user_id !== $user->id) {
            throw new DomainException(__('messages.organization.representative_only'), 'representative_only', 403);
        }
    }

    /** @return array<string, mixed> GET /organization/team */
    public function team(User $viewer): array
    {
        $organization = $this->organizationOf($viewer);
        $isRepresentative = $organization->representative_user_id === $viewer->id;

        $members = User::query()->where('organization_id', $organization->id)
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $organization->representative_user_id])
            ->orderBy('name')
            ->get();

        $invitations = $isRepresentative
            ? $organization->invitations()->open()->with('inviter')->latest('id')->get()->map(fn (OrganizationInvitation $i): array => $this->invitationArray($i))->all()
            : [];

        return [
            'organization' => ['uuid' => $organization->uuid, 'name' => $organization->name],
            'is_representative' => $isRepresentative,
            'can_act' => $isRepresentative || (bool) $viewer->organization_can_act,
            'members' => $members->map(fn (User $m): array => $this->memberArray($m, $organization, $viewer))->all(),
            'invitations' => $invitations,
        ];
    }

    /** @param array{email: string, name?: ?string, can_act?: bool} $data */
    public function invite(User $representative, array $data): OrganizationInvitation
    {
        $organization = $this->organizationOf($representative);
        $this->assertRepresentative($representative, $organization);

        $email = Str::lower(trim($data['email']));
        if (User::withTrashed()->where('email', $email)->exists()) {
            throw new DomainException(__('messages.organization.email_taken'), 'email_taken', 422, ['email' => [__('messages.organization.email_taken')]]);
        }
        $pending = $organization->invitations()->open()->where('email', $email)->where('expires_at', '>', now())->exists();
        if ($pending) {
            throw new DomainException(__('messages.organization.already_invited'), 'already_invited', 422, ['email' => [__('messages.organization.already_invited')]]);
        }
        $max = (int) config('sciis.organizations.max_members', 20);
        $count = User::query()->where('organization_id', $organization->id)->count()
            + $organization->invitations()->open()->where('expires_at', '>', now())->count();
        if ($count >= $max) {
            throw new DomainException(__('messages.organization.limit_reached', ['max' => $max]), 'organization_limit', 422);
        }

        [$invitation, $token] = DB::transaction(function () use ($organization, $representative, $email, $data): array {
            $token = Str::random(48);
            $invitation = OrganizationInvitation::query()->create([
                'organization_id' => $organization->id,
                'email' => $email,
                'name' => isset($data['name']) && trim((string) $data['name']) !== '' ? trim((string) $data['name']) : null,
                'can_act' => (bool) ($data['can_act'] ?? false),
                'token_hash' => OrganizationInvitation::hashToken($token),
                'invited_by' => $representative->id,
                'expires_at' => now()->addDays($this->invitationDays()),
            ]);
            $this->audit->log('organization.invitation_sent', $invitation, null, ['email' => $email, 'can_act' => $invitation->can_act, 'organization_id' => $organization->id], $representative);

            return [$invitation, $token];
        });

        $this->mail($invitation, $token, $representative);

        return $invitation->load('inviter');
    }

    /** New token + new expiry; the previous link stops working. */
    public function resend(User $representative, OrganizationInvitation $invitation): OrganizationInvitation
    {
        $this->assertOwnInvitation($representative, $invitation);
        if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
            throw new DomainException(__('messages.organization.invitation_closed'), 'invitation_closed', 422);
        }

        $token = Str::random(48);
        $invitation->forceFill(['token_hash' => OrganizationInvitation::hashToken($token), 'expires_at' => now()->addDays($this->invitationDays())])->save();
        $this->audit->log('organization.invitation_resent', $invitation, null, ['email' => $invitation->email, 'expires_at' => $invitation->expires_at->toIso8601String()], $representative);
        $this->mail($invitation, $token, $representative);

        return $invitation->load('inviter');
    }

    public function revoke(User $representative, OrganizationInvitation $invitation): void
    {
        $this->assertOwnInvitation($representative, $invitation);
        if ($invitation->accepted_at !== null) {
            throw new DomainException(__('messages.organization.invitation_closed'), 'invitation_closed', 422);
        }
        if ($invitation->revoked_at === null) {
            $invitation->forceFill(['revoked_at' => now()])->save();
            $this->audit->log('organization.invitation_revoked', $invitation, null, ['email' => $invitation->email], $representative);
        }
    }

    /** @param array{can_act?: bool, disabled?: bool} $data */
    public function updateMember(User $representative, User $member, array $data): User
    {
        $organization = $this->organizationOf($representative);
        $this->assertRepresentative($representative, $organization);
        $this->assertColleague($organization, $member);
        if ($member->id === $representative->id) {
            throw new DomainException(__('messages.organization.not_self'), 'not_self', 422);
        }

        $before = ['can_act' => (bool) $member->organization_can_act, 'disabled' => $member->organization_disabled_at !== null, 'status' => $member->status->value];

        DB::transaction(function () use ($member, $data, $representative, $before): void {
            if (array_key_exists('can_act', $data)) {
                $member->organization_can_act = (bool) $data['can_act'];
            }
            if (array_key_exists('disabled', $data)) {
                if ($data['disabled'] && $member->organization_disabled_at === null) {
                    // Disabled by the representative: no sign-in, sessions and tokens ended. A staff suspension
                    // (status suspended without organization_disabled_at) is never lifted from here.
                    $member->organization_disabled_at = now();
                    $member->status = UserStatus::Suspended;
                    $member->tokens()->delete();
                    DB::table('sessions')->where('user_id', $member->id)->delete();
                } elseif (! $data['disabled'] && $member->organization_disabled_at !== null) {
                    $member->organization_disabled_at = null;
                    $member->status = UserStatus::Active;
                }
            }
            $member->save();

            $after = ['can_act' => (bool) $member->organization_can_act, 'disabled' => $member->organization_disabled_at !== null, 'status' => $member->status->value];
            $action = match (true) {
                $before['disabled'] !== $after['disabled'] => $after['disabled'] ? 'organization.member_disabled' : 'organization.member_enabled',
                default => 'organization.member_updated',
            };
            $this->audit->log($action, $member, $before, $after, $representative);
        });

        return $member->refresh();
    }

    /** Hands the representative role to an active colleague; the former representative stays as an acting colleague. */
    public function transfer(User $representative, User $target): Organization
    {
        $organization = $this->organizationOf($representative);
        $this->assertRepresentative($representative, $organization);
        $this->assertColleague($organization, $target);
        if ($target->id === $representative->id) {
            throw new DomainException(__('messages.organization.not_self'), 'not_self', 422);
        }
        if (! $target->isActive() || $target->organization_disabled_at !== null || ! $target->isFullyVerified()) {
            throw new DomainException(__('messages.organization.transfer_inactive'), 'member_inactive', 422, ['user_id' => [__('messages.organization.transfer_inactive')]]);
        }

        DB::transaction(function () use ($organization, $representative, $target): void {
            $organization->forceFill(['representative_user_id' => $target->id])->save();
            $representative->forceFill(['organization_can_act' => true])->save();
            $target->forceFill(['organization_can_act' => true])->save();
            $this->audit->log('organization.representative_transferred', $organization, ['representative_user_id' => $representative->id], ['representative_user_id' => $target->id], $representative);
        });

        $representative->unsetRelation('organization');

        return $organization->refresh();
    }

    // ---------------------------------------------------------------- public invitation link

    /** The open invitation of a token (404 unknown / used / revoked, 410 expired). */
    public function findByToken(string $token): OrganizationInvitation
    {
        $invitation = OrganizationInvitation::query()->with('organization.representative')->where('token_hash', OrganizationInvitation::hashToken($token))->first();
        if ($invitation === null || $invitation->accepted_at !== null || $invitation->revoked_at !== null || $invitation->organization === null) {
            throw new DomainException(__('messages.organization.invitation_invalid'), 'invitation_invalid', 404);
        }
        if ($invitation->isExpired()) {
            throw new DomainException(__('messages.organization.invitation_expired'), 'invitation_expired', 410);
        }

        return $invitation;
    }

    /** The account type a colleague of this organisation gets (the representative's). */
    public function memberType(Organization $organization): UserType
    {
        $type = $organization->representative?->type;
        if (in_array($type, [UserType::InvestorInstitutional, UserType::EntrepreneurEntity], true)) {
            return $type;
        }

        return $organization->institution_type !== null ? UserType::InvestorInstitutional : UserType::EntrepreneurEntity;
    }

    /**
     * Creates the colleague's account from an invitation (e-mail verified by the link; phone OTP like registration).
     *
     * @param  array<string, mixed>  $data
     */
    public function accept(string $token, array $data, Request $request): User
    {
        $user = DB::transaction(function () use ($token, $data, $request): User {
            $invitation = $this->findByToken($token);
            OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->first();

            if (User::withTrashed()->where('email', $invitation->email)->exists()) {
                throw new DomainException(__('messages.organization.email_taken'), 'email_taken', 422, ['email' => [__('messages.organization.email_taken')]]);
            }

            $organization = $invitation->organization;
            $type = $this->memberType($organization);
            $user = User::query()->create([
                'type' => $type,
                'name' => trim((string) $data['name']),
                'email' => $invitation->email,
                'phone' => $data['phone'],
                'password' => $data['password'],
                'locale' => $data['locale'] ?? 'ar',
                'preferred_contact' => $data['preferred_contact'] ?? null,
                'organization_id' => $organization->id,
                'organization_can_act' => $invitation->can_act,
                'email_verified_at' => now(),
            ]);

            $version = (string) ($data['consents_version'] ?? $this->settings->get('legal.terms_version', '1.0'));
            $types = [ConsentType::PrivacyPolicy, ConsentType::Terms];
            if (in_array(ConsentType::DataAccuracy->value, (array) ($data['consents'] ?? []), true)) {
                $types[] = ConsentType::DataAccuracy;
            }
            foreach ($types as $consent) {
                Consent::query()->create([
                    'user_id' => $user->id,
                    'type' => $consent,
                    'version' => $version,
                    'text_hash' => hash('sha256', $consent->value.'|'.$version),
                    'accepted_at' => now(),
                    'ip' => $request->ip(),
                    'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                ]);
            }

            $invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
            $this->audit->log('organization.invitation_accepted', $invitation, null, ['user_id' => $user->id, 'organization_id' => $organization->id], $user);
            $this->audit->log('user.registered', $user, null, ['type' => $type->value, 'via' => 'organization_invitation', 'organization_id' => $organization->id], $user);

            return $user;
        });

        if (config('sciis.verification.phone')) {
            $this->otp->issue($user, OtpChannel::Sms);
        }

        return $user;
    }

    // ---------------------------------------------------------------- serialization

    /** @return array<string, mixed> */
    public function invitationArray(OrganizationInvitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'name' => $invitation->name,
            'can_act' => $invitation->can_act,
            'status' => $invitation->isExpired() ? 'expired' : 'pending',
            'expires_at' => $invitation->expires_at->toIso8601String(),
            'created_at' => $invitation->created_at?->toIso8601String(),
            'invited_by' => $invitation->inviter?->name,
        ];
    }

    /** @return array<string, mixed> */
    public function memberArray(User $member, Organization $organization, User $viewer): array
    {
        $isRepresentative = $organization->representative_user_id === $member->id;

        return [
            'id' => $member->id,
            'uuid' => $member->uuid,
            'name' => $member->name,
            'email' => $member->email,
            'is_representative' => $isRepresentative,
            'is_me' => $member->id === $viewer->id,
            'can_act' => $isRepresentative || (bool) $member->organization_can_act,
            'status' => $member->organization_disabled_at !== null || ! $member->isActive() ? 'disabled' : 'active',
            'last_login_at' => $member->last_login_at?->toIso8601String(),
            'joined_at' => $member->created_at?->toIso8601String(),
        ];
    }

    // ---------------------------------------------------------------- internals

    private function invitationDays(): int
    {
        return max(1, (int) config('sciis.organizations.invitation_days', 7));
    }

    private function assertOwnInvitation(User $representative, OrganizationInvitation $invitation): void
    {
        $organization = $this->organizationOf($representative);
        $this->assertRepresentative($representative, $organization);
        if ($invitation->organization_id !== $organization->id) {
            throw new DomainException(__('messages.errors.not_found'), 'not_found', 404);
        }
    }

    private function assertColleague(Organization $organization, User $member): void
    {
        if ($member->organization_id !== $organization->id) {
            throw new DomainException(__('messages.errors.not_found'), 'not_found', 404);
        }
    }

    private function mail(OrganizationInvitation $invitation, string $token, User $inviter): void
    {
        $organization = $invitation->organization ?? $invitation->organization()->first();
        $locale = $inviter->locale ?: 'ar';
        $url = rtrim((string) config('app.frontend_url'), '/').($locale === 'en' ? '/en' : '').'/auth/invitation?token='.$token;

        Notification::route('mail', [$invitation->email => $invitation->name ?: $invitation->email])
            ->notify((new OrganizationInvitationNotification($invitation, (string) $organization?->name, $inviter->name, $url))->locale($locale));
    }
}
