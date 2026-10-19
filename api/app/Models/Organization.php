<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InstitutionType;
use App\Enums\LegalForm;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Organization extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'name', 'legal_form', 'institution_type', 'cr_number', 'unified_number', 'license_number', 'founded_on',
        'employees_count', 'website', 'region_id', 'city', 'representative_user_id', 'authorization_letter_document_id',
    ];

    protected function casts(): array
    {
        return [
            'legal_form' => LegalForm::class,
            'institution_type' => InstitutionType::class,
            'founded_on' => 'date',
            'employees_count' => 'integer',
        ];
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(TaxonomyTerm::class, 'region_id');
    }

    public function representative(): BelongsTo
    {
        return $this->belongsTo(User::class, 'representative_user_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function authorizationLetter(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'authorization_letter_document_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function foundedYear(): ?int
    {
        return $this->founded_on?->year;
    }
}
