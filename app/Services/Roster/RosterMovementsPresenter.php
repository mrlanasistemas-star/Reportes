<?php

namespace App\Services\Roster;

use App\Models\Period;
use App\Services\Roster\RosterMovementStatus as S;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Arma la sección laboral de la pantalla "Colaboradores" (18-oct-2026) a
 * partir de period_employee_rosters — la MISMA fuente que Rotación y OKR,
 * nunca un segundo cálculo. Separa hechos confirmados de pendientes de
 * conciliación: una ausencia de nómina nunca se muestra como baja confirmada.
 */
class RosterMovementsPresenter
{
    private ?bool $hasEvidence = null;

    public function __construct(private readonly RosterMovementEvidenceCollector $evidence) {}

    /**
     * @param Collection<int, Period> $monthlyPeriods
     * @param list<int>|null $branchIds Fase 6.4 — alcance por sucursal (null = todas).
     */
    public function forPeriod(Period $period, Collection $monthlyPeriods, ?array $branchIds = null): array
    {
        $prevPeriod = $period->previousMonthly($monthlyPeriods);
        $columns = ['employee_id', 'employee_key', 'nombre_normalizado', 'branch_id', 'nombre_original', 'branch_name', 'source', 'is_active_for_period', 'movement_type', 'reconciliation_status', 'reconciliation_note'];
        if ($this->hasEvidence()) {
            $columns[] = 'movement_evidence';
        }

        $rows = DB::table('period_employee_rosters')->where('period_id', $period->id)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->orderBy('nombre_original')->get($columns);
        $items = $rows->map(fn ($r) => $this->item($r, $period, $prevPeriod))->values();

        $active = $items->where('is_active', true)->values();
        $absent = $items->where('is_active', false)->values();
        $byStatus = fn (Collection $c, array $statuses) => $c->filter(fn ($i) => in_array($i['status'], $statuses, true))->values();

        $prevPlantilla = $prevPeriod
            ? DB::table('period_employee_rosters')->where('period_id', $prevPeriod->id)->where('is_active_for_period', true)
                ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))->count()
            : 0;
        $plantilla = $active->count();
        $dropRatio = $prevPlantilla > 0 ? max(0.0, ($prevPlantilla - $plantilla) / $prevPlantilla) : 0.0;

        $confirmedBajas = $absent->filter(fn ($i) => S::isConfirmedBaja($i['status'], $i['movement_type']))->values();
        $pending = $items->filter(fn ($i) => $i['requires_review'])->values();

        $directory = $this->evidence->directoryEvidenceFor($period);

        return [
            'roster' => [
                'calculado' => $rows->isNotEmpty(),
                'periodo' => $period->label,
                'periodo_rango' => $period->start_date && $period->end_date
                    ? $period->start_date->format('d/m/Y').' – '.$period->end_date->format('d/m/Y') : null,
                'plantilla' => $plantilla,
                // Plantilla laboral (personas), nunca registros de asignación.
                'con_sucursal' => $active->filter(fn ($i) => $i['branch_id'] !== null)->count(),
                'sin_sucursal' => $active->filter(fn ($i) => $i['branch_id'] === null)->count(),
                'directorio' => [
                    'vigente' => $directory['valid']['label'] ?? null,
                    'tipo' => $directory['valid']['kind'] ?? null,
                    'posterior' => $directory['later']['label'] ?? null,
                ],
                'periodo_anterior' => $prevPeriod?->label,
                'plantilla_anterior' => $prevPlantilla,
                'drop_ratio' => round($dropRatio, 4),
                'mass_drop_detected' => $prevPlantilla >= 10 && $dropRatio >= 0.20,
                'fuentes' => [
                    'ambos' => $active->where('source', 'ambos')->count(),
                    'solo_normal' => $active->where('source', 'noi_nomina')->count(),
                    'solo_fiscal' => $active->where('source', 'noi_nomina_fiscal')->count(),
                ],
                'sin_clasificar' => $items->where('status', S::SIN_CLASIFICAR)->count(),
            ],
            'active' => $active,
            'movements' => [
                'altas' => $byStatus($active, [S::ALTA_CONFIRMADA]),
                'reingresos' => $byStatus($active, [S::REINGRESO]),
                'bajas' => $confirmedBajas,
                'cambios_sucursal' => $byStatus($active, [S::CAMBIO_SUCURSAL]),
                'retornos' => $byStatus($items, [S::RETORNO_TRAS_AUSENCIA, S::AUSENCIA_JUSTIFICADA]),
            ],
            'pending' => $pending,
            'counts' => [
                // Cada cifra cuenta exactamente lo que dice su etiqueta.
                'altas' => $items->where('movement_type', S::MOVEMENT_ALTA)->count(),
                'altas_confirmadas' => $items->where('status', S::ALTA_CONFIRMADA)->count(),
                'reingresos' => $items->where('status', S::REINGRESO)->count(),
                'cambios_sucursal' => $items->where('status', S::CAMBIO_SUCURSAL)->count(),
                'posibles_bajas' => $absent->where('status', S::POSIBLE_BAJA)->count(),
                'ausencias_temporales' => $absent->whereIn('status', [S::NO_LOCALIZADO, S::AUSENCIA_JUSTIFICADA])->count(),
                'contradicciones' => $items->where('status', S::CONTRADICCION_FUENTES)->count(),
                'duplicados' => $items->where('status', S::REGISTRO_DUPLICADO)->count(),
                'bajas_confirmadas' => $confirmedBajas->count(),
                'pendientes' => $pending->count(),
                'ausencias_pendientes' => $pending->where('is_active', false)->count(),
                'sin_actividad_comercial' => $items->where('status', S::SIN_ACTIVIDAD_COMERCIAL)->count(),
            ],
        ];
    }

    /**
     * Histórico laboral: plantilla y movimientos por periodo mensual (los
     * periodos anteriores siguen visibles tal como se calcularon).
     *
     * @param  Collection<int, Period>  $monthlyPeriods
     * @param  list<int>|null  $branchIds  Fase 6.4 — alcance por sucursal (null = todas).
     */
    public function history(Collection $monthlyPeriods, ?array $branchIds = null): array
    {
        $ids = $monthlyPeriods->pluck('id')->all();
        if ($ids === []) {
            return [];
        }

        $rows = DB::table('period_employee_rosters')
            ->whereIn('period_id', $ids)
            ->when($branchIds !== null, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->get(['period_id', 'is_active_for_period', 'movement_type', 'reconciliation_status'])
            ->groupBy('period_id');

        return $monthlyPeriods->map(function (Period $p) use ($rows) {
            $group = $rows->get($p->id, collect());
            $resolved = $group->map(fn ($r) => [
                'active' => (bool) $r->is_active_for_period,
                'movement' => $r->movement_type,
                'status' => S::resolve($r->reconciliation_status, $r->movement_type, (bool) $r->is_active_for_period),
            ]);

            return [
                'period_id' => $p->id,
                'label' => $p->label,
                'calculado' => $group->isNotEmpty(),
                'plantilla' => $resolved->where('active', true)->count(),
                'altas' => $resolved->where('movement', S::MOVEMENT_ALTA)->count(),
                'bajas_confirmadas' => $resolved->filter(fn ($r) => S::isConfirmedBaja($r['status'], $r['movement']))->count(),
                'pendientes' => $resolved->filter(fn ($r) => S::isPending($r['status']))->count(),
            ];
        })->values()->all();
    }

    private function item(object $r, Period $period, ?Period $prevPeriod): array
    {
        $active = (bool) $r->is_active_for_period;
        $status = S::resolve($r->reconciliation_status, $r->movement_type, $active);
        $evidence = isset($r->movement_evidence) && $r->movement_evidence ? json_decode((string) $r->movement_evidence, true) : null;
        $note = $r->reconciliation_note;
        if ($status === S::SIN_CLASIFICAR) {
            $note = 'Este roster se calculó antes de la conciliación de movimientos: reconstruye el periodo para clasificar esta ausencia. Mientras tanto NO se considera baja confirmada.';
        }

        return [
            'id' => (int) $r->employee_id,
            'employee_id' => (int) $r->employee_id,
            'employee_name' => $r->nombre_original,
            'nombre_normalizado' => $r->nombre_normalizado,
            // Llave de identidad para conciliar (= nombre salvo homónimos separados).
            'identity_key' => (string) (($r->employee_key ?? null) ?: $r->nombre_normalizado),
            'branch_id' => $r->branch_id !== null ? (int) $r->branch_id : null,
            'branch_name' => $r->branch_name,
            'source' => $r->source,
            'period_label' => $active ? $period->label : ($prevPeriod?->label ?? $period->label),
            'is_active' => $active,
            'movement_type' => $r->movement_type,
            'status' => $status,
            'status_label' => S::label($status),
            // Compatibilidad con el tipo anterior del frontend.
            'reconciliation_status' => $status,
            'reconciliation_note' => $note,
            'evidence' => is_array($evidence) ? array_values($evidence['items'] ?? []) : [],
            'requires_review' => S::isPending($status),
            'can_review' => ! $active && S::isPending($status),
        ];
    }

    private function hasEvidence(): bool
    {
        return $this->hasEvidence ??= Schema::hasColumn('period_employee_rosters', 'movement_evidence');
    }
}
