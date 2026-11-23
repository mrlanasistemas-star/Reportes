<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la actualización del perfil propio (nombre, correo) desde
 * Ajustes › Perfil, usando las reglas compartidas de ProfileValidationRules.
 */
class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Reglas del perfil; se pasa el id del usuario actual para que la regla
     * de correo único lo ignore a él mismo.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->profileRules($this->user()->id);
    }
}
