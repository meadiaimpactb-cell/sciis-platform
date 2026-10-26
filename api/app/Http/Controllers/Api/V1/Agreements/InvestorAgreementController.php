<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Agreements;

use App\Enums\AgreementParty;

/** AGR-04/05 — the connected investor's agreements (/investor/agreements). */
class InvestorAgreementController extends PartyAgreementController
{
    protected function party(): AgreementParty
    {
        return AgreementParty::Investor;
    }
}
