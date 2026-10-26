<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\InstitutionType;
use App\Enums\LegalForm;
use App\Enums\ProjectStage;
use App\Enums\UserType;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\NormalizesPhone;
use App\Http\Requests\Investor\UpdateProfileRequest;
use App\Support\PhoneNumbers;
use Illuminate\Validation\Rules\Password;

/**
 * ACC-01. Besides the account fields, the web wizard may send the investor profile (same sectioned
 * shape as `UpdateProfileRequest`, under `profile`), organization details and, for entrepreneurs,
 * the first project basics (`project`, same rules as `StoreOpportunityRequest`).
 */
class RegisterRequest extends ApiRequest
{
    use NormalizesPhone;

    /** Phone → E.164 before `unique:users,phone` runs (standard verification layer). */
    protected function prepareForValidation(): void
    {
        $this->normalizePhoneFields('phone');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $types = array_filter(UserType::values(), fn (string $t): bool => $t !== UserType::Staff->value);

        $profileRules = collect((new UpdateProfileRequest)->rules())
            ->mapWithKeys(fn (array $rule, string $key): array => ['profile.'.$key => $rule])
            ->all();

        return [
            'type' => ['required', 'string', 'in:'.implode(',', $types)],
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['required', 'string', PhoneNumbers::rule(), 'unique:users,phone'],
            'password' => ['required', 'string', Password::defaults()],
            'locale' => ['nullable', 'string', 'in:ar,en'],
            'preferred_contact' => ['nullable', 'string', 'in:email,phone,whatsapp'],
            'organization_name' => ['required_if:type,investor_institutional,entrepreneur_entity', 'nullable', 'string', 'max:190'],
            'organization' => ['nullable', 'array'],
            'organization.institution_type' => ['nullable', 'string', $this->enumRule(InstitutionType::class)],
            'organization.legal_form' => ['nullable', 'string', $this->enumRule(LegalForm::class)],
            'organization.cr_number' => ['nullable', 'string', 'max:50'],
            'organization.city' => ['nullable', 'string', 'max:100'],
            'consents' => ['required', 'array', 'min:2'],
            'consents.*' => ['string', 'in:privacy_policy,terms,data_accuracy'],
            'consents_version' => ['nullable', 'string', 'max:20'],

            'profile' => ['nullable', 'array'],
            ...$profileRules,

            'project' => ['nullable', 'array'],
            'project.name' => ['required_with:project', 'string', 'min:3', 'max:190'],
            'project.short_description' => ['nullable', 'string', 'max:280'],
            'project.sector_id' => ['nullable', 'integer', 'exists:taxonomy_terms,id'],
            'project.stage' => ['nullable', 'string', $this->enumRule(ProjectStage::class)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v): void {
            $consents = (array) $this->input('consents', []);
            if (! in_array('privacy_policy', $consents, true) || ! in_array('terms', $consents, true)) {
                $v->errors()->add('consents', __('validation.custom.consents.required_both'));
            }
            // INV-01 — investors also declare that the information provided is accurate (stored as a consent).
            if (str_starts_with((string) $this->input('type'), 'investor') && ! in_array('data_accuracy', $consents, true)) {
                $v->errors()->add('consents.data_accuracy', __('validation.custom.consents.data_accuracy'));
            }
        });
    }
}
