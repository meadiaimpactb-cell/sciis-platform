<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\Rule;

/** ACC-03 — create / update a staff role (spatie, guard `api`). */
class RoleRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $isCreate = $this->isMethod('POST');
        $roleId = $this->route('role')?->id;

        return [
            'name' => [$isCreate ? 'required' : 'sometimes', 'string', 'min:3', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/', Rule::unique('roles', 'name')->where('guard_name', 'api')->ignore($roleId)],
            'permissions' => [$isCreate ? 'required' : 'sometimes', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name')->where('guard_name', 'api')],
        ];
    }
}
