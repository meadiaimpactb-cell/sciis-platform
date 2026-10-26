<?php

declare(strict_types=1);

namespace App\Services\Identity;

use App\Enums\IdentityVerificationStatus;
use App\Exceptions\DomainException;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ACC-09 — Nafath MFA driver (skeleton). Real use requires the platform to be onboarded with the Nafath service
 * (Elm), which issues the APP-ID / APP-KEY and confirms the service name and base URL (NAFATH_* in .env).
 *
 *   POST {base}/api/v1/mfa/request?local=ar|en&requestId={uuid}  {nationalId, service}  → {transId, random}
 *   POST {base}/api/v1/mfa/request/status                         {nationalId, transId, random} → {status}
 *        status: WAITING | COMPLETED | REJECTED | EXPIRED
 * Endpoint paths and payloads must be re-checked against the onboarding pack before go-live; the provider's
 * signed callback (JWT) is not wired — the platform polls the status instead.
 */
final class NafathIdentityVerifier implements IdentityVerifier
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $appId,
        private readonly string $appKey,
        private readonly string $service,
        private readonly int $ttlSeconds = 180,
        private readonly int $timeout = 10,
    ) {}

    public function driver(): string
    {
        return 'nafath';
    }

    public function available(): bool
    {
        return $this->baseUrl !== '' && $this->appId !== '' && $this->appKey !== '';
    }

    public function start(string $nationalId, string $locale): IdentityRequest
    {
        $query = http_build_query(['local' => $locale === 'en' ? 'en' : 'ar', 'requestId' => (string) Str::uuid()]);
        $response = $this->send('/api/v1/mfa/request?'.$query, ['nationalId' => $nationalId, 'service' => $this->service]);

        $transId = (string) $response->json('transId', '');
        if ($transId === '') {
            throw $this->providerError('start', $response);
        }

        return new IdentityRequest($transId, $response->json('random') !== null ? (string) $response->json('random') : null, CarbonImmutable::now()->addSeconds($this->ttlSeconds));
    }

    public function status(string $nationalId, IdentityRequest $request): IdentityVerificationStatus
    {
        $response = $this->send('/api/v1/mfa/request/status', ['nationalId' => $nationalId, 'transId' => $request->transactionId, 'random' => $request->random]);

        return match (strtoupper((string) $response->json('status', ''))) {
            'WAITING' => IdentityVerificationStatus::Pending,
            'COMPLETED' => IdentityVerificationStatus::Approved,
            'REJECTED' => IdentityVerificationStatus::Rejected,
            'EXPIRED' => IdentityVerificationStatus::Expired,
            default => IdentityVerificationStatus::Failed,
        };
    }

    /** @param array<string, mixed> $body */
    private function send(string $path, array $body): Response
    {
        try {
            $response = $this->client()->post(rtrim($this->baseUrl, '/').$path, $body);
        } catch (ConnectionException $e) {
            Log::warning('nafath.unreachable', ['path' => $path, 'error' => $e->getMessage()]);

            throw new DomainException(__('messages.identity.provider_error'), 'identity_provider_error', 502);
        }

        if ($response->failed()) {
            throw $this->providerError($path, $response);
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout($this->timeout)->withHeaders(['APP-ID' => $this->appId, 'APP-KEY' => $this->appKey]);
    }

    private function providerError(string $step, Response $response): DomainException
    {
        // The national ID is never logged.
        Log::warning('nafath.error', ['step' => $step, 'status' => $response->status(), 'code' => $response->json('code')]);

        return new DomainException(__('messages.identity.provider_error'), 'identity_provider_error', 502);
    }
}
