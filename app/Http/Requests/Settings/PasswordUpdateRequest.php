<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del cambio de contraseña desde Ajustes › Seguridad: exige la
 * contraseña actual y una nueva que cumpla las reglas de PasswordValidationRules.
 */
class PasswordUpdateRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Reglas: `current_password` (debe coincidir con la actual) y `password`
     * (reglas de fortaleza/confirmación del trait).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ];
    }
}
