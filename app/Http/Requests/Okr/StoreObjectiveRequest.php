<?php

namespace App\Http\Requests\Okr;

use App\Models\OkrObjective;
use App\Services\Okr\OkrEmployeeBranchResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Módulo OKR (08-sep-2026) — validación backend REAL (sección 49 del pedido) —
 * nunca se confía solo en Vue.
 *
 * CORRECCIÓN 08-sep-2026 (bug "empleado de otra sucursal"): `branch_id` ahora
 * es obligatorio también para scope_type=employee (contexto operativo del
 * colaborador — necesario para validar pertenencia y para filtrar el
 * buscador de colaboradores en el wizard). Si el `employee_id` enviado no
 * pertenece realmente a esa `branch_id` (según
 * employee_branch_assignments, la MISMA fuente que ya usa Reportería), la
 * petición se rechaza con 422 — nunca se confía en lo que ya venía
 * pre-filtrado en el frontend.
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
            'branch_id'            => ['required', 'integer', 'exists:branches,id'],
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

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->input('scope_type') !== OkrObjective::SCOPE_EMPLOYEE) {
                return;
            }
            $employeeId = $this->integer('employee_id');
            $branchId   = $this->integer('branch_id');
            if (!$employeeId || !$branchId) {
                return; // ya reportado por las reglas 'required' de arriba
            }

            $resolver = app(OkrEmployeeBranchResolver::class);
            if (!$resolver->employeeBelongsToBranch($employeeId, $branchId)) {
                $validator->errors()->add('employee_id', 'El colaborador seleccionado no pertenece a la sucursal indicada.');
            }
        });
    }
}
