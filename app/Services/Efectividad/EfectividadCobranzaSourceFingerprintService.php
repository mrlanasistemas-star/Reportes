<?php

namespace App\Services\Efectividad;

use App\Models\Period;
use App\Models\Portfolio;
use App\Models\Recovery;
use Illuminate\Support\Facades\DB;

/**
 * Fase 9 (auditoría 2026-10-12) — huella barata de las fuentes reales usadas
 * por un cálculo de Efectividad Aprobada, para poder decidir en LECTURA si un
 * `EfectividadCobranzaRun` ya generado quedó obsoleto (se reimportó/reprocesó
 * algo) sin tener que interceptar cada punto del código que modifica
 * `fact_portfolios`/`fact_recoveries` (importadores, reprocesos, comandos de
 * reparación, etc. — una lista que crecería y se desincronizaría con el
 * tiempo). Se recalcula y se compara; nunca se "marca" desde otro lugar.
 *
 * CARTERA es un snapshot del periodo exacto (fact_portfolios.period_id).
 * INGRESOS es histórico completo (fact_recoveries global, sin period_id) —
 * igual que el propio adaptador (ver EfectividadCobranzaAprobadaDataAdapter).
 */
class EfectividadCobranzaSourceFingerprintService
{
    public function compute(Period $period, ?int $branchId): string
    {
        $carteraQuery = Portfolio::query()->where('period_id', $period->id);
        if ($branchId) {
            $carteraQuery->where('branch_id', $branchId);
        }
        $cartera = $carteraQuery->reorder()->select([
            DB::raw('COUNT(*) as c'),
            DB::raw('COALESCE(MAX(id), 0) as max_id'),
            DB::raw('COALESCE(MAX(updated_at), "1970-01-01") as max_updated'),
        ])->first();

        $ingresosQuery = Recovery::query();
        if ($branchId) {
            $ingresosQuery->where('branch_id', $branchId);
        }
        $ingresos = $ingresosQuery->reorder()->select([
            DB::raw('COUNT(*) as c'),
            DB::raw('COALESCE(MAX(id), 0) as max_id'),
            DB::raw('COALESCE(MAX(updated_at), "1970-01-01") as max_updated'),
        ])->first();

        $raw = sprintf(
            'cartera:%d:%d:%s|ingresos:%d:%d:%s',
            $cartera->c ?? 0,
            $cartera->max_id ?? 0,
            $cartera->max_updated ?? '',
            $ingresos->c ?? 0,
            $ingresos->max_id ?? 0,
            $ingresos->max_updated ?? '',
        );

        return hash('sha256', $raw);
    }

    public function isStale(Period $period, ?int $branchId, ?string $storedFingerprint): bool
    {
        if ($storedFingerprint === null) {
            return true;
        }

        return $this->compute($period, $branchId) !== $storedFingerprint;
    }
}
