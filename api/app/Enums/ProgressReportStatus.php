<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/** PRJ-02 — staff accept a submitted report or send it back with comments; the owner resubmits. */
enum ProgressReportStatus: string
{
    use HasLabel;

    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case ChangesRequested = 'changes_requested';
}
