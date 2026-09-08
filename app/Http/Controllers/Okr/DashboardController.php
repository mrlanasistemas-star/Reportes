<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Okr\Concerns\ResolvesOperativeBranches;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\Period;
use App\Models\User;
use App\Services\Okr\OkrProgressCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Módulo OKR — dashboard general. Reconstruido 10-sep-2026 para reproducir
 * 1:1 docs/imagenesOKR/1.png y 2.png (nunca una composición inventada — ver
 * la auditoría de esa fecha, punto 4/5). Cards ejecutivas + listado, con
 * filtros combinados — sin N+1 (eager load de relaciones).
 *
 * CORRECCIÓN 08-sep-2026 (bugs D/E): antes el backend solo soportaba
 * branch_id/employee_id/status/search, y algunas cards (no cumplidos,
 * colaboradores con OKR) salían de queries GLOBALES en vez del alcance
 * filtrado. Ahora TODOS los filtros reales se aplican en un único builder
 * (applyDashboardFilters()) reutilizado tanto por la tabla como por las
 * cards — nunca dos fuentes de verdad.
 */
class DashboardController extends Controller
{
    use ResolvesOperativeBranches, AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('okr.view');

        // Un único conjunto filtrado (mismos filtros para tabla Y cards).
        // Incluye cerrados: el card "no cumplidos" (final_status) SOLO existe
        // en Objectives cerrados, y debe respetar los mismos filtros.
        $filtered = $this->applyDashboardFilters($this->baseQuery(), $request)->orderByDesc('id')->get();

        // La tabla y la mayoría de cards muestran el tablero VIGENTE (excluye
        // cerrados) — derivado del mismo conjunto filtrado.
        $openObjectives = $filtered->where('lifecycle_status', '!=', OkrObjective::STATUS_CLOSED);

        $activeCount      = $openObjectives->where('lifecycle_status', OkrObjective::STATUS_ACTIVE)->count();
        $riskCount        = $openObjectives->whereIn('health_status', [OkrObjective::HEALTH_RISK, OkrObjective::HEALTH_OFF_TRACK])->count();
        $notMetCount      = $filtered->where('final_status', OkrObjective::FINAL_NOT_COMPLETED)->count();
        $avgCompliance    = $openObjectives->isNotEmpty()
            ? round($openObjectives->avg(fn ($o) => $this->objectiveCompliance($o)), 2)
            : 0.0;
        $branchesWithOkr  = $openObjectives->pluck('branch_id')->filter()->unique()->count();
        $employeesWithOkr = $openObjectives->where('scope_type', OkrObjective::SCOPE_EMPLOYEE)->pluck('employee_id')->filter()->unique()->count();

        // Punto 14 de la auditoría 09-sep-2026 — distingue "sistema vacío" de
        // "filtros sin resultados": consulta GLOBAL (ignora los filtros).
        $hasAnyObjectives = OkrObjective::query()->exists();

        $activeOnly = $openObjectives->where('lifecycle_status', OkrObjective::STATUS_ACTIVE)->values();

        return Inertia::render('Okr/Dashboard', [
            'objectives' => $openObjectives->map(fn ($o) => $this->toCard($o))->values(),
            'has_any_objectives' => $hasAnyObjectives,
            'cards' => [
                'active'            => $activeCount,
                'risk'              => $riskCount,
                'not_met'           => $notMetCount,
                'avg_compliance'    => $avgCompliance,
                'branches_with_okr' => $branchesWithOkr,
                'employees_with_okr'=> $employeesWithOkr,
                'total_branches'    => count($this->operativeBranchNames()),
                'total_employees'   => Employee::query()->where('is_active', true)->count(),
            ],
            // "Cumplimiento general" (docs/imagenesOKR/1.png y 2.png) — donut
            // por semáforo de los OKR activos del alcance filtrado.
            'compliance_breakdown' => $this->complianceBreakdown($activeOnly),
            // "Riesgo y proyección" — mismos OKR activos, ordenados por
            // severidad de semáforo, con proyección de cierre ponderada.
            'risk_projection' => $this->riskProjection($activeOnly),
            'filters' => [
                'branches'      => Branch::whereIn('name', $this->operativeBranchNames())->orderBy('name')->get(['id', 'name']),
                'statuses'      => [OkrObjective::STATUS_DRAFT, OkrObjective::STATUS_ACTIVE, OkrObjective::STATUS_CLOSED, OkrObjective::STATUS_CANCELLED],
                'kpis'          => OkrKpi::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
                'responsibles'  => User::query()->orderBy('name')->get(['id', 'name']),
                'periods'       => Period::query()->where('type', 'monthly')->orderByDesc('id')->limit(24)->get()->map(fn ($p) => ['id' => $p->id, 'label' => $p->label]),
                // Colaborador ya NO se precarga completo — se busca bajo
                // demanda vía GET /okr/employees-lookup.
            ],
            'query' => $request->only(['branch_id', 'employee_id', 'status', 'search', 'period_id', 'start_date', 'end_date', 'kpi_id', 'responsible_user_id']),
            // "Asignar OKR" (docs/imagenesOKR/3-8.png) — Dialog embebido aquí,
            // ya NO una página aparte (ver auditoría 10-sep-2026, sección 6).
            'wizardBranches'    => Branch::whereIn('name', $this->operativeBranchNames())->orderBy('name')->get(['id', 'name']),
            'wizardKpis'        => OkrKpi::query()->where('is_active', true)->orderBy('name')->get(),
            'wizardCurrentUser' => ['id' => auth()->id(), 'full_name' => auth()->user()->name],
            // Normalizado a {id, full_name} — bug corregido punto 12 de la
            // auditoría (antes viajaba {id, name} y colisionaba con
            // label-key="full_name" del selector de responsables).
            'wizardUsers'       => User::query()->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['id' => $u->id, 'full_name' => $u->name])->values(),
        ]);
    }

    private function baseQuery(): Builder
    {
        // NO excluye 'closed' aquí — eso se resuelve después (ver $openObjectives
        // en index()), porque el card "no cumplidos" SÍ necesita ver cerrados
        // dentro del mismo conjunto filtrado.
        return OkrObjective::query()
            ->with(['branch:id,name', 'employee:id,full_name', 'responsibleUser:id,name', 'keyResults.kpi:id,name,code,unit'])
            ->whereNull('okr_objectives.deleted_at');
    }

    /**
     * Único punto que traduce filtros de request → condiciones de query.
     * Reutilizado por index() para tabla Y cards — nunca dos builders
     * distintos que puedan divergir.
     */
    private function applyDashboardFilters(Builder $query, Request $request): Builder
    {
        if ($branchId = $request->integer('branch_id')) {
            $query->where(fn ($q) => $q->where('branch_id', $branchId)->orWhereHas('parent', fn ($p) => $p->where('branch_id', $branchId)));
        }
        if ($employeeId = $request->integer('employee_id')) {
            $query->where('employee_id', $employeeId);
        }
        if ($status = $request->string('status')->toString()) {
            $query->where('lifecycle_status', $status);
        }
        if ($search = $request->string('search')->toString()) {
            $query->where('title', 'like', "%{$search}%");
        }
        if ($responsibleId = $request->integer('responsible_user_id')) {
            $query->where('responsible_user_id', $responsibleId);
        }
        if ($kpiId = $request->integer('kpi_id')) {
            $query->whereHas('keyResults', fn ($k) => $k->where('kpi_id', $kpiId));
        }
        if ($request->filled('start_date')) {
            $start = $this->parseDate($request->string('start_date')->toString());
            if ($start) {
                $query->whereDate('start_date', '>=', $start->toDateString());
            }
        }
        if ($request->filled('end_date')) {
            $end = $this->parseDate($request->string('end_date')->toString());
            if ($end) {
                $query->whereDate('end_date', '<=', $end->toDateString());
            }
        }
        if ($periodId = $request->integer('period_id')) {
            $period = Period::find($periodId);
            if ($period && $period->start_date && $period->end_date) {
                // El Objective "aplica" a ese periodo si su rango de fechas se
                // superpone con el rango del periodo financiero.
                $query->where('start_date', '<=', $period->end_date)->where('end_date', '>=', $period->start_date);
            }
        }

        return $query;
    }

    private function parseDate(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function objectiveCompliance(OkrObjective $objective): float
    {
        $calc = app(OkrProgressCalculator::class);
        $krs = $objective->keyResults->map(fn ($kr) => ['raw_progress' => $kr->actual_progress_percentage, 'weight' => (float) $kr->weight])->all();

        return $calc->objectiveCompliance($krs);
    }

    /** Reutiliza la MISMA fórmula canónica (Σ cumplimiento×peso) pero con el valor PROYECTADO de cada KR. */
    private function objectiveProjectedCompliance(OkrObjective $objective): ?float
    {
        $krs = $objective->keyResults->map(fn ($kr) => ['raw_progress' => $kr->projected_compliance_percentage, 'weight' => (float) $kr->weight])->all();
        if (empty(array_filter($krs, fn ($kr) => $kr['raw_progress'] !== null))) {
            return null;
        }

        return app(OkrProgressCalculator::class)->objectiveCompliance($krs);
    }

    /**
     * "Cumplimiento general" (docs/imagenesOKR/1.png y 2.png) — 4 buckets que
     * corresponden 1:1 al semáforo YA existente (OkrHealthService), nunca una
     * clasificación nueva: ahead→Cumplidos, on_track→En trayectoria,
     * risk→En riesgo, off_track→No cumplidos.
     */
    private function complianceBreakdown($activeObjectives): array
    {
        $total = $activeObjectives->count();
        $counts = [
            'completed'  => $activeObjectives->where('health_status', OkrObjective::HEALTH_AHEAD)->count(),
            'on_track'   => $activeObjectives->where('health_status', OkrObjective::HEALTH_ON_TRACK)->count(),
            'at_risk'    => $activeObjectives->where('health_status', OkrObjective::HEALTH_RISK)->count(),
            'off_track'  => $activeObjectives->where('health_status', OkrObjective::HEALTH_OFF_TRACK)->count(),
        ];

        $pct = fn (int $n) => $total > 0 ? round(($n / $total) * 100, 0) : 0;

        return [
            'total' => $total,
            'completed_pct'  => $pct($counts['completed']),
            'on_track_pct'   => $pct($counts['on_track']),
            'at_risk_pct'    => $pct($counts['at_risk']),
            'off_track_pct'  => $pct($counts['off_track']),
            'completed'  => $counts['completed'],
            'on_track'   => $counts['on_track'],
            'at_risk'    => $counts['at_risk'],
            'off_track'  => $counts['off_track'],
        ];
    }

    /** "Riesgo y proyección" (docs/imagenesOKR/1.png y 2.png) — severidad primero. */
    private function riskProjection($activeObjectives): array
    {
        $healthOrder = [OkrObjective::HEALTH_OFF_TRACK => 0, OkrObjective::HEALTH_RISK => 1, OkrObjective::HEALTH_ON_TRACK => 2, OkrObjective::HEALTH_AHEAD => 3];

        return $activeObjectives
            ->sortBy(fn ($o) => $healthOrder[$o->health_status] ?? 4)
            ->values()
            ->map(fn ($o) => [
                'id' => $o->id,
                'label' => $o->branch?->name ?? $o->employee?->full_name ?? $o->title,
                'health_status' => $o->health_status,
                'projected_compliance' => $this->objectiveProjectedCompliance($o),
            ])
            ->take(8)
            ->values()
            ->all();
    }

    private function toCard(OkrObjective $o): array
    {
        return [
            'id'               => $o->id,
            'title'            => $o->title,
            'scope_type'       => $o->scope_type,
            'branch'           => $o->branch?->name,
            'employee'         => $o->employee?->full_name,
            'responsible'      => $o->responsibleUser?->name,
            'lifecycle_status' => $o->lifecycle_status,
            'health_status'    => $o->health_status,
            'start_date'       => $o->start_date?->toDateString(),
            'end_date'         => $o->end_date?->toDateString(),
            'duration_weeks'   => $o->duration_weeks,
            'current_week'     => $o->currentWeekNumber(),
            'compliance'       => $this->objectiveCompliance($o),
            'key_results_count'=> $o->keyResults->count(),
            // "Objetivos y Key Results" (docs/imagenesOKR/1.png y 2.png) —
            // columnas Key Results / KPIs relacionados, listadas explícitas.
            'key_results' => $o->keyResults->map(fn ($kr) => $kr->description)->all(),
            'kpis' => $o->keyResults->map(fn ($kr) => $kr->kpi->name)->unique()->values()->all(),
        ];
    }
}
