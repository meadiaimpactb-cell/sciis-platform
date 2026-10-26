<?php

declare(strict_types=1);

namespace App\Http\Requests\Agreements;

use App\Http\Requests\ApiRequest;

/** AGR — base request: attribute names from lang/{ar,en}/agreements.php on top of the shared validation names. */
abstract class AgreementRequest extends ApiRequest
{
    public const TEXT_MAX = 100000;

    public const NOTE_MAX = 2000;

    /** @return array<string, string> */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), (array) __('agreements.attributes'));
    }
}
