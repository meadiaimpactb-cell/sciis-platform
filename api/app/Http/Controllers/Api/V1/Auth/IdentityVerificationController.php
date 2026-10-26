<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\IdentityVerificationStatus;
use App\Exceptions\DomainException;
use App\Http\Controllers\Controller;
use App\Models\IdentityVerification;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Identity\IdentityVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ACC-09 (P3) — national identity verification through the configured IdentityVerifier (Nafath):
 *   GET  /me/identity-verification          → {available, verified, verified_at, pending}
 *   POST /me/identity-verification {national_id} → starts a request, returns the number to pick in the app
 *   GET  /me/identity-verification/{uuid}   → polls the provider; approval stamps `users.national_id_verified_at`
 * With the default `none` driver the entry is hidden (`available: false`, POST → 404).
 */
class IdentityVerificationController extends Controller
{
    public function __construct(private readonly IdentityVerifier $verifier, private readonly AuditLogger $audit) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $pending = IdentityVerification::query()
            ->where('user_id', $user->id)
            ->where('status', IdentityVerificationStatus::Pending->value)
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        return $this->ok([
            'available' => $this->verifier->available(),
            'driver' => $this->verifier->available() ? $this->verifier->driver() : null,
            'verified' => $user->national_id_verified_at !== null,
            'verified_at' => $user->national_id_verified_at?->toIso8601String(),
            'pending' => $pending?->toPublicArray(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->verifier->available()) {
            throw new DomainException(__('messages.identity.unavailable'), 'identity_unavailable', 404);
        }
        $data = $request->validate(['national_id' => ['required', 'string', 'regex:/^[12]\d{9}$/']], [
            'national_id.regex' => __('messages.identity.national_id_format'),
        ], ['national_id' => __('messages.identity.national_id')]);

        /** @var User $user */
        $user = $request->user();
        if ($user->national_id_verified_at !== null) {
            throw new DomainException(__('messages.identity.already_verified'), 'identity_already_verified', 422);
        }

        $hash = self::hashNationalId($data['national_id']);
        $taken = User::query()->whereKeyNot($user->id)->where('national_id_hash', $hash)->whereNotNull('national_id_verified_at')->exists();
        if ($taken) {
            throw new DomainException(__('messages.identity.national_id_taken'), 'identity_national_id_taken', 422, ['national_id' => [__('messages.identity.national_id_taken')]]);
        }

        $started = $this->verifier->start($data['national_id'], (string) ($user->locale ?: app()->getLocale()));

        $verification = DB::transaction(function () use ($user, $hash, $data, $started): IdentityVerification {
            // A new request supersedes any request still pending for this user.
            IdentityVerification::query()->where('user_id', $user->id)->where('status', IdentityVerificationStatus::Pending->value)
                ->update(['status' => IdentityVerificationStatus::Expired->value, 'national_id_encrypted' => null, 'completed_at' => now()]);

            return IdentityVerification::query()->create([
                'user_id' => $user->id,
                'driver' => $this->verifier->driver(),
                'national_id_hash' => $hash,
                'national_id_encrypted' => $data['national_id'],
                'transaction_id' => $started->transactionId,
                'random' => $started->random,
                'status' => IdentityVerificationStatus::Pending,
                'expires_at' => $started->expiresAt,
            ]);
        });

        $this->audit->log('identity.verification_started', $user, null, ['driver' => $verification->driver, 'verification' => $verification->uuid], $user);

        return $this->ok($verification->toPublicArray(), 201);
    }

    public function poll(Request $request, string $verification): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $row = IdentityVerification::query()->where('uuid', $verification)->where('user_id', $user->id)->firstOrFail();

        if ($row->status === IdentityVerificationStatus::Pending) {
            $status = $row->expires_at !== null && $row->expires_at->isPast()
                ? IdentityVerificationStatus::Expired
                : $this->verifier->status((string) $row->national_id_encrypted, $row->toRequest());

            if ($status->isFinal()) {
                $this->complete($row, $user, $status);
            }
        }

        return $this->ok($row->toPublicArray() + ['verified' => $user->refresh()->national_id_verified_at !== null]);
    }

    private function complete(IdentityVerification $row, User $user, IdentityVerificationStatus $status): void
    {
        DB::transaction(function () use ($row, $user, $status): void {
            $row->forceFill(['status' => $status, 'completed_at' => now(), 'national_id_encrypted' => null])->save();

            if ($status === IdentityVerificationStatus::Approved) {
                $user->forceFill(['national_id_verified_at' => now(), 'national_id_hash' => $row->national_id_hash])->save();
                $this->audit->log('identity.verified', $user, null, ['driver' => $row->driver, 'verification' => $row->uuid], $user);
            } else {
                $this->audit->log('identity.verification_failed', $user, null, ['driver' => $row->driver, 'verification' => $row->uuid, 'status' => $status->value], $user);
            }
        });
    }

    /** Keyed hash (app key) — lets the platform refuse a second account with the same ID without storing the number. */
    public static function hashNationalId(string $nationalId): string
    {
        return hash_hmac('sha256', $nationalId, (string) config('app.key'));
    }
}
