<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Period;
use App\Models\PeriodSummary;
use Illuminate\Support\Facades\Cache;

/**
 * Tendencia EBITDA/OPEX del dashboard — extraída de DashboardController (cierre
 * 17-sep-2026, ronda 6) para poder recalentar la caché desde un job en cuanto una
 * radiografía nueva termina de generarse, en vez de que el primer usuario que abre
 * el dashboard tenga que esperar ~6 buildSnapshot() encadenados en su propia
 * request (causa real de "tarda muchísimo"/timeout reportada en producción: PHP
 * puede fatalear por max_execution_time a la mitad de ese cálculo, dejando el
 * fetch del frontend sin JSON válido — ver DashboardController::trend()).
 *
 * Fase 7.1 (15-oct-2026) — la tendencia respeta el ALCANCE elegido: con sucursal,
 * cada punto sale del snapshot de ESA sucursal (mismo buildSnapshot() con
 * scope=branch que alimenta los KPI del Dashboard y Reportería por sucursal), así
 * el último punto coincide siempre con la tarjeta EBITDA/OPEX de arriba. Sin
 * sucursal, consolidado (snapshot general). Caché POR PUNTO: periodo + resumen
 * vigente (id y updated_at) + alcance/sucursal — regenerar un periodo invalida
 * solo sus puntos, nunca deja cifras viejas hasta que venza un TTL.
 */
class DashboardTrendService
{
    private const POINT_TTL = 43_200; // 12 h — la clave ya cambia sola al regenerar el periodo
    private const PERIODS_COUNT = 6;
    private const CACHE_VERSION = 'v2';

    public function __construct(private readonly RadiografiaExportService $exportService)
    {
    }

    /** Periodos mensuales reales (nunca de prueba/migración) con radiografía generada, más reciente primero. */
    public function availablePeriods()
    {
        $generatedPeriodIds = PeriodSummary::query()->where('status', 'generated')->pluck('period_id');

        return Period::query()
            ->where('type', 'monthly')
            ->where('year', '<=', now()->year)
            ->whereIn('id', $generatedPeriodIds)
            ->orderByDesc('year')->orderByDesc('month')
            ->get();
    }

    /**
     * Tendencia de los últimos periodos del sistema para el alcance pedido.
     *
     * Cada punto: label, period_id, ebitda, opex y available (false = la sucursal no
     * tuvo radiografía ese periodo: ebitda/opex null, nunca un 0 inventado).
     *
     * @return array<int, array{label: string, period_id: int, ebitda: ?float, opex: ?float, available: bool}>
     */
    public function build(?int $branchId = null): array
    {
        $periods = $this->availablePeriods();
        if ($periods->isEmpty()) {
            return [];
        }

        $selected = $periods->take(self::PERIODS_COUNT)->reverse()->values();
        $versions = $this->summaryVersions($selected->pluck('id')->all());

        // ->all() / arreglos planos antes de cachear — el driver 'database' serializa
        // con serialize() nativo; un Collection podía volver como __PHP_Incomplete_Class
        // (ver historial de este archivo). Un array plano no tiene esa ambigüedad.
        return $selected->map(function (Period $p) use ($branchId, $versions) {
            $version = $versions[$p->id] ?? null;
            if ($version === null) {
                return ['label' => $p->label, 'period_id' => $p->id, 'ebitda' => null, 'opex' => null, 'available' => false];
            }

            return Cache::remember($this->pointKey($p->id, $version, $branchId), self::POINT_TTL, fn () => $this->point($p, $branchId));
        })->values()->all();
    }

    /** Nombre legible del alcance para títulos/notas ("Todas las sucursales" o la sucursal). */
    public function scopeLabel(?int $branchId): string
    {
        if (!$branchId) {
            return 'Todas las sucursales';
        }

        return (string) (Branch::query()->whereKey($branchId)->value('name') ?? 'Sucursal');
    }

    private function point(Period $p, ?int $branchId): array
    {
        $config = $branchId ? ['scope' => 'branch', 'branch_id' => $branchId] : [];
        $s = $this->exportService->buildSnapshot($p, $config);

        if ($branchId && !($s['scope']['available'] ?? true)) {
            return ['label' => $p->label, 'period_id' => $p->id, 'ebitda' => null, 'opex' => null, 'available' => false];
        }

        // MISMO redondeo que los KPI de DashboardController::payloadForPeriod().
        return [
            'label'     => $p->label,
            'period_id' => $p->id,
            'ebitda'    => round((float) ($s['summary']['ebitda_final'] ?? 0), 2),
            'opex'      => round((float) ($s['summary']['opex_total'] ?? 0), 2),
            'available' => true,
        ];
    }

    /**
     * Versión del resumen VIGENTE de cada periodo (mismo criterio que
     * RadiografiaExportService::requireSummary(): generado, no invalidado, el más
     * reciente) — "id@updated_at".
     *
     * @param  int[]  $periodIds
     * @return array<int, string>
     */
    private function summaryVersions(array $periodIds): array
    {
        return PeriodSummary::query()
            ->whereIn('period_id', $periodIds)
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->orderBy('id')
            ->get(['id', 'period_id', 'updated_at'])
            ->mapWithKeys(fn (PeriodSummary $s) => [$s->period_id => $s->id . '@' . ($s->updated_at?->timestamp ?? 0)])
            ->all();
    }

    private function pointKey(int $periodId, string $version, ?int $branchId): string
    {
        return sprintf('dashboard_trend_point:%s:%d:%s:%s', self::CACHE_VERSION, $periodId, $version, $branchId ? 'branch-' . $branchId : 'general');
    }
}
