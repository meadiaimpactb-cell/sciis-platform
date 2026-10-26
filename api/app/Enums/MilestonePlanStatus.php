<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/** PRJ-01 — milestone plan approval: the owner edits while draft / changes_requested; staff approve a submitted plan. */
enum MilestonePlanStatus: string
{
    use HasLabel;

    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case ChangesRequested = 'changes_requested';

    public function isEditableByOwner(): bool
    {
        return $this === self::Draft || $this === self::ChangesRequested;
    }
}
