<?php

namespace App\Models\EstadoResultados;

use Illuminate\Database\Eloquent\Model;

/**
 * Vínculo verificable de un movimiento bancario con su contraparte (ERP, NOI u
 * otro movimiento bancario), con importe aplicado y saldo pendiente de ambos lados.
 * Se regenera COMPLETO en cada procesamiento del periodo (idempotente).
 *
 * Tabla `estado_resultados_reconciliation_links` (única por periodo + huella del movimiento +
 * tipo/huella de contraparte + tipo de vínculo). Columnas clave:
 *  - `movement_fingerprint`: huella del movimiento bancario.
 *  - `target_type`: erp | noi | banco; `target_fingerprint`: huella ERP, employee_id NOI o huella
 *    bancaria de la contraparte.
 *  - `link_kind`: conciliacion | evidencia_noi | reverso | reembolso | traspaso.
 *  - `match_type`: 1:1 | 1:N | N:1 | N:M | parcial; `group_key`: grupo de emparejamiento.
 *  - Importes: `applied_amount`, `movement_amount`, `target_amount`, `movement_pending`,
 *    `target_pending`.
 *  - `delta_days`: diferencia en días entre ambas fechas; `evidence`: JSON con el porqué del vínculo.
 *  - `run_id`: corrida de procesamiento que lo generó.
 */
class EstadoResultadosReconciliationLink extends Model
{
    protected $table = 'estado_resultados_reconciliation_links';

    protected $guarded = ['id'];

    /** `evidence` es JSON; `delta_days` entero. */
    protected $casts = [
        'evidence' => 'array',
        'delta_days' => 'integer',
    ];
}
