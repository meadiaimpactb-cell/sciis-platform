<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Enums\OtpChannel;

/** POST /auth/verify-otp {channel: email|phone, code} — standard contract (legacy alias channel `sms` accepted). */
class VerifyChannelOtpRequest extends VerifyOtpRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + ['channel' => ['required', 'string', 'in:'.implode(',', OtpChannel::INPUT_VALUES)]];
    }

    public function channel(): OtpChannel
    {
        return OtpChannel::fromInput($this->validated('channel')) ?? OtpChannel::Email;
    }
}
