<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Public UUID identifier on externally visible rows (SPEC §4) to avoid enumeration.
 * Models using this trait are route-bound by `uuid` unless they override getRouteKeyName().
 */
trait HasUuid
{
    public static function bootHasUuid(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getAttribute('uuid'))) {
                $model->setAttribute('uuid', (string) Str::uuid());
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

}
