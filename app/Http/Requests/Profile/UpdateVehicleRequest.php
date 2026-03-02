<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'vehicle_type_id' => ['sometimes', 'exists:vehicle_types,id'],
            'car_brand_id' => ['nullable', 'exists:car_brands,id'],
            'car_model_id' => ['nullable', 'exists:car_models,id'],
            'custom_brand' => ['nullable', 'string', 'max:100'],
            'custom_model' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'nickname' => ['nullable', 'string', 'max:50'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
