<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\AuditLog */
class AuditLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null,
            'action' => $this->action,
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'before' => $this->before,
            'after' => $this->after,
            'ip' => $this->ip,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
