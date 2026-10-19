<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CMS-05 — public contact form submissions. */
class ContactMessage extends Model
{
    protected $fillable = ['category', 'name', 'email', 'phone', 'message', 'reference_no', 'assigned_to', 'status', 'ip'];

    protected function casts(): array
    {
        return [
            'category' => ContactCategory::class,
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
