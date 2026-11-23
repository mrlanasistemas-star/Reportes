<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Resumen corporativo (consolidado de toda la empresa) de un periodo.
 *
 * Tabla: `period_corporate_summaries`. Una fila por resumen de periodo con las
 * métricas consolidadas a nivel corporativo.
 *
 * Columnas clave:
 * - `metrics` (array/JSON): KPIs consolidados del periodo.
 */
class PeriodCorporateSummary extends Model
{
    protected $fillable = ['period_summary_id', 'metrics'];

    protected $casts = ['metrics' => 'array'];

    /** Resumen de periodo (`period_summaries`) al que pertenece este consolidado. */
    public function summary()
    {
        return $this->belongsTo(PeriodSummary::class, 'period_summary_id');
    }
}
