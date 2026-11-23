<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pago de cobranza (recuperación) importado de Lendus.
 *
 * Tabla: `fact_recoveries`. Cada fila es un pago registrado en el reporte de
 * ingresos/cobranza de Lendus (LendusIngresosCobranzaImportService). Es la base
 * del KPI de recuperación de la radiografía (se excluyen transacciones como
 * CONDONACION y los seguros, según las reglas del calculador) y de la
 * efectividad de cobranza por gestor.
 *
 * Columnas clave:
 * - `office_name_raw`/`branch_id`: oficina tal como viene y sucursal resuelta.
 * - `contract`, `client_name`/`acreditado`/`normalized_client_name`: crédito y cliente.
 * - `promoter_name`: gestor; `product_name`/`product_name_raw`: producto.
 * - `operation`/`concept`/`transaction`: tipo de movimiento en Lendus.
 * - Desglose del pago (decimal:2): `capital`, `interest`, `tax`, `charges`,
 *   `charges_due`, `excedente`, `total_amount`.
 * - `days_past_due` (int): días de atraso al pagar.
 * - `payment_date`, `installment_due_date`, `contract_due_date` (date) e `installment_number`.
 * - `is_savehearts` (bool)/`savehearts_crece_share` (decimal:2): pagos Savehearts y su parte CRECE.
 * - `importer_version`: versión del importador que generó la fila.
 * - `raw_payload` (array): fila original.
 */
class Recovery extends Model
{
    protected $table = 'fact_recoveries';

    protected $fillable = [
        'period_id',
        'report_upload_id',
        'branch_id',
        'office_name_raw',
        'contract',
        'client_name',
        'acreditado',
        'normalized_client_name',
        'promoter_name',
        'product_name',
        'product_name_raw',
        'operation',
        'concept',
        'transaction',
        'capital',
        'interest',
        'tax',
        'charges',
        'charges_due',
        'excedente',
        'total_amount',
        'days_past_due',
        'payment_date',
        'installment_due_date',
        'installment_number',
        'contract_due_date',
        'is_savehearts',
        'savehearts_crece_share',
        'importer_version',
        'raw_payload',
    ];

    protected $casts = [
        'capital' => 'decimal:2',
        'interest' => 'decimal:2',
        'tax' => 'decimal:2',
        'charges' => 'decimal:2',
        'charges_due' => 'decimal:2',
        'excedente' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'days_past_due' => 'integer',
        'payment_date' => 'date',
        'installment_due_date' => 'date',
        'contract_due_date' => 'date',
        'is_savehearts' => 'boolean',
        'savehearts_crece_share' => 'decimal:2',
        'raw_payload' => 'array',
    ];

    /** Periodo del pago. */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** Archivo cargado del que proviene. */
    public function reportUpload(): BelongsTo
    {
        return $this->belongsTo(ReportUpload::class);
    }

    /** Sucursal a la que se atribuye el pago. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
