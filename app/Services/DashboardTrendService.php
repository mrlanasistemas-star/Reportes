<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Period;
use App\Models\PeriodSummary;
use Illuminate\Database\Eloquent\Collection;
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

    /** Meses que muestra la tendencia. El precalentado automático cubre al menos estos. */
    public const PERIODS_COUNT = 6;

    private const CACHE_VERSION = 'v2';

    /**
     * @param  RadiografiaExportService  $exportService  Fuente del snapshot (buildSnapshot) del que salen EBITDA/OPEX.
     */
    public function __construct(private readonly RadiografiaExportService $exportService) {}

    /**
     * Periodos mensuales reales (nunca de prueba/migración) con radiografía generada, más reciente primero.
     *
     * Filtra type = 'monthly', año ≤ año actual y con algún PeriodSummary 'generated'. Solo lectura.
     *
     * @return Collection<int, Period>
     */
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
     * Toma los últimos 6 periodos en orden cronológico. Efectos: lee/escribe la caché
     * (un registro por punto, TTL 12 h) y, si falta, calcula el snapshot completo.
     *
     * @param  int|null  $branchId  Sucursal a graficar; null = consolidado general.
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
                return ['label' => $p->shortLabel(), 'period_id' => $p->id, 'ebitda' => null, 'opex' => null, 'available' => false];
            }

            return Cache::remember($this->pointKey($p->id, $version, $branchId), self::POINT_TTL, fn () => $this->point($p, $branchId));
        })->values()->all();
    }

    /**
     * Nombre legible del alcance para títulos/notas ("Todas las sucursales" o la sucursal).
     *
     * @param  int|null  $branchId  Sucursal elegida o null para el consolidado.
     * @return string Nombre de la sucursal, "Sucursal" si no existe, o "Todas las sucursales".
     */
    public function scopeLabel(?int $branchId): string
    {
        if (! $branchId) {
            return 'Todas las sucursales';
        }

        return (string) (Branch::query()->whereKey($branchId)->value('name') ?? 'Sucursal');
    }

    /**
     * Calcula un punto de la tendencia (sin caché) a partir del snapshot del periodo.
     *
     * Con sucursal usa scope=branch; si la sucursal no estuvo disponible ese periodo
     * devuelve available=false con valores null. Redondea a 2 decimales igual que
     * los KPI del dashboard.
     *
     * @param  Period  $p  Periodo del punto.
     * @param  int|null  $branchId  Sucursal o null para el consolidado.
     * @return array{label: string, period_id: int, ebitda: ?float, opex: ?float, available: bool}
     */
    private function point(Period $p, ?int $branchId): array
    {
        $config = $branchId ? ['scope' => 'branch', 'branch_id' => $branchId] : [];
        $s = $this->exportService->buildSnapshot($p, $config);

        if ($branchId && ! ($s['scope']['available'] ?? true)) {
            return ['label' => $p->shortLabel(), 'period_id' => $p->id, 'ebitda' => null, 'opex' => null, 'available' => false];
        }

        // MISMO redondeo que los KPI de DashboardController::payloadForPeriod().
        return [
            'label' => $p->shortLabel(),
            'period_id' => $p->id,
            'ebitda' => round((float) ($s['summary']['ebitda_final'] ?? 0), 2),
            'opex' => round((float) ($s['summary']['opex_total'] ?? 0), 2),
            'available' => true,
        ];
    }

    /**
     * Versión del resumen VIGENTE de cada periodo (mismo criterio que
     * RadiografiaExportService::requireSummary(): generado, no invalidado, el más
     * reciente) — "id@updated_at".
     *
     * Si un periodo tiene varios resúmenes vigentes gana el de id mayor (orderBy id +
     * mapWithKeys sobrescribe). Solo lectura.
     *
     * @param  int[]  $periodIds  Periodos a consultar.
     * @return array<int, string> period_id => "id@timestamp"
     */
    private function summaryVersions(array $periodIds): array
    {
        return PeriodSummary::query()
            ->whereIn('period_id', $periodIds)
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->orderBy('id')
            ->get(['id', 'period_id', 'updated_at'])
            ->mapWithKeys(fn (PeriodSummary $s) => [$s->period_id => $s->id.'@'.($s->updated_at?->timestamp ?? 0)])
            ->all();
    }

    /**
     * Arma la clave de caché de un punto: versión de caché + periodo + versión del
     * resumen + alcance. Al regenerar el periodo cambia la versión y la clave se invalida sola.
     *
     * @param  int  $periodId  Periodo.
     * @param  string  $version  Versión del resumen ("id@timestamp").
     * @param  int|null  $branchId  Sucursal o null (general).
     * @return string Clave de caché.
     */
    private function pointKey(int $periodId, string $version, ?int $branchId): string
    {
        return sprintf('dashboard_trend_point:%s:%d:%s:%s', self::CACHE_VERSION, $periodId, $version, $branchId ? 'branch-'.$branchId : 'general');
    }
}
