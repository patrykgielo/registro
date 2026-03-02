<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->user()->id),
            ],
            'password' => ['required', 'string', 'current_password'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.required' => __('Adres email jest wymagany.'),
            'email.email' => __('Podaj prawidłowy adres email.'),
            'email.unique' => __('Ten adres email jest już używany.'),
            'password.required' => __('Hasło jest wymagane dla potwierdzenia.'),
            'password.current_password' => __('Podane hasło jest nieprawidłowe.'),
        ];
    }
}
