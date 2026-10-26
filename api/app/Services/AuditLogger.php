<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * AUD-01..03 — helper used by every mutation. Request id / ip / user agent are read
 * from the current request (set by RequestId middleware) when available.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function log(
        string $action,
        ?Model $auditable = null,
        ?array $before = null,
        ?array $after = null,
        ?User $actor = null,
    ): AuditLog {
        $request = $this->currentRequest();
        $actor ??= $request?->user();

        return AuditLog::query()->create([
            'user_id' => $actor?->id,
            'action' => $action,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'before' => $before,
            'after' => $after,
            'ip' => $request?->ip(),
            'user_agent' => $request ? substr((string) $request->userAgent(), 0, 1000) : null,
            'request_id' => $request?->attributes->get('request_id'),
            'created_at' => now(),
        ]);
    }

    /**
     * AUD-01 — read-access entries (`*.viewed`): at most one row per actor + action + record within the window,
     * so reloading a file (or the SPA refreshing it after each action) does not flood the trail.
     *
     * @param  array<string, mixed>|null  $after
     */
    public function logThrottled(string $action, Model $auditable, ?User $actor = null, ?array $after = null, int $minutes = 10): ?AuditLog
    {
        $actor ??= $this->currentRequest()?->user();

        $recent = AuditLog::query()
            ->where('action', $action)
            ->where('auditable_type', $auditable->getMorphClass())
            ->where('auditable_id', $auditable->getKey())
            ->where('user_id', $actor?->id)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->exists();

        return $recent ? null : $this->log($action, $auditable, null, $after, $actor);
    }

    /**
     * ACC-04 — a 403 answered to an authenticated user. Called from the exception renderer (bootstrap/app.php);
     * guests are never logged and a request is logged once even if several 403s are rendered for it.
     */
    public function accessDenied(Request $request, string $code): void
    {
        try {
            if ($request->attributes->get('access_denied_logged') === true) {
                return;
            }
            $user = $request->user();
            if (! $user instanceof User) {
                return;
            }
            $request->attributes->set('access_denied_logged', true);

            $this->log('access.denied', null, null, [
                'method' => $request->method(),
                'path' => '/'.ltrim($request->path(), '/'),
                'route' => $request->route()?->getName(),
                'status' => 403,
                'code' => $code,
            ], $user);
        } catch (\Throwable) {
            // Never let audit logging break the error response.
        }
    }

    /** Convenience: logs the dirty attributes of a model that was just saved. */
    public function logModelChange(string $action, Model $model, ?User $actor = null): AuditLog
    {
        $changes = $model->getChanges();
        $before = [];
        foreach (array_keys($changes) as $key) {
            $before[$key] = $model->getOriginal($key);
        }

        return $this->log($action, $model, $before, $changes, $actor);
    }

    private function currentRequest(): ?Request
    {
        try {
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                return null;
            }

            return app()->bound('request') ? app('request') : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
