<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\NewsPost;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Static hosting: news / event pages published after the last site build have no prerendered HTML yet, so the web
 * server sends `/news/{slug}` and `/events/{slug}` (and their /en twins) here instead of blindly serving the SPA shell.
 * A published item gets the shell (200.html, which renders it in the browser); anything else gets the real 404 page
 * with a 404 status — no "soft 404" for search engines.
 */
class SiteShellController extends Controller
{
    public function __invoke(Request $request): Response
    {
        // Read by name: the optional `{locale}` segment would shift positional arguments.
        $model = $request->route('kind') === 'events' ? Event::class : NewsPost::class;
        $exists = $model::query()->published()->where('slug', (string) $request->route('slug'))->exists();
        $file = public_path($exists ? '200.html' : '404.html');
        $html = is_file($file) ? (string) file_get_contents($file) : '';

        return response($html, $exists ? 200 : 404, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'public, max-age=0, must-revalidate',
            'X-Robots-Tag' => $exists ? 'index, follow' : 'noindex',
        ]);
    }
}
