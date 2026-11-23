<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ejecución de "Actualizar base de datos" de un periodo.
 *
 * Tabla: `period_database_update_runs`. Cada fila es una corrida en cola de
 * UpdatePeriodDatabaseJob (DatabaseUpdateService), que consolida las cargas del
 * periodo en las tablas de hechos.
 *
 * Columnas clave:
 * - `status`: estado (en cola, `running`, `success`, `failed`).
 * - `log`/`error_message`: bitácora y error, si lo hubo.
 * - `metadata` (array): datos adicionales del proceso.
 * - `queued_at`/`started_at`/`finished_at` (datetime): encolado, inicio y fin.
 */
class PeriodDatabaseUpdateRun extends Model
{
    protected $fillable = [
        'period_id',
        'created_by',
        'status',
        'log',
        'error_message',
        'metadata',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'metadata' => 'array',
    ];

    /** Periodo cuya base se actualiza. */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** Usuario que solicitó la actualización. */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
