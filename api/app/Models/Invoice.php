<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = ['number', 'user_id', 'amount', 'vat_rate', 'vat_amount', 'total', 'issued_at', 'pdf_document_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'vat_rate' => 'decimal:4',
            'vat_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'issued_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function pdf(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'pdf_document_id');
    }
}
