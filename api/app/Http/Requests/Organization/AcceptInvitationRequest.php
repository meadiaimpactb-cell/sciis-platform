<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Enums\UserType;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Concerns\NormalizesPhone;
use App\Services\OrganizationTeamService;
use App\Support\PhoneNumbers;
use Illuminate\Validation\Rules\Password;

/**
 * ACC-08 — a colleague completes the registration from the invitation link (same account rules as RegisterRequest;
 * the e-mail and account type come from the invitation). Investor organisations also declare data accuracy (INV-01).
 * An unknown / used / expired token answers 404 / 410 before any field is validated.
 */
class AcceptInvitationRequest extends ApiRequest
{
    use NormalizesPhone;

    private bool $forInvestor = false;

    protected function prepareForValidation(): void
    {
        $service = app(OrganizationTeamService::class);
        $invitation = $service->findByToken((string) $this->route('token'));
        $this->forInvestor = $service->memberType($invitation->organization) === UserType::InvestorInstitutional;

        $this->normalizePhoneFields('phone');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'phone' => ['required', 'string', PhoneNumbers::rule(), 'unique:users,phone'],
            'password' => ['required', 'string', Password::defaults()],
            'password_confirmation' => ['nullable', 'string', 'same:password'],
            'locale' => ['nullable', 'string', 'in:ar,en'],
            'preferred_contact' => ['nullable', 'string', 'in:email,phone,whatsapp'],
            'consents' => ['required', 'array', 'min:2'],
            'consents.*' => ['string', 'in:privacy_policy,terms,data_accuracy'],
            'consents_version' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($v): void {
            $consents = (array) $this->input('consents', []);
            if (! in_array('privacy_policy', $consents, true) || ! in_array('terms', $consents, true)) {
                $v->errors()->add('consents', __('validation.custom.consents.required_both'));
            }
            if ($this->forInvestor && ! in_array('data_accuracy', $consents, true)) {
                $v->errors()->add('consents.data_accuracy', __('validation.custom.consents.data_accuracy'));
            }
        });
    }
}
