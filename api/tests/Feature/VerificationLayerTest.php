<?php

declare(strict_types=1);

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

/*
| Standard verification layer (server half): libphonenumber phones stored as E.164, one password
| policy, the OTP contract (/auth/otp/send + /auth/verify-otp), TOTP 2FA for every user, and the
| unified {message, errors, code} error contract.
*/

const VL_PASSWORD = 'Password123!';
const VL_PHONE_INVALID_AR = 'رقم الجوال غير صحيح';

/** @param array<string, mixed> $overrides */
function vlRegistration(array $overrides = []): array
{
    return $overrides + [
        'type' => 'investor_individual', 'name' => 'مستثمر التحقق', 'email' => 'verify@investor.test', 'phone' => '+966512345678',
        'password' => 'StrongPass123!', 'locale' => 'ar', 'consents' => ['privacy_policy', 'terms', 'data_accuracy'],
    ];
}

/** Collects the codes written to the log by OtpService in local/dev (`[OTP][dev]`). */
function vlCaptureDevOtps(): ArrayObject
{
    $codes = new ArrayObject;
    Event::listen(MessageLogged::class, function (MessageLogged $e) use ($codes): void {
        if (str_contains($e->message, '[OTP][dev]')) {
            $codes[] = (string) ($e->context['code'] ?? '');
        }
    });

    return $codes;
}

function vlTotp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}

/** A code that is guaranteed not to be the given one. */
function vlWrongCode(string $code): string
{
    return $code === '000000' ? '111111' : '000000';
}

/** @return array{0: User, 1: string} entrepreneur with confirmed 2FA (+ two known recovery codes) */
function vlTwoFactorUser(): array
{
    $secret = app(Google2FA::class)->generateSecretKey(32);
    $user = User::factory()->entrepreneur()->create(['password' => VL_PASSWORD]);
    $user->forceFill([
        'two_factor_secret' => $secret,
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => ['AAAAA11111', 'BBBBB22222'],
    ])->save();

    return [$user, $secret];
}

// ───────────────────────────────────────────────────────────── 1. Phone (libphonenumber)

it('normalises Saudi mobile input to E.164 before storing it', function (string $input): void {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', vlRegistration(['phone' => $input]))->assertCreated()
        ->assertJsonPath('data.phone', '+966512345678');

    expect(User::query()->where('email', 'verify@investor.test')->value('phone'))->toBe('+966512345678');
})->with([
    'local 05…' => '0512345678',
    'local with spaces' => '05 1234 5678',
    'local with dashes/parentheses' => '(051) 234-5678',
    'without leading zero' => '512345678',
    'country code without +' => '966512345678',
    '00 international prefix' => '00966512345678',
    'E.164 with spaces' => '+966 51 234 5678',
    'Arabic-Indic digits' => '٠٥١٢٣٤٥٦٧٨',
]);

it('rejects numbers that are not real mobile numbers', function (string $input): void {
    $this->postJson('/api/v1/auth/register', vlRegistration(['phone' => $input]))->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.phone.0', VL_PHONE_INVALID_AR);

    expect(User::query()->where('email', 'verify@investor.test')->exists())->toBeFalse();
})->with([
    'Riyadh landline' => '0112345678',
    'landline E.164' => '+966112345678',
    'unallocated mobile prefix' => '+966521234567',
    'too short' => '05123',
    'too long' => '+9665123456789',
    'letters' => 'not-a-phone',
    'punctuation only' => '---',
]);

it('checks phone uniqueness on the normalised value', function (): void {
    User::factory()->create(['phone' => '+966512345678']);

    $this->postJson('/api/v1/auth/register', vlRegistration(['phone' => '0512345678']))->assertStatus(422)
        ->assertJsonValidationErrors('phone');
});

it('accepts valid international mobiles (default region SA only applies to national input)', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', vlRegistration(['phone' => '+971 50 123 4567']))->assertCreated();
    expect(User::query()->where('email', 'verify@investor.test')->value('phone'))->toBe('+971501234567');
});

it('validates and normalises the optional phone on the contact form', function (): void {
    Notification::fake();
    $payload = ['category' => 'other', 'name' => 'زائر الموقع', 'email' => 'visitor@example.test', 'message' => 'رسالة تجريبية للتواصل مع المجلس'];

    $this->postJson('/api/v1/public/contact', $payload + ['phone' => '055 123 4567'])->assertCreated();
    $this->assertDatabaseHas('contact_messages', ['email' => 'visitor@example.test', 'phone' => '+966551234567']);

    $this->postJson('/api/v1/public/contact', $payload + ['phone' => '011 234 5678'])->assertStatus(422)
        ->assertJsonPath('errors.phone.0', VL_PHONE_INVALID_AR);

    $this->postJson('/api/v1/public/contact', $payload)->assertCreated(); // optional
});

it('validates and normalises the phone when staff accounts are created', function (): void {
    Notification::fake();
    config(['sciis.two_factor.enforce_for_staff' => false]);
    $this->actingAs($this->staff('super_admin'), 'api');

    $this->postJson('/api/v1/admin/users', ['name' => 'موظف بجوال', 'email' => 'phone.staff@sciis.test', 'phone' => '0501234567', 'roles' => ['reviewer']])
        ->assertCreated();
    expect(User::query()->where('email', 'phone.staff@sciis.test')->value('phone'))->toBe('+966501234567');

    $this->postJson('/api/v1/admin/users', ['name' => 'موظف آخر', 'email' => 'bad.phone@sciis.test', 'phone' => '0112345678', 'roles' => ['reviewer']])
        ->assertStatus(422)->assertJsonPath('errors.phone.0', VL_PHONE_INVALID_AR);
});

it('stores E.164 whatever the write path (model mutator)', function (): void {
    $user = User::factory()->create(['phone' => '0559876543']);

    expect($user->fresh()->phone)->toBe('+966559876543');
});

// ───────────────────────────────────────────────────────────── 2. Password policy

it('applies the single password policy (min 10, mixed case, number, symbol) on register', function (string $password, string $rule): void {
    $messages = $this->postJson('/api/v1/auth/register', vlRegistration(['password' => $password]))
        ->assertStatus(422)->assertJsonValidationErrors('password')->json('errors.password');

    expect($messages)->toContain(__('validation.'.$rule, ['attribute' => __('validation.attributes.password'), 'min' => 10]));
})->with([
    'no symbol' => ['StrongPass123', 'password.symbols'],
    'too short' => ['Sh0rt!pw', 'min.string'],
    'no uppercase' => ['strongpass123!', 'password.mixed'],
    'no number' => ['StrongPassword!', 'password.numbers'],
]);

it('applies the same policy on reset, password change and staff creation', function (): void {
    Notification::fake();
    config(['sciis.two_factor.enforce_for_staff' => false]);

    $this->postJson('/api/v1/auth/reset-password', ['token' => 'x', 'email' => 'a@b.test', 'password' => 'StrongPass123', 'password_confirmation' => 'StrongPass123'])
        ->assertStatus(422)->assertJsonValidationErrors('password');

    $user = User::factory()->entrepreneur()->create(['password' => VL_PASSWORD]);
    $this->actingAs($user, 'api');
    $this->putJson('/api/v1/me/password', ['current_password' => VL_PASSWORD, 'password' => 'NewPass12345', 'password_confirmation' => 'NewPass12345'])
        ->assertStatus(422)->assertJsonValidationErrors('password');
    $this->putJson('/api/v1/me/password', ['current_password' => VL_PASSWORD, 'password' => 'NewPass12345!', 'password_confirmation' => 'NewPass12345!'])
        ->assertOk();

    $this->actingAs($this->staff('super_admin'), 'api');
    $this->postJson('/api/v1/admin/users', ['name' => 'موظف جديد', 'email' => 'pw.staff@sciis.test', 'password' => 'StrongPass123', 'roles' => ['reviewer']])
        ->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson('/api/v1/admin/users', ['name' => 'موظف جديد', 'email' => 'pw.staff@sciis.test', 'password' => 'StrongPass123!', 'roles' => ['reviewer']])
        ->assertCreated();
});

// ───────────────────────────────────────────────────────────── 3. OTP contract

it('sends and verifies an OTP via the standard contract; the code is deleted after use and never returned', function (): void {
    Notification::fake();
    $codes = vlCaptureDevOtps();
    $user = User::factory()->entrepreneur()->unverified()->create();
    $this->actingAs($user, 'api');

    $send = $this->postJson('/api/v1/auth/otp/send', ['channel' => 'phone'])->assertOk()
        ->assertJsonPath('data.channel', 'phone')
        ->assertJsonPath('data.expires_in', 600)
        ->assertJsonPath('data.resend_in', 60)
        ->assertJsonStructure(['data', 'message']);

    expect($codes)->toHaveCount(1);
    $code = $codes[0];
    $row = OtpCode::query()->where('user_id', $user->id)->sole();
    expect($code)->toMatch('/^\d{6}$/')
        ->and($send->getContent())->not->toContain($code)
        ->and($row->code_hash)->not->toContain($code)
        ->and(Hash::check($code, $row->code_hash))->toBeTrue()
        ->and((int) round(now()->diffInMinutes($row->expires_at, true)))->toBe(10);

    $verify = $this->postJson('/api/v1/auth/verify-otp', ['channel' => 'phone', 'code' => $code])->assertOk()
        ->assertJsonPath('data.verified', true)
        ->assertJsonPath('data.phone_verified', true)
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('data.fully_verified', false);

    expect($verify->getContent())->not->toContain($code)
        ->and($user->fresh()->phone_verified_at)->not->toBeNull();
    $this->assertDatabaseCount('otp_codes', 0); // single use: deleted, not just marked consumed

    // Re-using the code is impossible.
    $this->postJson('/api/v1/auth/verify-otp', ['channel' => 'phone', 'code' => $code])->assertStatus(422)->assertJsonPath('code', 'otp_expired');
});

it('never returns the OTP codes issued at registration', function (): void {
    Notification::fake();
    config(['mail.default' => 'log']);
    $codes = vlCaptureDevOtps();

    $body = $this->postJson('/api/v1/auth/register', vlRegistration())->assertCreated()->getContent();

    expect($codes)->toHaveCount(2);
    foreach ($codes as $code) {
        expect($body)->not->toContain($code);
    }
});

it('verifies the email channel and accepts Arabic-Indic digits in the code', function (): void {
    Notification::fake();
    config(['mail.default' => 'log']);
    $codes = vlCaptureDevOtps();
    $user = User::factory()->entrepreneur()->unverified()->create();
    $this->actingAs($user, 'api');

    $this->postJson('/api/v1/auth/otp/send', ['channel' => 'email'])->assertOk()->assertJsonPath('data.channel', 'email');
    $arabic = strtr($codes[0], ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);

    $this->postJson('/api/v1/auth/verify-otp', ['channel' => 'email', 'code' => $arabic])->assertOk()->assertJsonPath('data.email_verified', true);
});

it('only logs the code in local/dev when the driver is `log`', function (): void {
    Notification::fake();
    $codes = vlCaptureDevOtps();
    $user = User::factory()->entrepreneur()->unverified()->create();
    $this->actingAs($user, 'api');

    config(['mail.default' => 'smtp']);
    $this->postJson('/api/v1/auth/otp/send', ['channel' => 'email'])->assertOk();
    expect($codes)->toHaveCount(0);

    $this->app['env'] = 'production';
    config(['sciis.sms.driver' => 'log']);
    $this->postJson('/api/v1/auth/otp/send', ['channel' => 'phone'])->assertOk();
    expect($codes)->toHaveCount(0);
});

it('limits OTP sends/verifications to 5 per minute per user and channel (6th → 429)', function (): void {
    Notification::fake();
    $codes = vlCaptureDevOtps();
    $user = User::factory()->entrepreneur()->unverified()->create();
    $this->actingAs($user, 'api');

    $this->postJson('/api/v1/auth/otp/send', ['channel' => 'phone'])->assertOk(); // 1
    $wrong = vlWrongCode($codes[0]);
    foreach (range(2, 5) as $_) {
        $this->postJson('/api/v1/auth/verify-otp', ['channel' => 'phone', 'code' => $wrong])->assertStatus(422)->assertJsonPath('code', 'otp_invalid');
    }

    $this->postJson('/api/v1/auth/verify-otp', ['channel' => 'phone', 'code' => $codes[0]])->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonStructure(['message', 'errors', 'code', 'request_id'])
        ->assertJsonPath('code', 'too_many_requests')
        ->assertJsonPath('message', __('messages.errors.too_many_requests'));

    // Separate bucket per channel.
    $this->postJson('/api/v1/auth/otp/send', ['channel' => 'email'])->assertOk();

    // A minute later the phone bucket is free again and the (still valid) code works.
    $this->travel(61)->seconds();
    $this->postJson('/api/v1/auth/verify-otp', ['channel' => 'phone', 'code' => $codes[0]])->assertOk();
});

it('keeps the legacy OTP endpoints working with the same logic', function (): void {
    Notification::fake();
    config(['mail.default' => 'log']);
    $codes = vlCaptureDevOtps();
    $user = User::factory()->entrepreneur()->unverified()->create();
    $this->actingAs($user, 'api');

    $this->postJson('/api/v1/auth/resend-otp', ['channel' => 'sms'])->assertOk()->assertJsonPath('message', __('messages.auth.otp_sent'));
    $this->postJson('/api/v1/auth/verify-phone', ['code' => $codes[0]])->assertOk();

    $this->postJson('/api/v1/auth/resend-otp', ['channel' => 'email'])->assertOk();
    $this->postJson('/api/v1/auth/verify-email', ['code' => $codes[1]])->assertOk();

    expect($user->fresh()->isFullyVerified())->toBeTrue();
    $this->assertDatabaseCount('otp_codes', 0);
    $this->postJson('/api/v1/auth/otp/send', ['channel' => 'phone'])->assertStatus(422)->assertJsonPath('code', 'already_verified');
});

// ───────────────────────────────────────────────────────────── 4. TOTP 2FA

it('enable returns a secret, otpauth URL and server-side SVG QR but does not enable 2FA', function (): void {
    $user = User::factory()->investor()->create();
    $this->actingAs($user, 'api');

    $data = $this->postJson('/api/v1/auth/2fa/enable')->assertOk()
        ->assertJsonStructure(['data' => ['secret', 'otpauth_url', 'qr']])
        ->assertJsonPath('data.two_factor_enabled', false)
        ->json('data');

    expect($data['otpauth_url'])->toStartWith('otpauth://totp/')
        ->and($data['qr'])->toStartWith('data:image/svg+xml;base64,')
        ->and(base64_decode(substr($data['qr'], strlen('data:image/svg+xml;base64,'))))->toContain('<svg')
        ->and($user->fresh()->hasTwoFactorEnabled())->toBeFalse();

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.two_factor_enabled', false);
});

it('confirm enables 2FA and returns 8 one-time recovery codes stored encrypted', function (): void {
    $user = User::factory()->investor()->create();
    $this->actingAs($user, 'api');

    $secret = $this->postJson('/api/v1/auth/2fa/enable')->assertOk()->json('data.secret');
    $current = vlTotp($secret);

    $this->postJson('/api/v1/auth/2fa/confirm', ['code' => vlWrongCode($current)])->assertStatus(422)->assertJsonPath('code', 'two_factor_invalid');
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();

    $codes = $this->postJson('/api/v1/auth/2fa/confirm', ['code' => $current])->assertOk()
        ->assertJsonPath('data.two_factor_enabled', true)
        ->json('data.recovery_codes');

    expect($codes)->toHaveCount(8)
        ->and(array_unique($codes))->toHaveCount(8)
        ->each->toMatch('/^[A-Z0-9]{10}$/');

    $fresh = $user->fresh();
    $raw = DB::table('users')->where('id', $user->id)->first(['two_factor_secret', 'two_factor_recovery_codes']);
    expect($fresh->hasTwoFactorEnabled())->toBeTrue()
        ->and($fresh->two_factor_recovery_codes)->toBe($codes)
        ->and((string) $raw->two_factor_recovery_codes)->not->toContain($codes[0])
        ->and((string) $raw->two_factor_secret)->not->toBe($secret);

    // Enable/confirm cannot be replayed once 2FA is on.
    $this->postJson('/api/v1/auth/2fa/enable')->assertStatus(422)->assertJsonPath('code', 'two_factor_enabled');
    $this->postJson('/api/v1/auth/2fa/confirm', ['code' => vlTotp($secret)])->assertStatus(422)->assertJsonPath('code', 'two_factor_enabled');
});

it('POST /auth/2fa/disable requires BOTH the current password and a valid code', function (): void {
    [$user, $secret] = vlTwoFactorUser();
    $this->actingAs($user, 'api');

    $this->postJson('/api/v1/auth/2fa/disable', ['code' => vlTotp($secret)])->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson('/api/v1/auth/2fa/disable', ['password' => VL_PASSWORD])->assertStatus(422)->assertJsonValidationErrors('code');
    $this->postJson('/api/v1/auth/2fa/disable', ['password' => 'WrongPass123!', 'code' => vlTotp($secret)])->assertStatus(422)->assertJsonValidationErrors('password');
    $this->postJson('/api/v1/auth/2fa/disable', ['password' => VL_PASSWORD, 'code' => vlWrongCode(vlTotp($secret))])->assertStatus(422)->assertJsonPath('code', 'two_factor_invalid');
    expect($user->fresh()->hasTwoFactorEnabled())->toBeTrue();

    $this->postJson('/api/v1/auth/2fa/disable', ['password' => VL_PASSWORD, 'code' => vlTotp($secret)])->assertOk()
        ->assertJsonPath('message', __('messages.auth.two_factor_disabled'));
    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse()
        ->and($user->fresh()->two_factor_recovery_codes)->toBeNull();
});

it('legacy DELETE /auth/2fa also requires the password and a code (recovery code accepted)', function (): void {
    [$user] = vlTwoFactorUser();
    $this->actingAs($user, 'api');

    $this->deleteJson('/api/v1/auth/2fa', ['code' => 'AAAAA11111'])->assertStatus(422)->assertJsonValidationErrors('password');
    $this->deleteJson('/api/v1/auth/2fa', ['password' => VL_PASSWORD])->assertStatus(422)->assertJsonValidationErrors('code');
    $this->deleteJson('/api/v1/auth/2fa', ['password' => VL_PASSWORD, 'code' => 'AAAAA11111'])->assertOk();

    expect($user->fresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('blocks a non-staff bearer-token user with 2FA on every protected resource until the challenge', function (): void {
    [$user, $secret] = vlTwoFactorUser();

    $pending = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => VL_PASSWORD, 'device_name' => 'Pixel'])->assertOk()
        ->assertJsonPath('data.two_factor_required', true)
        ->json('data.token');
    expect($user->tokens()->sole()->abilities)->toBe(['2fa:pending']);

    $this->withToken($pending);
    foreach ([['get', '/api/v1/entrepreneur/dashboard'], ['patch', '/api/v1/me'], ['get', '/api/v1/me/notifications'], ['post', '/api/v1/auth/otp/send'], ['post', '/api/v1/auth/2fa/enable']] as [$method, $uri]) {
        app('auth')->forgetGuards();
        $this->json($method, $uri, ['name' => 'اسم جديد', 'channel' => 'email'])->assertForbidden()
            ->assertJsonPath('code', 'two_factor_challenge_required')
            ->assertJsonStructure(['message', 'errors', 'code']);
    }

    // GET /me stays reachable and tells the client what is pending.
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.two_factor_passed', false)->assertJsonPath('data.two_factor_enabled', true);

    app('auth')->forgetGuards();
    $this->postJson('/api/v1/auth/2fa/challenge', ['code' => vlWrongCode(vlTotp($secret))])->assertStatus(422)->assertJsonPath('code', 'two_factor_invalid');

    app('auth')->forgetGuards();
    $full = $this->postJson('/api/v1/auth/2fa/challenge', ['code' => vlTotp($secret)])->assertOk()
        ->assertJsonPath('data.two_factor_passed', true)
        ->assertJsonPath('data.token_type', 'Bearer')
        ->json('data.token');

    app('auth')->forgetGuards();
    $this->withToken($full)->getJson('/api/v1/entrepreneur/dashboard')->assertOk();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.two_factor_passed', true);

    // The pending token was swapped (revoked) by the challenge.
    app('auth')->forgetGuards();
    $this->withToken($pending)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});

it('lets a pending 2FA session log out', function (): void {
    [$user] = vlTwoFactorUser();
    $pending = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => VL_PASSWORD, 'device_name' => 'Pixel'])->json('data.token');

    $this->withToken($pending)->postJson('/api/v1/auth/logout')->assertOk();
    expect($user->tokens()->count())->toBe(0);
});

it('requires the challenge for a 2FA cookie session (SPA) too', function (): void {
    [$user, $secret] = vlTwoFactorUser();
    $spa = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/auth/login'];

    $this->withHeaders($spa)->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => VL_PASSWORD])->assertOk()
        ->assertJsonPath('data.two_factor_required', true)->assertJsonMissingPath('data.token');

    $this->withHeaders($spa)->getJson('/api/v1/entrepreneur/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_challenge_required');
    $this->withHeaders($spa)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.two_factor_passed', false);

    $this->withHeaders($spa)->postJson('/api/v1/auth/2fa/challenge', ['code' => vlTotp($secret)])->assertOk()
        ->assertJsonPath('data.two_factor_passed', true)->assertJsonMissingPath('data.token');

    $this->withHeaders($spa)->getJson('/api/v1/entrepreneur/dashboard')->assertOk();
    $this->withHeaders($spa)->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.two_factor_passed', true);
});

it('accepts a recovery code exactly once', function (): void {
    [$user] = vlTwoFactorUser();

    $first = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => VL_PASSWORD, 'device_name' => 'Pixel'])->json('data.token');
    $this->withToken($first)->postJson('/api/v1/auth/2fa/challenge', ['code' => 'aaaaa 11111'])->assertOk()
        ->assertJsonPath('data.two_factor_passed', true)
        ->assertJsonPath('data.recovery_codes_remaining', 1);
    expect($user->fresh()->two_factor_recovery_codes)->toBe(['BBBBB22222']);

    app('auth')->forgetGuards();
    $this->flushHeaders();
    $second = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => VL_PASSWORD, 'device_name' => 'Pixel'])->json('data.token');
    app('auth')->forgetGuards();
    $this->withToken($second)->postJson('/api/v1/auth/2fa/challenge', ['code' => 'AAAAA11111'])->assertStatus(422)->assertJsonPath('code', 'two_factor_invalid');
});

it('rate-limits 2FA attempts (5 per minute per user)', function (): void {
    [$user, $secret] = vlTwoFactorUser();
    $pending = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => VL_PASSWORD, 'device_name' => 'Pixel'])->json('data.token');
    $wrong = vlWrongCode(vlTotp($secret));

    foreach (range(1, 5) as $_) {
        app('auth')->forgetGuards();
        $this->withToken($pending)->postJson('/api/v1/auth/2fa/challenge', ['code' => $wrong])->assertStatus(422);
    }
    app('auth')->forgetGuards();
    $this->withToken($pending)->postJson('/api/v1/auth/2fa/challenge', ['code' => vlTotp($secret)])->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
});

it('still enforces staff enrolment and the challenge in the staff area', function (): void {
    config(['sciis.two_factor.enforce_for_staff' => true]);
    $staff = $this->staff('reviewer');
    $staff->forceFill(['password' => VL_PASSWORD, 'two_factor_secret' => app(Google2FA::class)->generateSecretKey(32), 'two_factor_confirmed_at' => now()])->save();

    $pending = $this->postJson('/api/v1/auth/login', ['email' => $staff->email, 'password' => VL_PASSWORD, 'device_name' => 'Pixel'])->json('data.token');
    $this->withToken($pending)->getJson('/api/v1/admin/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_challenge_required');
});

// ───────────────────────────────────────────────────────────── 5. Unified error contract

it('renders every api error as {message, errors, code} (401 / 403 / 422)', function (): void {
    $this->getJson('/api/v1/me')->assertUnauthorized()
        ->assertJsonStructure(['message', 'errors', 'code', 'request_id'])
        ->assertJsonPath('code', 'unauthenticated')
        ->assertJsonPath('message', __('messages.errors.unauthenticated'));

    $this->postJson('/api/v1/auth/register', [])->assertStatus(422)
        ->assertJsonStructure(['message', 'errors' => ['type', 'email', 'phone', 'password'], 'code'])
        ->assertJsonPath('code', 'validation_failed');

    $this->actingAs(User::factory()->entrepreneur()->unverified()->create(), 'api');
    $this->getJson('/api/v1/entrepreneur/dashboard')->assertForbidden()
        ->assertJsonStructure(['message', 'errors', 'code'])
        ->assertJsonPath('code', 'verification_required');
});

it('localises error messages to the request locale (Accept-Language)', function (): void {
    config(['sciis.features.english' => true]);

    $this->withHeader('Accept-Language', 'en')->postJson('/api/v1/auth/register', vlRegistration(['phone' => '0112345678', 'password' => 'StrongPass123']))
        ->assertStatus(422)
        ->assertJsonPath('message', 'The given data is invalid.')
        ->assertJsonPath('errors.phone.0', 'Invalid mobile number')
        ->assertJsonPath('errors.password.0', 'The password field must contain at least one symbol.');

    $this->withHeader('Accept-Language', 'ar')->postJson('/api/v1/auth/register', vlRegistration(['phone' => '0112345678']))
        ->assertStatus(422)
        ->assertJsonPath('message', 'البيانات المدخلة غير صالحة.')
        ->assertJsonPath('errors.phone.0', VL_PHONE_INVALID_AR);

    $this->withHeader('Accept-Language', 'en')->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('message', 'Authentication required.');
});
