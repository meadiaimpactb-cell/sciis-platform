<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InterestStatus;
use App\Enums\MembershipStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Models\Concerns\HasUuid;
use App\Support\PhoneNumbers;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $uuid
 * @property UserType $type
 * @property string $name
 * @property string $email
 * @property ?string $phone
 * @property string $locale
 * @property UserStatus $status
 * @property ?\Illuminate\Support\Carbon $email_verified_at
 * @property ?\Illuminate\Support\Carbon $phone_verified_at
 * @property ?\Illuminate\Support\Carbon $two_factor_confirmed_at
 * @property ?string $two_factor_secret
 * @property ?int $organization_id
 */
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuid, Notifiable, SoftDeletes;

    protected string $guard_name = 'api';

    /** spatie/laravel-permission guard resolution (SPEC §1.2: guard `api`). */
    public function guardName(): string
    {
        return 'api';
    }

    protected $fillable = [
        'type', 'name', 'email', 'phone', 'password', 'locale', 'status', 'preferred_contact', 'organization_id',
        'email_verified_at', 'phone_verified_at', 'last_login_at',
        'organization_can_act', 'organization_disabled_at', // ACC-08
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'national_id_hash',
    ];

    protected function casts(): array
    {
        return [
            'type' => UserType::class,
            'status' => UserStatus::class,
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'anonymized_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'password' => 'hashed',
            'preferences' => 'array', // UX-05 / BRD-04 — {calendar, theme}
            'national_id_verified_at' => 'datetime', // ACC-09
            'organization_can_act' => 'boolean', // ACC-08
            'organization_disabled_at' => 'datetime', // ACC-08
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** Standard verification layer: phones are always stored as E.164 (`05…` / `9665…` → `+9665…`) whatever the write path. */
    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (?string $value): ?string => $value === null || trim($value) === '' ? null : (string) PhoneNumbers::normalize($value));
    }

    /** Admin endpoints reference users by numeric id (SPEC §6 `/admin/users/{id}`). */
    public function getRouteKeyName(): string
    {
        return 'id';
    }

    // ---------------------------------------------------------------- relations

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function investorProfile(): HasOne
    {
        return $this->hasOne(InvestorProfile::class);
    }

    public function membership(): HasOne
    {
        return $this->hasOne(Membership::class);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'owner_user_id');
    }

    public function interests(): HasMany
    {
        return $this->hasMany(Interest::class, 'investor_user_id');
    }

    public function nominations(): HasMany
    {
        return $this->hasMany(Nomination::class, 'investor_user_id');
    }

    public function savedOpportunities(): HasMany
    {
        return $this->hasMany(SavedOpportunity::class);
    }

    public function infoRequests(): HasMany
    {
        return $this->hasMany(InfoRequest::class, 'investor_user_id');
    }

    public function fileAccessGrants(): HasMany
    {
        return $this->hasMany(FileAccessGrant::class, 'investor_user_id');
    }

    public function evaluationAssignments(): HasMany
    {
        return $this->hasMany(EvaluationAssignment::class, 'evaluator_user_id');
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function otpCodes(): HasMany
    {
        return $this->hasMany(OtpCode::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    public function notificationPreferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function meetingParticipations(): HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    // ---------------------------------------------------------------- scopes

    public function scopeStaff(Builder $query): Builder
    {
        return $query->where('type', UserType::Staff->value);
    }

    public function scopeInvestors(Builder $query): Builder
    {
        return $query->whereIn('type', [UserType::InvestorIndividual->value, UserType::InvestorInstitutional->value]);
    }

    public function scopeEntrepreneurs(Builder $query): Builder
    {
        return $query->whereIn('type', [UserType::EntrepreneurIndividual->value, UserType::EntrepreneurEntity->value]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', UserStatus::Active->value);
    }

    // ---------------------------------------------------------------- helpers

    public function isStaff(): bool
    {
        return $this->type === UserType::Staff;
    }

    public function isInvestor(): bool
    {
        return in_array($this->type, [UserType::InvestorIndividual, UserType::InvestorInstitutional], true);
    }

    public function isEntrepreneur(): bool
    {
        return in_array($this->type, [UserType::EntrepreneurIndividual, UserType::EntrepreneurEntity], true);
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /** Email + phone verified (ACC-01 "registered"). Staff: email verified (phone optional; TOTP is enforced instead — ACC-05). */
    public function isFullyVerified(): bool
    {
        if ($this->email_verified_at === null) {
            return false;
        }

        return $this->phone_verified_at !== null || $this->isStaff() || ! config('sciis.verification.phone');
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** Membership approved + terms accepted (SPEC §1.3 approved_member). ACC-08: colleagues use the organisation's. */
    public function isApprovedMember(): bool
    {
        $membership = $this->effectiveMembership();

        return $membership !== null
            && $membership->status === MembershipStatus::Approved
            && $membership->terms_accepted_at !== null;
    }

    public function hasConnectedInterest(Opportunity $opportunity): bool
    {
        return $this->interests()
            ->where('opportunity_id', $opportunity->id)
            ->whereIn('status', [InterestStatus::Connected->value, InterestStatus::Meeting->value])
            ->exists();
    }

    public function routeNotificationForSms(): ?string
    {
        return $this->phone;
    }

    /** NTF-04 — WhatsApp goes to the account phone (E.164). */
    public function routeNotificationForWhatsapp(): ?string
    {
        return $this->phone;
    }

    // ---------------------------------------------------------------- ACC-08 organisation accounts

    public function isOrganizationRepresentative(): bool
    {
        return $this->organization_id !== null && $this->organization?->representative_user_id === $this->id;
    }

    /** A colleague invited into an organisation account (not its representative). */
    public function isOrganizationColleague(): bool
    {
        return $this->organization_id !== null && ! $this->isOrganizationRepresentative();
    }

    /** Individuals and representatives always act; colleagues only when the representative granted "can act". */
    public function canActForOrganization(): bool
    {
        return ! $this->isOrganizationColleague() || (bool) $this->organization_can_act;
    }

    /** Ids of the active users of the same organisation account (the user alone otherwise). @return list<int> */
    public function organizationPeerIds(): array
    {
        if ($this->organization_id === null) {
            return [$this->id];
        }

        $ids = self::query()->where('organization_id', $this->organization_id)->where('type', $this->type->value)
            ->whereNull('organization_disabled_at')->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return array_values(array_unique([$this->id, ...$ids]));
    }

    /**
     * The membership that governs this account: the user's own, or for an organisation colleague the organisation's
     * (representative's first, then any colleague holding one — e.g. after the representative role was transferred).
     */
    public function effectiveMembership(): ?Membership
    {
        if ($this->organization_id === null || ! $this->isInvestor()) {
            return $this->relationLoaded('membership') ? $this->membership : $this->membership()->first();
        }

        $representativeId = (int) $this->organization?->representative_user_id;

        return Membership::query()->whereIn('user_id', $this->organizationPeerIds())
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$representativeId])
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [MembershipStatus::Approved->value])
            ->orderBy('id')
            ->first();
    }

    public function preferredLocale(): string
    {
        return $this->locale ?: 'ar';
    }
}
