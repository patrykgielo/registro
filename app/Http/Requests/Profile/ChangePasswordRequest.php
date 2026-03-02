<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'current_password.required' => __('Obecne hasło jest wymagane.'),
            'current_password.current_password' => __('Obecne hasło jest nieprawidłowe.'),
            'password.required' => __('Nowe hasło jest wymagane.'),
            'password.confirmed' => __('Potwierdzenie hasła nie zgadza się.'),
        ];
    }
}
