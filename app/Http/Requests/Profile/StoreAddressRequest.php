<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class StoreAddressRequest extends FormRequest
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
            'address' => ['required', 'string', 'max:500'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'place_id' => ['nullable', 'string', 'max:255'],
            'components' => ['nullable', 'array'],
            'nickname' => ['nullable', 'string', 'max:50'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'address.required' => __('Adres jest wymagany.'),
            'latitude.required' => __('Lokalizacja jest wymagana. Wybierz adres z listy podpowiedzi.'),
            'longitude.required' => __('Lokalizacja jest wymagana. Wybierz adres z listy podpowiedzi.'),
        ];
    }
}
