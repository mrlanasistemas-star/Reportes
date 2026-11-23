<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Historial de exportaciones del Estado de Resultados (tabla `estado_resultados_exports`).
 *
 * Cada fila es una descarga (PDF o Excel) de un periodo y alcance (`scope_key`), con el hash
 * del dataset exportado (`dataset_hash`) para poder demostrar qué cifras se entregaron.
 * Columnas posteriores: `version_number` (versión aprobada exportada, si aplica),
 * `report_status` (estado del reporte al exportar) y `preliminary` (exportación de un reporte
 * aún no aprobado). `format`: pdf | xlsx.
 */
class EstadoResultadosExport extends Model
{
    /** Sin columna updated_at: el historial sólo se inserta. */
    public const UPDATED_AT = null;

    protected $table = 'estado_resultados_exports';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    /**
     * Usuario que generó la exportación.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
