<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class OtpRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Send exactly one of `email` or `phone`. Email delivers the code by
     * email; phone delivers it by SMS.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required_without:phone', 'prohibits:phone', 'nullable', 'email', 'max:191'],
            'phone' => ['required_without:email', 'nullable', 'string', 'regex:/^[6-9]\d{9}$/'],
            // Only self-service roles. A merchant or admin account cannot be
            // created by asking for a code.
            'intended_role' => ['nullable', 'in:customer,rider'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'email.required_without' => 'Provide either an email address or a mobile number.',
            'phone.required_without' => 'Provide either an email address or a mobile number.',
            'email.prohibits' => 'Provide either an email address or a mobile number, not both.',
        ];
    }

    /**
     * The value the code will be sent to.
     *
     * Email addresses are lowercased here rather than only in the service, so
     * the controller echoes back the same identifier that gets stored —
     * otherwise the client would send "A@b.in" on verify and not match.
     */
    public function identifier(): string
    {
        return $this->filled('email')
            ? mb_strtolower(trim($this->string('email')->toString()))
            : trim($this->string('phone')->toString());
    }
}
