<?php

declare(strict_types=1);

namespace App\Http\Requests\Rental;

use Illuminate\Foundation\Http\FormRequest;

class StoreRentalStep2Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', 'string', 'regex:/^[\d\s\+\-\(\)]{9,20}$/'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'invoice_requested' => ['sometimes', 'boolean'],
        ];

        if ($this->boolean('invoice_requested')) {
            $rules['invoice_company_name'] = ['required', 'string', 'max:255'];
            $rules['invoice_nip'] = ['required', 'string', 'max:13'];
            $rules['invoice_street'] = ['required', 'string', 'max:255'];
            $rules['invoice_street_number'] = ['required', 'string', 'max:20'];
            $rules['invoice_postal_code'] = ['required', 'string', 'max:10'];
            $rules['invoice_city'] = ['required', 'string', 'max:255'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Podaj prawidłowy numer telefonu.',
        ];
    }
}
