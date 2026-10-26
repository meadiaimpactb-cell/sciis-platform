<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Portal header & account page: profile edit, avatar, password change, notification bell. */
it('updates profile fields via PATCH /me', function (): void {
    $user = User::factory()->entrepreneur()->create();
    $this->actingAs($user, 'api');

    $this->patchJson('/api/v1/me', ['name' => 'اسم محدث', 'locale' => 'en', 'preferred_contact' => 'phone'])
        ->assertOk()->assertJsonPath('data.name', 'اسم محدث')->assertJsonPath('data.locale', 'en');
});

it('uploads an avatar as a 256px square WebP and replaces/removes it', function (): void {
    Storage::fake('public');
    $user = User::factory()->entrepreneur()->create();
    $this->actingAs($user, 'api');

    $url = $this->post('/api/v1/me/avatar', ['file' => UploadedFile::fake()->image('me.png', 800, 600)], ['Accept' => 'application/json'])
        ->assertOk()->json('data.avatar_url');
    $first = $user->fresh()->avatar_path;
    expect($url)->toContain('avatars/')->and($first)->toEndWith('.webp');
    Storage::disk('public')->assertExists($first);
    expect(substr(Storage::disk('public')->get($first), 0, 4))->toBe('RIFF');
    [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($first));
    expect([$w, $h])->toBe([256, 256]);

    $this->post('/api/v1/me/avatar', ['file' => UploadedFile::fake()->image('me2.jpg', 300, 300)], ['Accept' => 'application/json'])->assertOk();
    Storage::disk('public')->assertMissing($first);

    $this->deleteJson('/api/v1/me/avatar')->assertOk()->assertJsonPath('data.avatar_url', null);
    expect($user->fresh()->avatar_path)->toBeNull();

    $this->post('/api/v1/me/avatar', ['file' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);
});

it('changes the password only with the current password and the strength policy', function (): void {
    $user = User::factory()->entrepreneur()->create(['password' => 'OldPass12345']);
    $this->actingAs($user, 'api');

    $this->putJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'NewPass12345!', 'password_confirmation' => 'NewPass12345!'])->assertStatus(422);
    $this->putJson('/api/v1/me/password', ['current_password' => 'OldPass12345', 'password' => 'weak', 'password_confirmation' => 'weak'])->assertStatus(422);
    $this->putJson('/api/v1/me/password', ['current_password' => 'OldPass12345', 'password' => 'NewPass12345!', 'password_confirmation' => 'NewPass12345!'])->assertOk();

    expect(Hash::check('NewPass12345!', $user->fresh()->password))->toBeTrue();
});

it('returns unread_count and filters unread notifications', function (): void {
    $user = User::factory()->entrepreneur()->create();
    foreach ([null, now()] as $readAt) {
        $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['title' => 'T', 'body' => 'B'], 'read_at' => $readAt]);
    }
    $this->actingAs($user, 'api');

    $this->getJson('/api/v1/me/notifications')->assertOk()->assertJsonPath('meta.unread_count', 1)->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/me/notifications?unread=1')->assertOk()->assertJsonCount(1, 'data');
    $this->postJson('/api/v1/me/notifications/read')->assertOk();
    $this->getJson('/api/v1/me/notifications')->assertJsonPath('meta.unread_count', 0);
});
