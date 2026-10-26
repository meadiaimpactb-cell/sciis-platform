<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ACC-07 — idle timeout. A session expires after `security.idle_timeout_minutes` without requests
 * (0 keeps the framework default). Runs before the session starts, so the lifetime applies to this request.
 */
class IdleTimeout
{
    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $minutes = $this->settings->int('security.idle_timeout_minutes', 30);
        if ($minutes > 0) {
            config(['session.lifetime' => $minutes]);
        }

        return $next($request);
    }
}
