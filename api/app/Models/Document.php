<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentKind;
use App\Enums\DocumentStatus;
use App\Enums\ScanResult;
use App\Enums\Sensitivity;
use App\Models\Concerns\HasUuid;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** DOC-01..03 — encrypted at rest, signed temporary URLs only, every access logged. */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'owner_user_id', 'documentable_type', 'documentable_id', 'kind', 'title', 'disk', 'path', 'mime', 'size_bytes',
        'sha256', 'sensitivity', 'status', 'reviewer_note', 'version', 'supersedes_document_id', 'scanned_at', 'scan_result',
    ];

    protected $hidden = ['path', 'disk'];

    protected function casts(): array
    {
        return [
            'kind' => DocumentKind::class,
            'status' => DocumentStatus::class,
            'sensitivity' => Sensitivity::class,
            'scan_result' => ScanResult::class,
            'size_bytes' => 'integer',
            'version' => 'integer',
            'scanned_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DocumentFactory
    {
        return DocumentFactory::new();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_document_id');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(DocumentAccessLog::class);
    }

    public function scopeClean(Builder $query): Builder
    {
        return $query->where('scan_result', ScanResult::Clean->value);
    }

    public function isDownloadable(): bool
    {
        return $this->scan_result === ScanResult::Clean && $this->status !== DocumentStatus::Rejected;
    }
}
