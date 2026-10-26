<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\DataRequestRequest;
use App\Http\Requests\Auth\DeviceTokenRequest;
use App\Http\Requests\Auth\NotificationPreferencesRequest;
use App\Http\Requests\Auth\UpdateMeRequest;
use App\Http\Resources\UserResource;
use App\Models\DataRequest;
use App\Services\AuditLogger;
use App\Services\NotificationService;
use App\Support\TwoFactorState;
use App\Support\WebpEncoder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/** /me endpoints — ACC-07 (profile), NTF-02 (preferences), PRV-03 (data requests), mobile push tokens. */
class MeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly NotificationService $notifications) {}

    /** GET /me */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(['membership', 'investorProfile', 'organization']);

        return $this->ok((new UserResource($user))->resolve($request) + [
            'unread_notifications' => $user->unreadNotifications()->count(),
            // Same check as the `2fa` middleware (session flag for the SPA, token ability for mobile).
            'two_factor_passed' => TwoFactorState::passed($request),
        ]);
    }

    /** PATCH /me */
    public function update(UpdateMeRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->fill($request->validated())->save();
        $this->audit->logModelChange('user.updated', $user, $user);

        return $this->ok((new UserResource($user->load(['membership', 'investorProfile'])))->resolve($request));
    }

    /** POST /me/device-tokens */
    public function storeDeviceToken(DeviceTokenRequest $request): JsonResponse
    {
        $request->user()->deviceTokens()->updateOrCreate(
            ['token' => $request->validated('token')],
            ['platform' => $request->validated('platform'), 'last_seen_at' => now()],
        );

        return $this->message(__('messages.saved'), 201);
    }

    /** DELETE /me/device-tokens/{token} */
    public function deleteDeviceToken(Request $request, string $token): JsonResponse
    {
        $request->user()->deviceTokens()->where('token', $token)->delete();

        return $this->message(__('messages.deleted'));
    }

    /** GET /me/notification-preferences */
    public function notificationPreferences(Request $request): JsonResponse
    {
        return $this->ok(['preferences' => $this->notifications->preferencesMatrix($request->user())->all()]);
    }

    /** PUT /me/notification-preferences */
    public function updateNotificationPreferences(NotificationPreferencesRequest $request): JsonResponse
    {
        $this->notifications->updatePreferences($request->user(), $request->validated('preferences'));
        $this->audit->log('user.notification_preferences_updated', $request->user(), null, $request->validated(), $request->user());

        return $this->ok(['preferences' => $this->notifications->preferencesMatrix($request->user())->all()]);
    }

    /** GET /me/notifications (?unread=1 for unread only) — `meta.unread_count` feeds the portal header bell. */
    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = $request->boolean('unread') ? $user->unreadNotifications() : $user->notifications();
        $paginator = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($n): array => ['id' => $n->id, 'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at?->toIso8601String()] + (array) $n->data)->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(),
                'unread_count' => $user->unreadNotifications()->count(),
            ],
        ]);
    }

    /** POST /me/avatar (multipart `file`) — square 256px WebP on the public disk; replaces the previous one. */
    public function uploadAvatar(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072']]);
        $user = $request->user();

        $encoded = WebpEncoder::encode($request->file('file'), 256, 85, 256);
        if ($encoded === null) {
            throw ValidationException::withMessages(['file' => __('messages.content.image_failed')]);
        }

        $path = sprintf('avatars/%s.webp', Str::uuid()->toString());
        Storage::disk('public')->put($path, $encoded['bytes']);
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }
        $user->forceFill(['avatar_path' => $path])->save();
        $this->audit->log('user.avatar_updated', $user, null, ['path' => $path], $user);

        return $this->ok((new UserResource($user->load(['membership', 'investorProfile'])))->resolve($request));
    }

    /** DELETE /me/avatar */
    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
            $this->audit->log('user.avatar_removed', $user, null, null, $user);
        }

        return $this->ok((new UserResource($user->load(['membership', 'investorProfile'])))->resolve($request));
    }

    /** PUT /me/password {current_password, password, password_confirmation} — same policy as registration (ACC-06). */
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ]);
        $user = $request->user();
        $user->forceFill(['password' => Hash::make($data['password'])])->save();
        // Revoke API tokens other than the current one; web sessions keep the current session alive.
        $current = $user->currentAccessToken();
        $user->tokens()->when($current && isset($current->id), fn ($q) => $q->where('id', '!=', $current->id))->delete();
        $this->audit->log('user.password_changed', $user, null, null, $user);

        return $this->message(__('messages.auth.password_changed'));
    }

    /** POST /me/notifications/read */
    public function markNotificationsRead(Request $request): JsonResponse
    {
        $ids = (array) $request->input('ids', []);
        $query = $request->user()->unreadNotifications();
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
        $query->update(['read_at' => now()]);

        return $this->message(__('messages.saved'));
    }

    /** POST /me/data-requests — PRV-03 */
    public function dataRequest(DataRequestRequest $request): JsonResponse
    {
        $dr = DataRequest::query()->create(['user_id' => $request->user()->id] + $request->validated())->refresh(); // hydrate DB defaults (status)
        $this->audit->log('privacy.data_request', $dr, null, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $dr->id, 'type' => $dr->type->value, 'status' => $dr->status], 'message' => __('messages.privacy.request_received')], 201);
    }
}
