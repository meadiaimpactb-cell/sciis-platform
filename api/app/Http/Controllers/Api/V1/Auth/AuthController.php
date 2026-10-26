<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\ConsentType;
use App\Enums\MembershipStatus;
use App\Enums\OpportunityStatus;
use App\Enums\OtpChannel;
use App\Enums\UserType;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\ResendOtpRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\VerifyChannelOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\Consent;
use App\Models\Membership;
use App\Models\Opportunity;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\InvestorProfileService;
use App\Services\OtpService;
use App\Services\SettingsService;
use App\Support\TwoFactorState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;

/**
 * ACC-01 (register + OTP), ACC-02 (login web cookie / mobile token), ACC-03 (password reset),
 * PRV-01/02 (consents at registration).
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly InvestorProfileService $profiles,
    ) {}

    /** POST /auth/register — ACC-01 (+ optional investor profile / first project from the web wizard). */
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
        $type = UserType::from($data['type']);

        $user = DB::transaction(function () use ($data, $type, $request): User {
            $user = User::query()->create([
                'type' => $type,
                'name' => $data['name'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'],
                'password' => $data['password'],
                'locale' => $data['locale'] ?? 'ar',
                'preferred_contact' => $data['preferred_contact'] ?? null,
            ]);

            if (in_array($type, [UserType::InvestorInstitutional, UserType::EntrepreneurEntity], true)) {
                $orgExtra = array_filter((array) ($data['organization'] ?? []), fn ($v): bool => $v !== null && $v !== '');
                $org = Organization::query()->create($orgExtra + ['name' => $data['organization_name'], 'representative_user_id' => $user->id]);
                $user->organization_id = $org->id;
                $user->save();
            }

            if ($user->isInvestor()) {
                $profile = $user->investorProfile()->create(['preferred_contact' => $data['preferred_contact'] ?? null]);
                Membership::query()->create(['user_id' => $user->id, 'status' => MembershipStatus::Registered]);

                // Same code path as PUT /investor/profile — nothing collected by the wizard is lost.
                if (! empty($data['profile'])) {
                    $this->profiles->apply($profile, (array) $data['profile'], $user);
                }
            }

            if ($user->isEntrepreneur() && ! empty($data['project']['name'])) {
                // Same creation rules as EntrepreneurOpportunityController@store (ENT-01): a draft with the basics.
                $opportunity = Opportunity::query()->create(array_filter((array) $data['project'], fn ($v): bool => $v !== null) + [
                    'owner_user_id' => $user->id,
                    'organization_id' => $user->organization_id,
                    'status' => OpportunityStatus::Draft,
                ]);
                $this->audit->log('opportunity.created', $opportunity, null, ['name' => $opportunity->name], $user);
            }

            $version = $data['consents_version'] ?? (string) $this->settings->get('legal.terms_version', '1.0');
            $types = [ConsentType::PrivacyPolicy, ConsentType::Terms];
            // INV-01 — the investor's data-accuracy declaration is kept like the other consents (time, IP, UA).
            if (in_array(ConsentType::DataAccuracy->value, (array) $data['consents'], true)) {
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

            $this->audit->log('user.registered', $user, null, ['type' => $type->value], $user);

            return $user;
        });

        $this->otp->issue($user, OtpChannel::Email);
        if (config('sciis.verification.phone')) {
            $this->otp->issue($user, OtpChannel::Sms);
        }

        if ($request->hasSession()) {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }
        $request->setUserResolver(fn () => $user);

        return response()->json([
            'data' => (new UserResource($user->load(['membership', 'investorProfile'])))->resolve($request),
            'message' => __('messages.auth.registered'),
        ], 201);
    }

    /**
     * POST /auth/otp/send {channel: email|phone} — standard contract.
     * → 200 {data: {channel, expires_in, resend_in}, message}. The code itself is never returned.
     */
    public function sendOtp(ResendOtpRequest $request): JsonResponse
    {
        $channel = $request->channel();
        $this->issueOtp($request->user(), $channel);

        return response()->json([
            'data' => [
                'channel' => $channel->publicName(),
                'expires_in' => (int) config('sciis.otp.ttl_minutes', 10) * 60,
                'resend_in' => (int) config('sciis.otp.resend_cooldown_seconds', 60),
            ],
            'message' => __('messages.auth.otp_sent'),
        ]);
    }

    /**
     * POST /auth/verify-otp {channel: email|phone, code} — standard contract.
     * → 200 {data: {channel, verified, email_verified, phone_verified, fully_verified}, message}.
     */
    public function verifyOtp(VerifyChannelOtpRequest $request): JsonResponse
    {
        $channel = $request->channel();
        $user = $request->user();
        $this->otp->verify($user, $channel, $request->validated('code'));

        return response()->json([
            'data' => [
                'channel' => $channel->publicName(),
                'verified' => true,
                'email_verified' => $user->email_verified_at !== null,
                'phone_verified' => $user->phone_verified_at !== null,
                'fully_verified' => $user->isFullyVerified(),
            ],
            'message' => __($channel === OtpChannel::Email ? 'messages.auth.email_verified' : 'messages.auth.phone_verified'),
        ]);
    }

    /** POST /auth/verify-email — legacy (same logic as /auth/verify-otp with channel=email). */
    public function verifyEmail(VerifyOtpRequest $request): JsonResponse
    {
        $this->otp->verify($request->user(), OtpChannel::Email, $request->validated('code'));

        return $this->message(__('messages.auth.email_verified'));
    }

    /** POST /auth/verify-phone — legacy (same logic as /auth/verify-otp with channel=phone). */
    public function verifyPhone(VerifyOtpRequest $request): JsonResponse
    {
        $this->otp->verify($request->user(), OtpChannel::Sms, $request->validated('code'));

        return $this->message(__('messages.auth.phone_verified'));
    }

    /** POST /auth/resend-otp {channel: email|sms|phone} — legacy (same logic as /auth/otp/send). */
    public function resendOtp(ResendOtpRequest $request): JsonResponse
    {
        $this->issueOtp($request->user(), $request->channel());

        return $this->message(__('messages.auth.otp_sent'));
    }

    private function issueOtp(User $user, OtpChannel $channel): void
    {
        if (($channel === OtpChannel::Email && $user->email_verified_at) || ($channel === OtpChannel::Sms && $user->phone_verified_at)) {
            throw new DomainException(__('messages.auth.already_verified'), 'already_verified', 422);
        }

        if ($channel === OtpChannel::Sms && blank($user->phone)) {
            throw new DomainException(__('messages.auth.phone_missing'), 'phone_missing', 422, ['channel' => [__('messages.auth.phone_missing')]]);
        }

        $this->otp->issue($user, $channel);
    }

    /** POST /auth/login — ACC-02: web → cookie session; mobile (device_name) → bearer token. */
    public function login(LoginRequest $request): JsonResponse
    {
        $key = 'login:'.strtolower((string) $request->input('email')).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new DomainException(__('messages.auth.too_many_attempts', ['seconds' => RateLimiter::availableIn($key)]), 'too_many_attempts', 429);
        }

        /** @var User|null $user */
        $user = User::query()->where('email', strtolower($request->validated('email')))->first();

        if ($user === null || ! Hash::check($request->validated('password'), $user->password)) {
            RateLimiter::hit($key, 300);
            $this->audit->log('auth.login_failed', $user, null, ['email' => $request->validated('email')], $user);
            throw new DomainException(__('messages.auth.invalid_credentials'), 'invalid_credentials', 422, ['email' => [__('messages.auth.invalid_credentials')]]);
        }

        if (! $user->isActive()) {
            throw new DomainException(__('messages.auth.account_suspended'), 'account_suspended', 403);
        }

        RateLimiter::clear($key);
        $user->last_login_at = now();
        $user->save();
        $this->audit->log('auth.login', $user, null, ['device' => $request->validated('device_name')], $user);

        $requires2fa = $user->hasTwoFactorEnabled();
        // The resource shows email/phone only to the user themself — resolve them as the viewer of this response.
        $request->setUserResolver(fn () => $user);
        $payload = [
            'user' => (new UserResource($user->load(['membership', 'investorProfile'])))->resolve($request),
            'two_factor_required' => $requires2fa,
        ];

        // ACC-05: with 2FA on, the session flag / token ability stays "pending" until POST /auth/2fa/challenge;
        // every protected route answers 403 `two_factor_challenge_required` meanwhile (middleware `2fa`).
        $abilities = $requires2fa ? [TwoFactorState::ABILITY_PENDING] : ['*'];
        $deviceName = $request->validated('device_name');
        if ($request->hasSession()) {
            // Stateful SPA request (Sanctum: Origin/Referer on a stateful domain) → cookie session, even if a
            // device_name was sent. A bearer token here would be useless to the browser and break every later call.
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $request->session()->put(TwoFactorState::SESSION_KEY, ! $requires2fa);
        } else {
            // Mobile (device_name) — or no session and no device name: a bearer token so the client can proceed.
            $token = $user->createToken($deviceName ? (string) $deviceName : 'api', $abilities);
            $payload['token'] = $token->plainTextToken;
            $payload['token_type'] = 'Bearer';
        }

        return response()->json(['data' => $payload]);
    }

    /** POST /auth/logout */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        if ($request->hasSession()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($user) {
            $this->audit->log('auth.logout', $user, null, null, $user);
        }

        return $this->message(__('messages.auth.logged_out'));
    }

    /** POST /auth/forgot-password — ACC-03 */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker()->sendResetLink(['email' => strtolower($request->validated('email'))]);

        // Always the same answer to avoid account enumeration.
        return $this->message(__('messages.auth.reset_link_sent'));
    }

    /** POST /auth/reset-password */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            ['email' => strtolower($request->validated('email')), 'password' => $request->validated('password'), 'password_confirmation' => $request->input('password_confirmation'), 'token' => $request->validated('token')],
            function (User $user, string $password): void {
                $user->forceFill(['password' => $password])->save();
                $user->tokens()->delete();
                $this->audit->log('auth.password_reset', $user, null, null, $user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw new DomainException(__('messages.auth.reset_failed'), 'reset_failed', 422, ['token' => [__('messages.auth.reset_failed')]]);
        }

        return $this->message(__('messages.auth.password_reset'));
    }
}
