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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'two_factor_passed' => $request->hasSession() ? (bool) $request->session()->get('2fa.passed', ! $user->hasTwoFactorEnabled()) : true,
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

    /** GET /me/notifications */
    public function notifications(Request $request): JsonResponse
    {
        $paginator = $request->user()->notifications()->paginate($this->perPage($request));

        return response()->json([
            'data' => collect($paginator->items())->map(fn ($n): array => ['id' => $n->id, 'read_at' => $n->read_at?->toIso8601String(), 'created_at' => $n->created_at?->toIso8601String()] + (array) $n->data)->all(),
            'meta' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
        ]);
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
        $dr = DataRequest::query()->create(['user_id' => $request->user()->id] + $request->validated());
        $this->audit->log('privacy.data_request', $dr, null, $request->validated(), $request->user());

        return response()->json(['data' => ['id' => $dr->id, 'type' => $dr->type->value, 'status' => $dr->status], 'message' => __('messages.privacy.request_received')], 201);
    }
}
