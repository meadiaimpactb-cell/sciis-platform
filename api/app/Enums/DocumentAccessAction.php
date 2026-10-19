<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

enum DocumentAccessAction: string
{
    use HasLabel;

    case View = 'view';
    case Download = 'download';
}
