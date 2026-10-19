<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Accept-Language: ar|en (English only when the feature flag is on). */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = feature('english') ? (array) config('sciis.locales', ['ar']) : ['ar'];
        $requested = $request->getPreferredLanguage($supported) ?? config('app.locale', 'ar');

        if ($request->user()?->locale && in_array($request->user()->locale, $supported, true) && ! $request->headers->has('Accept-Language')) {
            $requested = $request->user()->locale;
        }

        app()->setLocale(in_array($requested, $supported, true) ? $requested : 'ar');

        return $next($request);
    }
}
