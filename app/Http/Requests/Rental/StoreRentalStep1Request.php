<?php

declare(strict_types=1);

namespace App\Http\Requests\Rental;

use Illuminate\Foundation\Http\FormRequest;

class StoreRentalStep1Request extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'start_date' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'start_date.after_or_equal' => 'Data rozpoczęcia musi być dzisiejsza lub przyszła.',
            'end_date.after_or_equal' => 'Data zakończenia musi być równa lub późniejsza niż data rozpoczęcia.',
        ];
    }
}
