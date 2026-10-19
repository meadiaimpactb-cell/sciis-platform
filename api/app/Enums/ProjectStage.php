<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum ProjectStage: string
{
    use HasLabel;

    case Idea = 'idea';
    case Prototype = 'prototype';
    case PreSeed = 'pre_seed';
    case Seed = 'seed';
    case EarlyStage = 'early_stage';
    case LaterStage = 'later_stage';
}
