<?php

namespace App\Http\Requests\Okr;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parte 6/12 del cierre (04-oct-2026) — carga semanal de colocación. Mismo
 * candado de lifecycle que evidencias/check-ins: solo un Objective ACTIVE
 * acepta cargas nuevas (closed/cancelled quedan de solo lectura, Parte 12).
 */
class StorePlacementUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $objective = $this->route('objective');

        return $objective ? ($this->user()?->can('update', $objective) ?? false) : false;
    }

    public function rules(): array
    {
        $objective = $this->route('objective');

        return [
            'file'         => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv'],
            'week_number'  => ['required', 'integer', 'min:1', 'max:' . ($objective?->duration_weeks ?? 104)],
            'confirm_replace' => ['nullable', 'boolean'],
        ];
    }
}
