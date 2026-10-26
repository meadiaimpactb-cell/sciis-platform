<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UX-05 / BRD-04 — display preferences of the signed-in user, merged into `users.preferences`:
 *   calendar: gregorian | hijri | both   (dates across the web app; Hijri = Umm al-Qura, Latin digits)
 *   theme:    system | light | dark      (portal only, behind FEATURE_DARK_MODE)
 */
class PreferencesController extends Controller
{
    public const DEFAULTS = ['calendar' => 'gregorian', 'theme' => 'system'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** PATCH /me/preferences */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'calendar' => ['sometimes', 'string', 'in:gregorian,hijri,both'],
            'theme' => ['sometimes', 'string', 'in:system,light,dark'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $before = self::of($user);
        $user->forceFill(['preferences' => array_merge((array) $user->preferences, $data)])->save();
        $this->audit->log('user.preferences_updated', $user, $before, self::of($user), $user);

        return $this->ok(['preferences' => self::of($user)]);
    }

    /** @return array<string, string> */
    public static function of(User $user): array
    {
        return array_intersect_key(array_merge(self::DEFAULTS, (array) $user->preferences), self::DEFAULTS);
    }
}
