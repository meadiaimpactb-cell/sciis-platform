<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Reporting;

use App\Http\Requests\ApiRequest;

/** Base for the reporting / bulk / advisory requests: attribute names from lang/{ar,en}/reporting.php too. */
abstract class ReportingRequest extends ApiRequest
{
    /** @return array<string, string> */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), (array) __('reporting.attributes'));
    }

    protected function routeEndsWith(string $suffix): bool
    {
        return str_ends_with((string) $this->route()?->getName(), $suffix);
    }
}
