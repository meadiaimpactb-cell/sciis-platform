<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/** PRJ-01 — `delayed` is derived (due date passed, not done); the stored value is planned | in_progress | done. */
enum MilestoneStatus: string
{
    use HasLabel;

    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Delayed = 'delayed';
}
