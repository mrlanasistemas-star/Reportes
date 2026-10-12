<?php

namespace App\Services\Efectividad\V4;

use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Models\PortfolioSnapshot;
use App\Models\Recovery;
use Illuminate\Support\Facades\DB;

/**
 * Huella de las fuentes que usa un cálculo V4: fotografías activas de cada fecha
 * (id + fecha de importación) y movimientos de Ingresos que pueden afectar el
 * corte (los mismos que lee el adaptador). Si se sustituye una fotografía o se
 * reprocesa/carga un archivo de Ingresos, la huella cambia y el resultado
 * guardado se presenta como obsoleto (no se recalcula en lectura).
 */
class EfectividadV4FingerprintService
{
    public function __construct(private readonly EfectividadV4DataAdapter $adapter) {}

    public function compute(Period $period, ?int $branchId, string $mode, ?string $cutoff): ?string
    {
        try {
            $w = $this->adapter->window($period, $mode, $cutoff);
        } catch (EfectividadV4Exception) {
            return null;
        }
        $hasta = fn (string $d) => $d.' 23:59:59';

        $fotos = PortfolioSnapshot::query()->active()
            ->whereBetween('snapshot_date', [$w['domingo_base'], $hasta($w['fecha_corte'])])
            ->orderBy('id')->get(['id', 'imported_at', 'branch_ids'])
            ->filter(fn (PortfolioSnapshot $s) => $s->coversBranch($branchId))
            ->map(fn ($s) => $s->id.'@'.$s->imported_at?->format('YmdHis'))
            ->implode(',');

        $ing = Recovery::query()->toBase()
            ->where(fn ($q) => $q
                ->whereBetween('payment_date', [$w['inicio'], $hasta($w['fin'])])
                ->orWhere(fn ($q2) => $q2->where('payment_date', '<=', $hasta($w['fin']))->whereBetween('installment_due_date', [$w['inicio'], $hasta($w['fin'])])))
            ->select([
                DB::raw('COUNT(*) as c'),
                DB::raw('COALESCE(MAX(id), 0) as max_id'),
                DB::raw('COALESCE(SUM(report_upload_id), 0) as uploads'),
            ])->first();

        return hash('sha256', sprintf('v4|%s|%s|fotos:%s|ingresos:%d:%d:%d', $w['modo'], $w['fecha_corte'], $fotos, $ing->c ?? 0, $ing->max_id ?? 0, $ing->uploads ?? 0));
    }

    public function isStale(EfectividadCobranzaRun $run): bool
    {
        if (! $run->period || ! $run->source_fingerprint) {
            return true;
        }

        return $this->compute($run->period, $run->branch_id, $run->mode ?? EfectividadCobranzaRun::MODE_SEMANAL, $run->cutoff_date?->format('Y-m-d')) !== $run->source_fingerprint;
    }
}
