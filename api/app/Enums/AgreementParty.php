<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Concerns\HasLabel;

/** AGR-04/05 — the two signing parties of an agreement (the council is not a party by default, doc §16.1). */
enum AgreementParty: string
{
    use HasLabel;

    case Owner = 'owner';
    case Investor = 'investor';
}
