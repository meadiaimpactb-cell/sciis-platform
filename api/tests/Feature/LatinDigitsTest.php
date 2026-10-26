<?php

declare(strict_types=1);

use App\Console\Commands\LatinDigitsCommand;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Platform rule: numbers are stored and shown with Latin digits only (no Arabic-Indic numerals). */
it('converts Arabic-Indic digits and signs in incoming request strings before they are stored', function (): void {
    $user = User::factory()->entrepreneur()->create();
    $this->actingAs($user, 'api');

    $this->patchJson('/api/v1/me', ['name' => 'سارة ٢٠٢٦ ۱۲ ٪٥٠'])
        ->assertOk()
        ->assertJsonPath('data.name', 'سارة 2026 12 %50');
    expect($user->fresh()->name)->toBe('سارة 2026 12 %50');
});

it('leaves passwords exactly as typed', function (): void {
    $user = User::factory()->entrepreneur()->create(['password' => 'OldPass12345!']);
    $this->actingAs($user, 'api');

    // A new password containing Arabic-Indic digits is kept byte-for-byte (only other fields are normalised).
    $this->putJson('/api/v1/me/password', ['current_password' => 'OldPass12345!', 'password' => 'NewPass١٢٣٤٥aA!', 'password_confirmation' => 'NewPass١٢٣٤٥aA!'])->assertOk();
    expect(Hash::check('NewPass١٢٣٤٥aA!', $user->fresh()->password))->toBeTrue();
});

it('rewrites stored text and JSON-escaped digits with content:latin-digits', function (): void {
    expect(LatinDigitsCommand::convert('نسبة ٨٠٪ و ۳٫٥'))->toBe('نسبة 80% و 3.5')
        ->and(LatinDigitsCommand::convert('{"a":"٨٠٪"}'))->toBe('{"a":"80%"}');

    $user = User::factory()->entrepreneur()->create();
    DB::table('users')->where('id', $user->id)->update(['name' => 'اسم ١٢٣']);

    $this->artisan('content:latin-digits', ['--dry-run' => true])->assertSuccessful();
    expect(DB::table('users')->where('id', $user->id)->value('name'))->toBe('اسم ١٢٣');

    $this->artisan('content:latin-digits')->assertSuccessful();
    expect(DB::table('users')->where('id', $user->id)->value('name'))->toBe('اسم 123');
});
