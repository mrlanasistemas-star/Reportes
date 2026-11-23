<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Fila de una fotografía diaria de cartera (Lendus "Saldos por Cliente" con fecha).
 *
 * Tabla: `fact_portfolio_snapshot_rows`. Cada fila es un contrato tal como aparece
 * en el archivo de fotografía de una fecha, SIN filtrar (incluye cerrados, saldos
 * negativos y productos excluidos) porque el motor de Efectividad de Cobranza los
 * necesita para clasificar. Los montos se guardan en DOUBLE para conservar el
 * valor exacto del Excel y mantener paridad con el cálculo de referencia.
 *
 * Columnas clave: `portfolio_snapshot_id`, `report_upload_id`, `snapshot_date`
 * (fecha de la foto), `branch_id`, `source_row` (fila del archivo), `contract`,
 * `promoter_code`/`promoter_name` (gestor), `status`/`substatus`, saldos
 * (`saldo_capital`, `total_overdue`, `current_balance`...), `days_overdue`/`days_past_due`.
 *
 * Casts: fechas `snapshot_date`, `first_payment_date`, `contract_due_date` y
 * `next_payment_date` como `date`. Todo es asignable salvo `id`.
 */
class PortfolioSnapshotRow extends Model
{
    protected $table = 'fact_portfolio_snapshot_rows';

    protected $guarded = ['id'];

    protected $casts = [
        'snapshot_date' => 'date',
        'first_payment_date' => 'date',
        'contract_due_date' => 'date',
        'next_payment_date' => 'date',
    ];
}
