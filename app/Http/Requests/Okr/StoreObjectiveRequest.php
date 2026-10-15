<?php

namespace App\Http\Requests\Okr;

use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\User;
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
            // D10 del cierre (17-sep-2026): la UI (OkrAssignDialog.vue) permite hasta
            // 500 caracteres (maxlength="500") — el backend validaba max:191 y la
            // columna real era varchar(255) (bug real, ver migración
            // 2026_09_17_000001_widen_title_on_okr_objectives_table). 500 es ahora
            // la fuente de verdad en los tres lugares (UI/validación/columna).
            'title'                => ['required', 'string', 'min:10', 'max:500'],
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
            'individual_objectives.*.title'       => ['required_with:individual_objectives', 'string', 'min:10', 'max:500'],
            // Corrección 14-oct-2026 (defecto 1): cada OKR individual nace con
            // SUS PROPIOS Key Results capturados en el asistente (KPI, línea
            // base individual, meta individual, peso) — antes nacía vacío y el
            // gestor quedaba sin meta. Nunca se copian los de la sucursal.
            // save_incomplete (15-oct-2026): el usuario decide A PROPÓSITO guardar el borrador
            // sin terminar las metas de algún gestor — la UI y la activación marcan qué falta.
            'individual_objectives.*.key_results'                         => $this->boolean('save_incomplete') ? ['nullable', 'array'] : ['required_with:individual_objectives', 'array', 'min:1'],
            'save_incomplete'                                             => ['nullable', 'boolean'],
            'individual_objectives.*.key_results.*.kpi_id'                => ['required', 'integer', Rule::exists('okr_kpis', 'id')->where('is_active', true)],
            'individual_objectives.*.key_results.*.description'           => ['required', 'string', 'max:191'],
            'individual_objectives.*.key_results.*.baseline_value'        => ['nullable', 'numeric'],
            'individual_objectives.*.key_results.*.baseline_manual_reason' => ['nullable', 'string', 'max:500'],
            'individual_objectives.*.key_results.*.target_value'          => ['required', 'numeric'],
            'individual_objectives.*.key_results.*.weight'                => ['required', 'numeric', 'min:0.01', 'max:100'],
            'key_results.*.baseline_manual_reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateOperativeBranch($validator);
            $this->validateEmployeeBelongsToBranch($validator);
            $this->validateParent($validator);
            $this->validateAutomaticBaselines($validator);
            $this->validateIndividualObjectives($validator);
            $this->validateIndividualWeights($validator);
            $this->validateResponsibleIsEnabled($validator);
        });
    }

    /**
     * 16-oct-2026 — una asignación NUEVA solo puede hacerse sobre una de las
     * once sucursales operativas del catálogo oficial (nunca una ruta, MATRIZ,
     * REGIÓN Q*, AGUASCALIENTES ni TENANGO). Los OKR históricos no cambian.
     */
    private function validateOperativeBranch(Validator $validator): void
    {
        $branchName = \App\Models\Branch::query()->whereKey($this->integer('branch_id'))->value('name');
        if ($branchName !== null && !\App\Services\Catalog\BranchRouteCatalog::isOkrOperative($branchName)) {
            $validator->errors()->add('branch_id', "{$branchName} no es una de las sucursales operativas para OKR.");
        }
    }

    /**
     * D6 del cierre (17-sep-2026): un responsable "pendiente" (no-admin sin
     * `access_enabled_at`) no puede ni siquiera entrar al módulo OKR (ver
     * EnsureOkrAccessEnabled) — no tiene sentido asignarle un OKR de antemano.
     * Backend REAL, nunca solo el filtro del selector en el wizard.
     */
    private function validateResponsibleIsEnabled(Validator $validator): void
    {
        $responsibleId = $this->input('responsible_user_id');
        if (!$responsibleId) {
            return; // opcional — ObjectiveController::store() cae a auth()->id() si no viene
        }

        $responsible = User::query()->find($responsibleId);
        if (!$responsible) {
            return; // ya reportado por la regla 'exists' de arriba
        }

        if (($responsible->role ?? null) !== 'admin' && $responsible->access_enabled_at === null) {
            $validator->errors()->add('responsible_user_id', 'Ese responsable todavía no tiene acceso habilitado al módulo OKR.');
        }
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

            // Parte 1.1 del cierre (04-oct-2026): un colaborador en baja no es
            // seleccionable para un NUEVO OKR — backend real, nunca solo el
            // filtro del buscador del wizard (que ya oculta inactivos, pero
            // esto cubre una petición directa sin pasar por la UI).
            if (!$resolver->employeeIsActive($employeeId)) {
                $validator->errors()->add("individual_objectives.{$i}.employee_id", 'Ese colaborador está dado de baja — no se puede asignar un nuevo OKR.');

                continue;
            }

            if ($branchId && !$resolver->employeeBelongsToBranch($employeeId, $branchId)) {
                $conflict = $resolver->branchConflictFor($employeeId);
                $validator->errors()->add("individual_objectives.{$i}.employee_id", $conflict !== null
                    ? $conflict['message'] . ' ' . $conflict['detail'] . '. Concilia su sucursal antes de asignarle un OKR.'
                    : 'Ese colaborador no pertenece a la sucursal seleccionada.');

                continue;
            }

            if ($this->hasOverlappingIndividualObjective((int) $employeeId)) {
                $validator->errors()->add("individual_objectives.{$i}.employee_id", 'Ese colaborador ya tiene un OKR individual en borrador o activo que se traslapa con estas fechas.');
            }
        }
    }

    /**
     * Prevención de asignaciones duplicadas (14-oct-2026): mismo colaborador
     * (cualquier id de su identidad canónica) con un OKR individual vigente
     * (borrador/activo) cuyo rango de fechas se traslapa con el nuevo.
     */
    private function hasOverlappingIndividualObjective(int $employeeId): bool
    {
        $start = \Illuminate\Support\Carbon::parse((string) $this->input('start_date'));
        $end = app(\App\Services\Okr\OkrCalendarService::class)->endDate($start, max(1, $this->integer('duration_weeks')));

        return OkrObjective::query()
            ->where('scope_type', OkrObjective::SCOPE_EMPLOYEE)
            ->whereIn('employee_id', app(OkrEmployeeBranchResolver::class)->canonicalIdsFor($employeeId))
            ->whereIn('lifecycle_status', [OkrObjective::STATUS_DRAFT, OkrObjective::STATUS_ACTIVE])
            ->where('start_date', '<=', $end->toDateString())
            ->where('end_date', '>=', $start->toDateString())
            ->exists();
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

        // Parte 1.1 del cierre (04-oct-2026): mismo candado que arriba, para
        // un Objective individual directo (sin pasar por "OKR individuales").
        if (!$resolver->employeeIsActive($employeeId)) {
            $validator->errors()->add('employee_id', 'Ese colaborador está dado de baja — no se puede asignar un nuevo OKR.');

            return;
        }

        if (!$resolver->employeeBelongsToBranch($employeeId, $branchId)) {
            $conflict = $resolver->branchConflictFor($employeeId);
            $validator->errors()->add('employee_id', $conflict !== null
                ? $conflict['message'] . ' ' . $conflict['detail'] . '. Concilia su sucursal antes de asignarle un OKR.'
                : 'El colaborador seleccionado no pertenece a la sucursal indicada.');

            return;
        }

        if ($this->hasOverlappingIndividualObjective($employeeId)) {
            $validator->errors()->add('employee_id', 'Ese colaborador ya tiene un OKR individual en borrador o activo que se traslapa con estas fechas.');
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
        $branchId = $this->integer('branch_id') ?: null;
        $scopeType = (string) $this->input('scope_type');
        $employeeId = $scopeType === OkrObjective::SCOPE_EMPLOYEE ? ($this->integer('employee_id') ?: null) : null;

        foreach ($this->input('key_results', []) as $i => $kr) {
            $this->validateBaselineRow($validator, "key_results.{$i}", $kr, $scopeType, $branchId, $employeeId);
        }

        if ($scopeType !== OkrObjective::SCOPE_BRANCH) {
            return;
        }

        foreach ($this->input('individual_objectives', []) as $j => $row) {
            foreach ($row['key_results'] ?? [] as $i => $kr) {
                $this->validateBaselineRow($validator, "individual_objectives.{$j}.key_results.{$i}", $kr, OkrObjective::SCOPE_EMPLOYEE, $branchId, isset($row['employee_id']) ? (int) $row['employee_id'] : null);
            }
        }
    }

    /**
     * Corrección 14-oct-2026 — línea base manual AUTORIZADA para KPI automático:
     * solo se acepta cuando (a) trae motivo (trazabilidad, queda en bitácora) y
     * (b) Reportería REALMENTE no tiene dato verificable para ESE alcance
     * (gestor o sucursal) en el periodo de inicio. Si el dato existe, se rechaza:
     * nunca se sustituye en silencio una línea base real por una capturada.
     */
    private function validateBaselineRow(Validator $validator, string $prefix, array $kr, string $scopeType, ?int $branchId, ?int $employeeId): void
    {
        $error = app(\App\Services\Okr\OkrBaselineGuard::class)->check($kr, $scopeType, $branchId, $employeeId, (string) $this->input('start_date'));
        if ($error !== null) {
            $validator->errors()->add("{$prefix}.{$error['field']}", $error['message']);
        }
    }
    /** Ponderación de CADA OKR individual = 100% (nunca sumando gestores entre sí). */
    private function validateIndividualWeights(Validator $validator): void
    {
        if ($this->input('scope_type') !== OkrObjective::SCOPE_BRANCH) {
            return;
        }

        foreach ($this->input('individual_objectives', []) as $j => $row) {
            $krs = $row['key_results'] ?? [];
            if (!is_array($krs) || $krs === []) {
                continue; // ya reportado por required_with/min:1
            }
            $total = round(array_sum(array_map(fn ($kr) => (float) ($kr['weight'] ?? 0), $krs)), 2);
            if (abs($total - 100.0) > 0.01) {
                $validator->errors()->add("individual_objectives.{$j}.key_results", "La ponderación de los Key Results de este gestor debe sumar 100% (actual: {$total}%).");
            }
        }
    }
}
