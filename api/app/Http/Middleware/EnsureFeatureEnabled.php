<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Routes behind a feature flag return 404 when the flag is off (e.g. payments). */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! feature($feature)) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
