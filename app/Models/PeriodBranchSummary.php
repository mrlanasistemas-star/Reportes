<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Resumen de radiografía por sucursal de un periodo.
 *
 * Tabla: `period_branch_summaries`. Cada fila guarda, para un resumen de periodo
 * (`period_summaries`), las métricas calculadas de UNA sucursal (`branch_id`; puede
 * ser null si la sucursal se eliminó). Lo consume el reporte mensual
 * (MonthlyReportController) y lo limpia PeriodDerivedDataCleaner al reprocesar.
 *
 * Columnas clave:
 * - `metrics` (array/JSON): KPIs calculados de la sucursal (colocación, cartera, mora, gastos, etc.).
 */
class PeriodBranchSummary extends Model
{
    protected $fillable = ['period_summary_id', 'branch_id', 'metrics'];

    protected $casts = ['metrics' => 'array'];

    /** Resumen de periodo (`period_summaries`) al que pertenece este desglose por sucursal. */
    public function summary()
    {
        return $this->belongsTo(PeriodSummary::class, 'period_summary_id');
    }
}
