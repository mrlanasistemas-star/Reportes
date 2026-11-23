<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento de nómina NOI (percepción o deducción de un empleado).
 *
 * Tabla: `fact_noi_movements`. Cada fila es un concepto de nómina de un empleado
 * en un periodo, importado por NoiNominaImportService. Es la base de Nómina y
 * Capital Humano, y de la plantilla del periodo (IMSS/Rotación se derivan de NOI).
 *
 * Columnas clave:
 * - `concept`/`concept_type`: concepto de nómina y su tipo (percepción/deducción).
 * - `amount`/`quantity` (decimal:4): importe y cantidad.
 * - `payroll_type`: tipo de nómina (ej. normal o fiscal).
 * - `movement_date` (date): fecha del movimiento.
 * - `raw_row_hash`/`source_row_key`: huellas para deduplicar al reimportar.
 * - `raw_payload` (array): fila original.
 */
class NoiMovement extends Model
{
    protected $table = 'fact_noi_movements';

    protected $fillable = [
        'period_id',
        'employee_id',
        'report_upload_id',
        'concept',
        'concept_type',
        'amount',
        'quantity',
        'payroll_type',
        'movement_date',
        'raw_row_hash',
        'source_row_key',
        'raw_payload',
    ];

    protected $casts = [
        'amount' => 'decimal:4',
        'quantity' => 'decimal:4',
        'movement_date' => 'date',
        'raw_payload' => 'array',
    ];

    /** Periodo de nómina del movimiento. */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** Empleado al que corresponde el movimiento. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Archivo NOI cargado del que proviene. */
    public function reportUpload(): BelongsTo
    {
        return $this->belongsTo(ReportUpload::class);
    }
}
