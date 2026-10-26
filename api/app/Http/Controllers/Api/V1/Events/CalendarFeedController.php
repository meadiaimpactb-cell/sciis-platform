<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Events;

use App\Http\Controllers\Controller;
use App\Services\CalendarFeedService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** MTG-05 — personal calendar subscription feed (manage under /me, read anonymously with the secret token). */
class CalendarFeedController extends Controller
{
    public function __construct(private readonly CalendarFeedService $feeds) {}

    /** GET /me/calendar-feed */
    public function show(Request $request): JsonResponse
    {
        return $this->ok($this->feeds->payload($this->feeds->feedOf($request->user())));
    }

    /** POST /me/calendar-feed — create or regenerate (the old URL stops working). */
    public function regenerate(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->feeds->payload($this->feeds->regenerate($request->user())), 'message' => __('messages.calendar.regenerated')], 201);
    }

    /** DELETE /me/calendar-feed */
    public function revoke(Request $request): JsonResponse
    {
        $this->feeds->revoke($request->user());

        return response()->json(['data' => $this->feeds->payload(null), 'message' => __('messages.calendar.revoked')]);
    }

    /** GET /calendar/{token}.ics — what calendar apps poll; unknown / revoked tokens and inactive users get 404. */
    public function feed(string $token): Response
    {
        $feed = $this->feeds->findByToken($token);
        if ($feed === null || $feed->user === null || ! $feed->user->isActive()) {
            abort(404);
        }

        return response($this->feeds->render($feed), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="sciis.ics"',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }
}
