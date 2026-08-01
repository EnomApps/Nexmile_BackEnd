<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class OtpVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Send the same identifier that was used to request the code.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required_without:phone', 'prohibits:phone', 'nullable', 'email', 'max:191'],
            'phone' => ['required_without:email', 'nullable', 'string', 'regex:/^[6-9]\d{9}$/'],
            'code' => ['required', 'string', 'digits:'.config('otp.length')],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'code.digits' => 'The code is '.config('otp.length').' digits.',
            'email.required_without' => 'Provide either an email address or a mobile number.',
            'phone.required_without' => 'Provide either an email address or a mobile number.',
            'email.prohibits' => 'Provide either an email address or a mobile number, not both.',
        ];
    }

    /** Lowercased for email, so a differently-cased address still matches. */
    public function identifier(): string
    {
        return $this->filled('email')
            ? mb_strtolower(trim($this->string('email')->toString()))
            : trim($this->string('phone')->toString());
    }
}
