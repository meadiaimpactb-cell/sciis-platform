<?php

declare(strict_types=1);

use Illuminate\Cache\RateLimiting\Unlimited;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** The static site build renders every public page from one machine; its secret token skips the per-IP limits. */
it('exempts only GET requests carrying the configured prerender token', function (): void {
    $request = fn (string $method, ?string $token): Request => Request::create('/api/v1/public/stats', $method, server: $token === null ? [] : ['HTTP_X_PRERENDER_TOKEN' => $token]);

    config(['sciis.prerender_token' => 'build-secret']);
    foreach (['public', 'api'] as $limiter) {
        expect(RateLimiter::limiter($limiter)($request('GET', 'build-secret')))->toBeInstanceOf(Unlimited::class)
            ->and(RateLimiter::limiter($limiter)($request('GET', 'wrong')))->not->toBeInstanceOf(Unlimited::class)
            ->and(RateLimiter::limiter($limiter)($request('GET', null)))->not->toBeInstanceOf(Unlimited::class)
            ->and(RateLimiter::limiter($limiter)($request('POST', 'build-secret')))->not->toBeInstanceOf(Unlimited::class);
    }

    // No token configured → nobody is exempt, not even a request sending an empty header.
    config(['sciis.prerender_token' => '']);
    expect(RateLimiter::limiter('public')($request('GET', '')))->not->toBeInstanceOf(Unlimited::class);
});
