<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Costo IMSS por sucursal y periodo.
 *
 * Tabla: `fact_imss`. Cada fila es el costo IMSS de una sucursal en un periodo,
 * derivado de la nómina NOI fiscal (ImssFromNoiFiscalService) — ya no se sube un
 * archivo IMSS aparte. Lo consumen la radiografía (BranchRadiographyCalculator,
 * RadiographySnapshotBuilder) y el Estado de Resultados.
 *
 * Columnas clave:
 * - `branch_id`/`branch_name`: sucursal (nombre conservado tal como se derivó).
 * - `collaborators_count`: número de colaboradores considerados.
 * - `monthly_fee_per_employee` (float): cuota mensual por colaborador.
 * - `amount` (float): costo IMSS total de la sucursal.
 * - `included_in_report` (bool) / `excluded_reason`: si entra al reporte y, si no, por qué.
 * - `source`: origen del dato.
 */
class FactImss extends Model
{
    protected $table = 'fact_imss';

    protected $fillable = [
        'period_id',
        'branch_id',
        'branch_name',
        'collaborators_count',
        'monthly_fee_per_employee',
        'amount',
        'included_in_report',
        'excluded_reason',
        'source',
    ];

    protected $casts = [
        'included_in_report' => 'boolean',
        'monthly_fee_per_employee' => 'float',
        'amount' => 'float',
    ];

    /** Periodo al que corresponde el costo IMSS. */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }
}
