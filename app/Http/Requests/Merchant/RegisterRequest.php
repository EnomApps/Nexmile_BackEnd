<?php

namespace App\Http\Requests\Merchant;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
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
            // Account owner
            'owner_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^[6-9]\d{9}$/', 'unique:users,phone'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],

            // Business profile
            'business_name' => ['required', 'string', 'max:255'],
            'business_phone' => ['nullable', 'string', 'regex:/^[6-9]\d{9}$/'],
            'business_email' => ['nullable', 'email', 'max:255'],

            // Location
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['nullable', 'string', 'max:255'],
            'pincode' => ['required', 'string', 'regex:/^[1-9]\d{5}$/'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],

            // KYC — optional at signup, completed in the onboarding step
            'fssai_license_no' => ['nullable', 'string', 'regex:/^\d{14}$/'],
            'fssai_expiry_date' => ['nullable', 'date', 'after:today'],
            'gstin' => ['nullable', 'string', 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z]\d[Z][0-9A-Z]$/'],
            'pan' => ['nullable', 'string', 'regex:/^[A-Z]{5}\d{4}[A-Z]$/'],

            'preferred_locale' => ['nullable', 'in:en,ta'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'pincode.regex' => 'Enter a valid 6-digit PIN code.',
            'fssai_license_no.regex' => 'FSSAI licence number must be exactly 14 digits.',
            'fssai_expiry_date.after' => 'This FSSAI licence has already expired.',
            'gstin.regex' => 'Enter a valid 15-character GSTIN.',
            'pan.regex' => 'Enter a valid PAN (e.g. ABCDE1234F).',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'pan' => $this->pan ? strtoupper($this->pan) : null,
            'gstin' => $this->gstin ? strtoupper($this->gstin) : null,
        ], fn ($value) => $value !== null));
    }
}
