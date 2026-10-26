<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * NTF-01 — one admin override of a notification text, keyed like `lang/{locale}/notifications.php`
 * in dot notation (e.g. `status_changed.subject`).
 *
 * @property int $id
 * @property string $locale
 * @property string $key
 * @property string $value
 * @property ?int $updated_by
 */
class NotificationTemplate extends Model
{
    protected $fillable = ['locale', 'key', 'value', 'updated_by'];
}
