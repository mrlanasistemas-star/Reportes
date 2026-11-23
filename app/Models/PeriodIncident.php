<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Incidencia de datos detectada al actualizar la base o generar la radiografía de un periodo.
 *
 * Tabla: `period_incidents`. Cada fila es un aviso (ej. registro sin sucursal,
 * gestor no identificado) ligado a un resumen de periodo. Las crean
 * DatabaseUpdateService, GenerateRadiographyJob y ReportUploadController, y se
 * muestran al usuario para resolverlas antes del cierre.
 *
 * Columnas clave:
 * - `type`: código de la incidencia (hasta 60 caracteres).
 * - `severity`: gravedad (por defecto `warning`).
 * - `message`: texto legible para el usuario.
 * - `context` (array): datos estructurados para diagnosticar/resolver la incidencia.
 */
class PeriodIncident extends Model
{
    protected $fillable = ['period_summary_id', 'type', 'severity', 'message', 'context'];

    protected $casts = ['context' => 'array'];

    /** Resumen de periodo (`period_summaries`) en el que se registró la incidencia. */
    public function periodSummary(): BelongsTo
    {
        return $this->belongsTo(PeriodSummary::class);
    }
}
