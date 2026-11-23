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
    /**
     * Requiere la habilidad `update` sobre el Objective de la ruta (la Policy
     * es la que aplica el candado de lifecycle).
     *
     * @return bool false si no hay Objective en la ruta o no hay permiso.
     */
    public function authorize(): bool
    {
        $objective = $this->route('objective');

        return $objective ? ($this->user()?->can('update', $objective) ?? false) : false;
    }

    /**
     * Reglas: archivo de colocación (xlsx/xls/csv, ≤10 MB), semana obligatoria
     * entre 1 y la duración del Objective (104 si no hay Objective), y banderas
     * opcionales `confirm_replace` (sustituir carga previa de esa semana) y
     * `complete_report` (marcar el reporte como completo).
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $objective = $this->route('objective');

        return [
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv'],
            'week_number' => ['required', 'integer', 'min:1', 'max:'.($objective?->duration_weeks ?? 104)],
            'confirm_replace' => ['nullable', 'boolean'],
            'complete_report' => ['nullable', 'boolean'],
        ];
    }
}
