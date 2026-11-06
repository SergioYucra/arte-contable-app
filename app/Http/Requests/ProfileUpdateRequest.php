<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],
            // V4 (H2): cambiar el correo exige la contraseña actual. Una sesión abierta sola no alcanza
            // para quedarse con la cuenta, porque el correo es lo que recibe "Olvidé mi contraseña".
            'current_password' => [
                Rule::requiredIf(fn (): bool => $this->wantsToChangeEmail()),
                'nullable',
                'string',
                'current_password',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.required' => 'Para cambiar el correo, escribe tu contraseña actual.',
        ];
    }

    public function wantsToChangeEmail(): bool
    {
        return $this->input('email') !== $this->user()->email;
    }
}
