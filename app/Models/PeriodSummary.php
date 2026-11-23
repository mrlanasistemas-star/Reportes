<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Resumen generado de la radiografía financiera de un periodo.
 *
 * Tabla: `period_summaries` (única por `period_id`). Cada fila es el resultado
 * vigente del cálculo del periodo: métricas globales, desglose por sucursal,
 * consolidado corporativo e incidencias detectadas durante la generación.
 *
 * Columnas clave:
 * - `source_upload_ids` (array): IDs de ReportUpload usados como fuente del cálculo.
 * - `status`: estado del resumen (por defecto `generated`).
 * - `global_metrics` (array): KPIs globales del periodo.
 * - `warnings` (array): advertencias producidas al generar.
 * - `version`: contador de versión del resumen.
 * - `generated_at`/`generated_by`: cuándo y qué usuario lo generó.
 * - `invalidated_at`/`invalidated_by`/`invalidated_reason`: invalidación del resumen
 *   (por ejemplo, cuando cambian las fuentes y hay que regenerar).
 */
class PeriodSummary extends Model
{
    protected $fillable = ['period_id', 'source_upload_ids', 'status', 'global_metrics', 'warnings', 'version', 'generated_at', 'generated_by', 'invalidated_at', 'invalidated_by', 'invalidated_reason'];

    protected $casts = ['source_upload_ids' => 'array', 'global_metrics' => 'array', 'warnings' => 'array', 'generated_at' => 'datetime', 'invalidated_at' => 'datetime'];

    /** Periodo al que pertenece el resumen. */
    public function period()
    {
        return $this->belongsTo(Period::class);
    }

    /** Desgloses de métricas por sucursal de este resumen. */
    public function branchSummaries()
    {
        return $this->hasMany(PeriodBranchSummary::class);
    }

    /** Consolidado corporativo de este resumen (uno por resumen). */
    public function corporateSummary()
    {
        return $this->hasOne(PeriodCorporateSummary::class);
    }

    /** Incidencias (advertencias/errores de datos) registradas al generar este resumen. */
    public function incidents()
    {
        return $this->hasMany(PeriodIncident::class);
    }
}
