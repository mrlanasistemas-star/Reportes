<?php

namespace App\Http\Requests\Okr;

use App\Models\OkrObjective;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Módulo OKR (08-sep-2026) — validación backend REAL (sección 49 del pedido) —
 * nunca se confía solo en Vue.
 */
class StoreObjectiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('okr.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'scope_type'           => ['required', Rule::in([OkrObjective::SCOPE_BRANCH, OkrObjective::SCOPE_EMPLOYEE])],
            'branch_id'            => ['required_if:scope_type,branch', 'nullable', 'integer', 'exists:branches,id'],
            'employee_id'          => ['required_if:scope_type,employee', 'nullable', 'integer', 'exists:employees,id'],
            'parent_id'            => ['nullable', 'integer', 'exists:okr_objectives,id'],
            'title'                => ['required', 'string', 'min:10', 'max:191'],
            'responsible_user_id'  => ['nullable', 'integer', 'exists:users,id'],
            'start_date'           => ['required', 'date'],
            'duration_weeks'       => ['required', 'integer', 'min:1', 'max:104'],
            'key_results'                        => ['required', 'array', 'min:1'],
            'key_results.*.kpi_id'               => ['required', 'integer', Rule::exists('okr_kpis', 'id')->where('is_active', true)],
            'key_results.*.description'          => ['required', 'string', 'max:191'],
            'key_results.*.baseline_value'       => ['nullable', 'numeric'],
            'key_results.*.target_value'         => ['required', 'numeric'],
            'key_results.*.weight'               => ['required', 'numeric', 'min:0.01', 'max:100'],
        ];
    }
}
