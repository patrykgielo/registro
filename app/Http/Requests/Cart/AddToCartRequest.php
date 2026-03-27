<?php

declare(strict_types=1);

namespace App\Http\Requests\Cart;

use Illuminate\Foundation\Http\FormRequest;

class AddToCartRequest extends FormRequest
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
            'service_id' => ['required', 'exists:services,id'],
            'start_date' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:today'],
            'end_date' => ['required', 'date', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'service_id.exists' => 'Wybrana usługa nie istnieje.',
            'start_date.after_or_equal' => 'Data rozpoczęcia musi być dzisiejsza lub przyszła.',
            'end_date.after_or_equal' => 'Data zakończenia musi być równa lub późniejsza niż data rozpoczęcia.',
            'quantity.min' => 'Ilość musi wynosić co najmniej 1.',
        ];
    }
}
