<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AddressRequest extends FormRequest
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
        // PATCH sends only what changed; POST must carry the full address.
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'label' => ['sometimes', 'in:home,work,other'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'regex:/^[6-9]\d{9}$/'],
            'line1' => [$required, 'string', 'max:255'],
            'line2' => ['nullable', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'city' => [$required, 'string', 'max:255'],
            'state' => ['sometimes', 'string', 'max:255'],
            'pincode' => [$required, 'string', 'regex:/^[1-9]\d{5}$/'],
            // Required: the 1 km radius is computed from these, and an address
            // without coordinates cannot be matched to a zone or a merchant.
            'latitude' => [$required, 'numeric', 'between:-90,90'],
            'longitude' => [$required, 'numeric', 'between:-180,180'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'contact_phone.regex' => 'Enter a valid 10-digit Indian mobile number.',
            'pincode.regex' => 'Enter a valid 6-digit PIN code.',
            'latitude.required' => 'A map location is required so we can find restaurants near you.',
            'longitude.required' => 'A map location is required so we can find restaurants near you.',
        ];
    }
}
