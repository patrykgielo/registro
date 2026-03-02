<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_type_id' => ['required', 'exists:vehicle_types,id'],
            'car_brand_id' => ['nullable', 'exists:car_brands,id'],
            'car_model_id' => ['nullable', 'exists:car_models,id'],
            'custom_brand' => ['nullable', 'string', 'max:100'],
            'custom_model' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'nickname' => ['nullable', 'string', 'max:50'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'vehicle_type_id.required' => __('Typ pojazdu jest wymagany.'),
            'vehicle_type_id.exists' => __('Wybrany typ pojazdu nie istnieje.'),
            'year.min' => __('Rok produkcji musi być większy niż 1900.'),
            'year.max' => __('Rok produkcji nie może być większy niż :max.', ['max' => date('Y') + 1]),
        ];
    }
}
