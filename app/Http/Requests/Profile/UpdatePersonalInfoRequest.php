<?php

declare(strict_types=1);

namespace App\Http\Requests\Profile;

use App\Rules\ValidPolishPESEL;
use App\Rules\ValidPolishREGON;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePersonalInfoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
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
            // Rental / legal profile fields
            'customer_type' => ['nullable', Rule::in(['natural_person', 'business'])],
            'pesel' => ['nullable', new ValidPolishPESEL],
            'regon' => ['nullable', new ValidPolishREGON],
            'krs' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required' => __('Imię jest wymagane.'),
            'last_name.required' => __('Nazwisko jest wymagane.'),
            'phone_e164.regex' => __('Numer telefonu musi być w formacie międzynarodowym (np. +48123456789).'),
            'billing_postal_code.regex' => __('Kod pocztowy musi być w formacie XX-XXX.'),
            'nip.digits' => __('NIP musi składać się z 10 cyfr.'),
            'customer_type.in' => __('Nieprawidłowy typ klienta.'),
            'krs.max' => __('Numer KRS/CEIDG nie może przekraczać 20 znaków.'),
        ];
    }
}
