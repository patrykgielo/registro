<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePersonalInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone_e164' => ['nullable', 'string', 'regex:/^\+[1-9]\d{1,14}$/'],
            // Billing address fields
            'billing_street' => ['nullable', 'string', 'max:255'],
            'billing_building_number' => ['nullable', 'string', 'max:20'],
            'billing_apartment_number' => ['nullable', 'string', 'max:20'],
            'billing_postal_code' => ['nullable', 'regex:/^\d{2}-\d{3}$/'],
            'billing_city' => ['nullable', 'string', 'max:100'],
            'nip' => ['nullable', 'digits:10'],
            'company_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'first_name.required' => __('Imię jest wymagane.'),
            'last_name.required' => __('Nazwisko jest wymagane.'),
            'phone_e164.regex' => __('Numer telefonu musi być w formacie międzynarodowym (np. +48123456789).'),
            'billing_postal_code.regex' => __('Kod pocztowy musi być w formacie XX-XXX.'),
            'nip.digits' => __('NIP musi składać się z 10 cyfr.'),
        ];
    }
}
