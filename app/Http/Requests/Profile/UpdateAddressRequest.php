<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('components') && is_string($this->components)) {
            $decoded = json_decode($this->components, true);
            $this->merge([
                'components' => is_array($decoded) ? $decoded : null,
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'address' => ['sometimes', 'string', 'max:500'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'place_id' => ['nullable', 'string', 'max:255'],
            'components' => ['nullable', 'array'],
            'nickname' => ['nullable', 'string', 'max:50'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
