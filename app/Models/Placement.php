<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Colocación (crédito otorgado / ministración) importada de Lendus.
 *
 * Tabla: `fact_placements`. Cada fila es un crédito colocado en el periodo,
 * importado por LendusMinistracionesImportService. Es la base del KPI de
 * colocación de la radiografía (monto bruto) y de la productividad por gestor.
 *
 * Columnas clave:
 * - `client_name`/`normalized_client_name`: acreditado.
 * - `promoter_name`/`promoter_code`/`normalized_promoter_name`: gestor que colocó.
 * - `coordinator_name`: coordinador.
 * - `product_name`/`financial_product_id`: producto financiero.
 * - `amount` (decimal:2): monto colocado.
 * - `operation_date` (date): fecha de la operación.
 * - `raw_payload` (array): fila original.
 */
class Placement extends Model
{
    protected $table = 'fact_placements';

    protected $fillable = [
        'period_id',
        'report_upload_id',
        'branch_id',
        'financial_product_id',
        'client_name',
        'normalized_client_name',
        'promoter_name',
        'promoter_code',
        'normalized_promoter_name',
        'coordinator_name',
        'product_name',
        'amount',
        'operation_date',
        'raw_payload',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'operation_date' => 'date',
        'raw_payload' => 'array',
    ];

    /** Periodo de la colocación. */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** Archivo cargado del que proviene. */
    public function reportUpload(): BelongsTo
    {
        return $this->belongsTo(ReportUpload::class);
    }

    /** Sucursal que colocó el crédito. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Producto financiero del crédito. */
    public function financialProduct(): BelongsTo
    {
        return $this->belongsTo(FinancialProduct::class);
    }
}
