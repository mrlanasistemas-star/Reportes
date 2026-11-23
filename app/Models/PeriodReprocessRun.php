<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ejecución de reprocesamiento de las cargas de un periodo.
 *
 * Tabla: `period_reprocess_runs`. Cada fila es un intento de volver a importar
 * los archivos cargados de un periodo, ejecutado en segundo plano por
 * ReprocessPeriodUploadsJob (al terminar o fallar se envía correo).
 *
 * Columnas clave:
 * - `status`: estado de la ejecución (en curso, éxito, fallo).
 * - `log`/`error_message`: bitácora y error, si lo hubo.
 * - `metadata` (array): datos adicionales del proceso.
 * - `started_at`/`finished_at` (datetime): inicio y fin.
 */
class PeriodReprocessRun extends Model
{
    protected $fillable = [
        'period_id',
        'created_by',
        'status',
        'log',
        'error_message',
        'metadata',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metadata' => 'array',
    ];

    /** Periodo que se reprocesa. */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** Usuario que lanzó el reproceso. */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
