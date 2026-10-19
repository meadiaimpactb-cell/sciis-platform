<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OtpChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OtpCode extends Model
{
    protected $fillable = ['user_id', 'channel', 'purpose', 'code_hash', 'expires_at', 'consumed_at', 'attempts'];

    protected function casts(): array
    {
        return [
            'channel' => OtpChannel::class,
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->where('expires_at', '>', now());
    }
}
