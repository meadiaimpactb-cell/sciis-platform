<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Short browser cache for the public read endpoints (CMS pages, news, settings…): repeat visits and the static site's
 * post-load refresh reuse the response instead of waiting on the API. `private` keeps shared caches/CDN out (the body
 * depends on Accept-Language); `Vary` makes the browser keep one copy per language. A CMS edit shows within minutes.
 */
class PublicCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethodCacheable() && $response->isSuccessful()) {
            $response->headers->set('Cache-Control', 'private, max-age=60, stale-while-revalidate=300');
            $response->setVary(array_unique([...$response->getVary(), 'Accept-Language']));
        }

        return $response;
    }
}
