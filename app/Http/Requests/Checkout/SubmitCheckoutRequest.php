<?php

declare(strict_types=1);

namespace App\Http\Requests\Checkout;

use Illuminate\Foundation\Http\FormRequest;

class SubmitCheckoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'customer_first_name' => ['required', 'string', 'max:100'],
            'customer_last_name' => ['required', 'string', 'max:100'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:20'],
            'invoice_requested' => ['nullable', 'boolean'],
            'invoice_company_name' => ['required_if:invoice_requested,true', 'nullable', 'string', 'max:255'],
            'invoice_nip' => ['required_if:invoice_requested,true', 'nullable', 'string', 'max:20'],
            'invoice_street' => ['required_if:invoice_requested,true', 'nullable', 'string', 'max:255'],
            'invoice_street_number' => ['required_if:invoice_requested,true', 'nullable', 'string', 'max:20'],
            'invoice_postal_code' => ['required_if:invoice_requested,true', 'nullable', 'string', 'max:10'],
            'invoice_city' => ['required_if:invoice_requested,true', 'nullable', 'string', 'max:100'],
        ];
    }
}
