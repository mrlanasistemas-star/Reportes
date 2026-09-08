<?php

namespace App\Http\Requests\Okr;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * CORRECCIÓN 09-sep-2026 (punto 1 de la auditoría): el check-in ahora puede
 * traer `manual_results` (resultados de KR manuales/híbridos capturados esa
 * semana) — validados aquí: el KR debe pertenecer al Objective de la ruta, y
 * su KPI NUNCA puede ser automático (ese valor viene solo de Reportería,
 * jamás se sobreescribe a mano).
 */
class StoreCheckInRequest extends FormRequest
{
    public function authorize(): bool
    {
        $objective = $this->route('objective');

        return $objective ? ($this->user()?->can('checkin', $objective) ?? false) : false;
    }

    public function rules(): array
    {
        return [
            'main_blocker'       => ['nullable', 'string', 'max:2000'],
            'corrective_action'  => ['nullable', 'string', 'max:2000'],
            'action_responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action_due_date'            => ['nullable', 'date', 'after_or_equal:today'],
            'manual_results'                    => ['nullable', 'array'],
            'manual_results.*.key_result_id'    => ['required', 'integer'],
            'manual_results.*.value'            => ['required', 'numeric'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $objective = $this->route('objective');
            $results = $this->input('manual_results', []);
            if (!$objective || empty($results)) {
                return;
            }

            $keyResults = $objective->keyResults()->with('kpi')->get()->keyBy('id');

            foreach ($results as $i => $row) {
                $krId = $row['key_result_id'] ?? null;
                $kr = $krId ? ($keyResults[$krId] ?? null) : null;

                if (!$kr) {
                    $validator->errors()->add("manual_results.{$i}.key_result_id", 'Ese Key Result no pertenece a este Objective.');
                    continue;
                }

                if ($kr->kpi->isAutomatic()) {
                    $validator->errors()->add("manual_results.{$i}.key_result_id", "\"{$kr->kpi->name}\" es un KPI automático — su valor viene solo de Reportería, no se puede capturar a mano.");
                }
            }
        });
    }
}
