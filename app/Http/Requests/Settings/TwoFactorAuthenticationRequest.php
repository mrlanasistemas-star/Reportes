<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Laravel\Fortify\InteractsWithTwoFactorState;

/**
 * Petición de la pantalla de autenticación en dos pasos (Ajustes › Seguridad).
 * No valida campos; su función es aportar el trait de Fortify
 * InteractsWithTwoFactorState para consultar/ajustar el estado 2FA del usuario.
 */
class TwoFactorAuthenticationRequest extends FormRequest
{
    use InteractsWithTwoFactorState;

    /**
     * Sin reglas de validación.
     *
     * @return array<string, ValidationRule|array<mixed>|string> Arreglo vacío.
     */
    public function rules(): array
    {
        return [];
    }
}
