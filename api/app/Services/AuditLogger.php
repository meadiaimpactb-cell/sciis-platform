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
