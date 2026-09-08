<?php

namespace App\Http\Requests\Okr;

use App\Models\OkrKpi;
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
            // OKR individuales dentro de la MISMA asignación de sucursal (fix
            // 10-sep-2026, sección 8/9 de la auditoría — la referencia exige
            // sucursal + N individuales en una sola asignación, no un
            // employee_id suelto). Solo aplica cuando scope_type=branch.
            'individual_objectives'               => ['nullable', 'array'],
            'individual_objectives.*.employee_id' => ['required_with:individual_objectives', 'integer', 'exists:employees,id'],
            'individual_objectives.*.title'       => ['required_with:individual_objectives', 'string', 'min:10', 'max:191'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateEmployeeBelongsToBranch($validator);
            $this->validateParent($validator);
            $this->validateAutomaticBaselines($validator);
            $this->validateIndividualObjectives($validator);
        });
    }

    /**
     * OKR individuales opcionales de la misma asignación (sección 8/9 de la
     * auditoría 10-sep-2026): cada colaborador debe pertenecer a la MISMA
     * sucursal que se está asignando (misma fuente que Reportería, nunca
     * confiar en lo que ya venía filtrado en el frontend), y no puede
     * repetirse dentro de la asignación.
     */
    private function validateIndividualObjectives(Validator $validator): void
    {
        $rows = $this->input('individual_objectives', []);
        if (empty($rows) || $this->input('scope_type') !== OkrObjective::SCOPE_BRANCH) {
            return;
        }

        $branchId = $this->integer('branch_id');
        $resolver = app(OkrEmployeeBranchResolver::class);
        $seen = [];

        foreach ($rows as $i => $row) {
            $employeeId = $row['employee_id'] ?? null;
            if (!$employeeId) {
                continue; // ya reportado por 'required_with' de arriba
            }

            if (isset($seen[$employeeId])) {
                $validator->errors()->add("individual_objectives.{$i}.employee_id", 'Ese colaborador ya fue agregado en esta asignación.');

                continue;
            }
            $seen[$employeeId] = true;

            if ($branchId && !$resolver->employeeBelongsToBranch($employeeId, $branchId)) {
                $validator->errors()->add("individual_objectives.{$i}.employee_id", 'Ese colaborador no pertenece a la sucursal seleccionada.');
            }
        }
    }

    private function validateEmployeeBelongsToBranch(Validator $validator): void
    {
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
    }

    /**
     * CORRECCIÓN 09-sep-2026 (punto 12 de la auditoría): un Objective de
     * colaborador que dice "contribuir" a un Objective de sucursal, cuyo
     * parent es de OTRA sucursal, es una contradicción de datos (ej. gestor
     * de Tlaxcala → parent de Córdoba) — 422, nunca se guarda.
     */
    private function validateParent(Validator $validator): void
    {
        $parentId = $this->input('parent_id');
        if (!$parentId) {
            return;
        }

        // find() respeta el global scope de SoftDeletes — un parent ya
        // eliminado no "existe" para este propósito aunque la fila siga en la
        // tabla (a diferencia de la regla `exists` de arriba, que sí la vería).
        $parent = OkrObjective::query()->find($parentId);
        if (!$parent) {
            $validator->errors()->add('parent_id', 'El Objective de sucursal seleccionado ya no está disponible.');

            return;
        }

        if ($parent->scope_type !== OkrObjective::SCOPE_BRANCH) {
            $validator->errors()->add('parent_id', 'Solo un Objective de SUCURSAL puede usarse como padre.');

            return;
        }

        if ($parent->lifecycle_status === OkrObjective::STATUS_CANCELLED) {
            $validator->errors()->add('parent_id', 'Ese Objective de sucursal está cancelado — no puede usarse como padre.');

            return;
        }

        $childBranchId = $this->integer('branch_id');
        if ($childBranchId && (int) $parent->branch_id !== $childBranchId) {
            $validator->errors()->add('parent_id', 'El Objective de sucursal padre debe ser de la MISMA sucursal que este colaborador.');
        }
    }

    /**
     * CORRECCIÓN 09-sep-2026 (punto 3 de la auditoría): un KPI automático
     * nunca acepta una baseline manual en la creación — solo Reportería la
     * fija al activar (ver ObjectiveController::activate()/baselinePreview()).
     */
    private function validateAutomaticBaselines(Validator $validator): void
    {
        foreach ($this->input('key_results', []) as $i => $kr) {
            if (($kr['baseline_value'] ?? null) === null || !isset($kr['kpi_id'])) {
                continue;
            }

            $kpi = OkrKpi::query()->find($kr['kpi_id']);
            if ($kpi && $kpi->isAutomatic()) {
                $validator->errors()->add("key_results.{$i}.baseline_value", 'Este KPI es automático — no se puede capturar una línea base manual, se obtiene sola al activar.');
            }
        }
    }
}
