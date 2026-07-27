<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Accepts either the registered email or the 10-digit mobile number.
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Whether the supplied identifier should be matched against email or phone.
     */
    public function identifierField(): string
    {
        return filter_var($this->input('identifier'), FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
    }
}
