<?php

namespace App\Models\EstadoResultados;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento bancario normalizado del Estado de Resultados (tabla `estado_resultados_movements`).
 *
 * Cada fila es un renglón del estado de cuenta cargado (`upload_id`) en un periodo: conserva el
 * dato original y el resultado de la conciliación/clasificación automática (recalculable y
 * determinista en cada procesamiento).
 *
 * Columnas clave del dato original: `account_slot`, `source_row`, `fingerprint` (huella del
 * renglón), `movement_date`, `bank_reference`, `cargo`/`abono`/`saldo`, folio (`folio_raw`,
 * `folio_normalized`), `concept`, sucursal (`sucursal_raw`, `branch_name`, `branch_kind`:
 * operativa | corporativo | agrupado | desconocida | vacia), `beneficiary`, `uuid` (CFDI),
 * `detail_lines` (desglose) y `raw_payload` (fila cruda completa).
 *
 * Ubicación en el periodo: `in_period`, `placement` (por defecto «periodo»; cada movimiento va al
 * periodo de su fecha) y `dedup_key` (permite repetir huella dentro del periodo sin duplicar;
 * único con period_id + fingerprint).
 *
 * Resultado del procesamiento: `reconciliation_status`/`reconciliation_state`,
 * `reconciliation_detail`, `auto_treatment`, `suggested_treatment`, `rule_code`, `reason`,
 * `needs_review`, `transfer_pair_key`, importes `applied_amount`/`pending_amount`,
 * `financial_category`, `accounting_treatment`, `inclusion_status`, `recognizing_source`,
 * fechas separadas (`operation_date`, `erp_capture_date`, `erp_payment_date`, `accrual_date`),
 * vínculos de reverso/reembolso/NOI y la regla del catálogo aplicada (`concept_rule_id`,
 * `concept_label`).
 */
class EstadoResultadosMovement extends Model
{
    protected $table = 'estado_resultados_movements';

    protected $guarded = ['id'];

    /** Fechas serializadas como Y-m-d; JSON de detalle y payload crudo; banderas booleanas. */
    protected $casts = [
        'movement_date' => 'date:Y-m-d',
        'operation_date' => 'date:Y-m-d',
        'erp_capture_date' => 'date:Y-m-d',
        'erp_payment_date' => 'date:Y-m-d',
        'accrual_date' => 'date:Y-m-d',
        'detail_lines' => 'array',
        'raw_payload' => 'array',
        'reconciliation_detail' => 'array',
        'in_period' => 'boolean',
        'needs_review' => 'boolean',
        'processed_at' => 'datetime',
    ];

    /**
     * Carga (archivo de estado de cuenta) de la que proviene el movimiento.
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(EstadoResultadosUpload::class, 'upload_id');
    }
}
