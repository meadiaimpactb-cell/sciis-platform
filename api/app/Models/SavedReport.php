<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** RPT-04 — a named custom report definition; RPT-05 — optional weekly/monthly e-mail schedule. */
class SavedReport extends Model
{
    use HasUuid;

    public const FREQUENCIES = ['weekly', 'monthly'];

    public const FORMATS = ['xlsx', 'pdf'];

    protected $fillable = [
        'name', 'dataset', 'definition', 'created_by', 'schedule_frequency', 'schedule_format', 'schedule_recipients',
        'next_run_at', 'last_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'schedule_recipients' => 'array',
            'next_run_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isScheduled(): bool
    {
        return in_array($this->schedule_frequency, self::FREQUENCIES, true);
    }

    public function scopeDue(Builder $query, ?Carbon $at = null): Builder
    {
        return $query->whereIn('schedule_frequency', self::FREQUENCIES)->whereNotNull('next_run_at')->where('next_run_at', '<=', $at ?? now());
    }

    /** Next send time after `$from`: weekly → next Sunday 07:00, monthly → the 1st of next month 07:00 (Riyadh). */
    public static function nextRunAfter(string $frequency, ?Carbon $from = null): Carbon
    {
        $local = ($from ?? now())->copy()->setTimezone('Asia/Riyadh');
        $next = $frequency === 'monthly'
            ? $local->copy()->startOfMonth()->addMonthNoOverflow()->setTime(7, 0)
            : $local->copy()->next(Carbon::SUNDAY)->setTime(7, 0);

        return $next->setTimezone(config('app.timezone', 'UTC'));
    }
}
