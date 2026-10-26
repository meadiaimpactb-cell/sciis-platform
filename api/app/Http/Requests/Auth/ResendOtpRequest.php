<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\OtpChannel;
use App\Http\Requests\ApiRequest;

/** POST /auth/otp/send and legacy POST /auth/resend-otp — `channel`: email | phone (legacy alias: sms). */
class ResendOtpRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['channel' => ['required', 'string', 'in:'.implode(',', OtpChannel::INPUT_VALUES)]];
    }

    public function channel(): OtpChannel
    {
        return OtpChannel::fromInput($this->validated('channel')) ?? OtpChannel::Email;
    }
}
