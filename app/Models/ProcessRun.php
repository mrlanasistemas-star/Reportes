<?php

namespace App\Models;

use App\Enums\ProcessRunStatus;
use App\Enums\ProcessType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de procesamiento (importación) de un archivo cargado.
 *
 * Tabla: `process_runs`. Cada fila es una ejecución de un proceso sobre un
 * ReportUpload (por ejemplo, importar sus filas a las tablas de hechos), con
 * contadores de filas leídas, insertadas, omitidas y con error.
 *
 * Columnas clave:
 * - `process_type` (enum ProcessType): tipo de proceso ejecutado.
 * - `status` (enum ProcessRunStatus): estado de la ejecución.
 * - `rows_read`/`rows_inserted`/`rows_skipped`/`rows_with_errors` (int): contadores.
 * - `log`: bitácora textual.
 * - `started_at`/`finished_at` (datetime): inicio y fin.
 */
class ProcessRun extends Model
{
    protected $fillable = [
        'period_id',
        'report_upload_id',
        'process_type',
        'status',
        'rows_read',
        'rows_inserted',
        'rows_skipped',
        'rows_with_errors',
        'log',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'status' => ProcessRunStatus::class,
        'process_type' => ProcessType::class,
        'rows_read' => 'integer',
        'rows_inserted' => 'integer',
        'rows_skipped' => 'integer',
        'rows_with_errors' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /** Periodo del archivo procesado. */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** Archivo cargado que se procesó. */
    public function reportUpload(): BelongsTo
    {
        return $this->belongsTo(ReportUpload::class);
    }
}
