<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Resumen mensual por colaborador (nómina y gastos consolidados).
 *
 * Tabla: `fact_period_employee_summary`. Cada fila consolida, para un periodo,
 * lo pagado a un colaborador y sus gastos atribuidos, con la sucursal en la que se
 * contabiliza y si entra o no al reporte.
 *
 * Columnas clave (todas decimal:2): `total_payments`, `total_bonuses`,
 * `total_discounts`, `total_expenses`, `net_amount`.
 * - `has_useful_movement` (bool): si tuvo algún movimiento relevante.
 * - `included_in_report` (bool) / `exclusion_reason`: si entra al reporte y, si no, por qué.
 */
class MonthlyEmployeeSummary extends Model
{
    protected $table = 'fact_period_employee_summary';

    protected $fillable = [
        'period_id',
        'employee_id',
        'branch_id',
        'total_payments',
        'total_bonuses',
        'total_discounts',
        'total_expenses',
        'net_amount',
        'has_useful_movement',
        'included_in_report',
        'exclusion_reason',
    ];

    protected $casts = [
        'total_payments' => 'decimal:2',
        'total_bonuses' => 'decimal:2',
        'total_discounts' => 'decimal:2',
        'total_expenses' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'has_useful_movement' => 'boolean',
        'included_in_report' => 'boolean',
    ];

    /** Periodo del resumen. */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** Colaborador resumido. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Sucursal donde se contabiliza al colaborador en el periodo. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
