<?php

namespace App\Http\Requests\Okr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * CORRECCIÓN 09-sep-2026 (punto 9 de la auditoría): antes `okr_key_result_id`
 * solo validaba `exists:okr_key_results,id` — un KR de OTRO Objective pasaba
 * la validación igual (cross-objective). Ahora se verifica explícitamente que
 * pertenezca al Objective de la ruta, y que `week_number` esté dentro del
 * rango real del Objective (1..duration_weeks) — nunca semana 0 asignada
 * automáticamente si el Objective aún no inicia.
 */
class StoreEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $objective = $this->route('objective');

        return $objective ? ($this->user()?->can('uploadEvidence', $objective) ?? false) : false;
    }

    public function rules(): array
    {
        $objective = $this->route('objective');

        return [
            'file'              => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,pdf,jpg,jpeg,png,csv,docx'],
            'okr_key_result_id' => ['nullable', 'integer', 'exists:okr_key_results,id'],
            'week_number'       => ['nullable', 'integer', 'min:1', 'max:' . ($objective?->duration_weeks ?? 104)],
            'comment'           => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $objective = $this->route('objective');
            if (!$objective || !$this->filled('okr_key_result_id')) {
                return;
            }

            $belongs = $objective->keyResults()->where('id', $this->integer('okr_key_result_id'))->exists();
            if (!$belongs) {
                $validator->errors()->add('okr_key_result_id', 'Ese Key Result no pertenece a este Objective.');
            }
        });
    }
}
