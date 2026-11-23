<?php

namespace App\Models\EstadoResultados;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de nómina propia del Estado de Resultados (tabla `estado_resultados_payroll_lines`).
 *
 * Cada fila es un concepto (percepción o deducción) de un empleado dentro de una carga de NOI
 * de corporativo (`upload_id`). Columnas clave: `source_row` (fila del archivo),
 * `employee_code`, `employee_name`, `name_key` (nombre normalizado para cruzar con banco),
 * `concept`, `concept_type` (percepcion | deduccion | null) y `amount`.
 * No se mezcla con la nómina de Radiografía (fact_noi_movements).
 */
class EstadoResultadosPayrollLine extends Model
{
    protected $table = 'estado_resultados_payroll_lines';

    protected $guarded = ['id'];

    protected $casts = [
        'source_row' => 'integer',
        'amount' => 'decimal:2',
    ];

    /**
     * Carga de nómina a la que pertenece el renglón.
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(EstadoResultadosPayrollUpload::class, 'upload_id');
    }
}
