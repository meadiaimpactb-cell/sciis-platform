<?php

declare(strict_types=1);

use App\Enums\OpportunityStatus;
use App\Enums\OtpChannel;
use App\Models\Opportunity;
use App\Models\User;
use App\Notifications\OtpNotification;
use App\Services\OpportunityStateMachine;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function (): void {
    config(['sciis.two_factor.enforce_for_staff' => false]);
    $this->admin = $this->staff('super_admin');
});

it('lets the admin edit a notification text and restore the default (NTF-01)', function (): void {
    $this->actingAs($this->admin, 'api');

    $item = collect($this->getJson('/api/v1/admin/notification-templates')->assertOk()->json('data.items'))->firstWhere('key', 'otp.subject');
    expect($item['placeholders'])->toBe(['app'])->and($item['ar']['value'])->toBeNull();

    $this->putJson('/api/v1/admin/notification-templates', ['locale' => 'ar', 'key' => 'otp.subject', 'value' => 'رمزك من :app'])->assertOk();
    app()->setLocale('ar');
    app('translator')->setLoaded([]);
    expect(__('notifications.otp.subject', ['app' => 'المنصة']))->toBe('رمزك من المنصة');

    $this->assertDatabaseHas('audit_logs', ['action' => 'notification_template.updated']);

    $this->putJson('/api/v1/admin/notification-templates', ['locale' => 'ar', 'key' => 'otp.subject', 'value' => null])->assertOk();
    app('translator')->setLoaded([]);
    expect(__('notifications.otp.subject', ['app' => 'المنصة']))->toBe('رمز التحقق — المنصة');
});

it('rejects unknown keys and placeholders the notification does not provide', function (): void {
    $this->actingAs($this->admin, 'api');

    $this->putJson('/api/v1/admin/notification-templates', ['locale' => 'ar', 'key' => 'nope.subject', 'value' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('key');
    // A cleared field restores the default text.
    $this->putJson('/api/v1/admin/notification-templates', ['locale' => 'ar', 'key' => 'otp.subject', 'value' => '  '])->assertOk()->assertJsonPath('data.value', null);
    $this->putJson('/api/v1/admin/notification-templates', ['locale' => 'ar', 'key' => 'otp.subject', 'value' => 'رمز :code'])->assertUnprocessable()->assertJsonValidationErrors('value');

    $this->actingAs($this->staff('reviewer'), 'api');
    $this->getJson('/api/v1/admin/notification-templates')->assertForbidden();
});

it('uses the edited text in the e-mail the user receives', function (): void {
    $this->actingAs($this->admin, 'api');
    $this->putJson('/api/v1/admin/notification-templates', ['locale' => 'ar', 'key' => 'otp.subject', 'value' => 'رمز الدخول — :app'])->assertOk();
    app('translator')->setLoaded([]);

    $user = User::factory()->investor()->create(['locale' => 'ar']);
    app()->setLocale('ar');
    $mail = (new OtpNotification('123456', OtpChannel::Email, 'verify'))->toMail($user);
    expect($mail->subject)->toStartWith('رمز الدخول — ');
});

it('reports the launch checklist to settings managers', function (): void {
    Cache::forever('sciis.scheduler.heartbeat', now()->toIso8601String());
    $this->actingAs($this->admin, 'api');

    $checks = collect($this->getJson('/api/v1/admin/system-status')->assertOk()->json('data.checks'))->keyBy('key');
    expect($checks->keys()->all())->toContain('mail', 'sms', 'scheduler', 'backup', 'verification', 'staff_2fa')
        ->and($checks['scheduler']['ok'])->toBeTrue()
        ->and($checks['backup']['ok'])->toBeFalse();

    $this->actingAs($this->staff('reviewer'), 'api');
    $this->getJson('/api/v1/admin/system-status')->assertForbidden();
});

it('applies the idle timeout setting to the session and keeps active sessions alive (ACC-07)', function (): void {
    app(SettingsService::class)->setMany(['security.idle_timeout_minutes' => 15], $this->admin);

    $this->actingAs($this->admin, 'api')->postJson('/api/v1/auth/keepalive')->assertNoContent();
    expect(config('session.lifetime'))->toBe(15);
    $this->getJson('/api/v1/settings/public')->assertOk()->assertJsonPath('data.idle_timeout_minutes', 15);
});

it('refuses database backups on drivers other than MySQL/MariaDB and prunes old archives', function (): void {
    $dir = storage_path('framework/testing/backups');
    config(['sciis.backup.path' => $dir]);
    @mkdir($dir, 0777, true);

    $this->artisan('backup:run')->assertFailed();
    expect(glob($dir.'/sciis-db-*'))->toBe([]);
});

it('signs in with a one-time code by e-mail instead of the authenticator app (ACC-05)', function (): void {
    Notification::fake();
    $user = User::factory()->entrepreneur()->create(['email_verified_at' => now(), 'phone_verified_at' => null]);
    $user->forceFill(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(32), 'two_factor_confirmed_at' => now()])->save();
    $this->actingAs($user, 'api');

    // SMS needs a verified phone and a real provider.
    $this->postJson('/api/v1/auth/2fa/send-code', ['channel' => 'phone'])->assertUnprocessable()->assertJsonPath('code', 'channel_unavailable');

    $code = null;
    $this->postJson('/api/v1/auth/2fa/send-code', ['channel' => 'email'])->assertOk()->assertJsonPath('data.channel', 'email');
    Notification::assertSentTo($user, OtpNotification::class, function (OtpNotification $n) use (&$code): bool {
        $code = (fn () => $this->code)->call($n);

        return true;
    });

    $this->postJson('/api/v1/auth/2fa/challenge', ['code' => $code === '000000' ? '111111' : '000000'])->assertUnprocessable();
    $this->postJson('/api/v1/auth/2fa/challenge', ['code' => $code])->assertOk()->assertJsonPath('data.two_factor_passed', true);
    $this->assertDatabaseMissing('otp_codes', ['user_id' => $user->id, 'purpose' => 'login']);
});

it('lets the admin choose the permission each status transition needs (ADM-01)', function (): void {
    $officer = $this->staff('agreements_officer');
    $opportunity = Opportunity::factory()->status(OpportunityStatus::Negotiating)->create();
    $machine = app(OpportunityStateMachine::class);
    expect($machine->permits($opportunity, OpportunityStatus::InExecution, $officer))->toBeFalse();

    $this->actingAs($this->admin, 'api')
        ->putJson('/api/v1/admin/settings', ['settings' => ['workflow.transition_permissions' => ['in_execution' => 'agreements.manage']]])->assertOk();
    expect($machine->permits($opportunity, OpportunityStatus::InExecution, $officer))->toBeTrue();

    $this->putJson('/api/v1/admin/settings', ['settings' => ['workflow.transition_permissions' => ['nope' => 'agreements.manage']]])->assertUnprocessable();
    $this->putJson('/api/v1/admin/settings', ['settings' => ['workflow.transition_permissions' => ['in_execution' => 'no.such']]])->assertUnprocessable();
});
