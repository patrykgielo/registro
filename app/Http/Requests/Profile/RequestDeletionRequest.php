<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class RequestDeletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password'],
            'confirmation' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => __('Hasło jest wymagane dla potwierdzenia.'),
            'password.current_password' => __('Podane hasło jest nieprawidłowe.'),
            'confirmation.required' => __('Musisz potwierdzić chęć usunięcia konta.'),
            'confirmation.accepted' => __('Musisz potwierdzić chęć usunięcia konta.'),
        ];
    }
}
