<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Gasto operativo importado (ERP o Lendus).
 *
 * Tabla: `fact_expenses`. Cada fila es un gasto del periodo, importado por los
 * servicios de gastos (GastosErpExcelImportService, GastosLendusExcelImportService,
 * GastosLendusPdfImportService, GastosImportService), atribuido a una sucursal y,
 * cuando aplica, a un colaborador. Alimenta OPEX/Nómina en la radiografía y el
 * Estado de Resultados.
 *
 * Columnas clave:
 * - `category`/`concept`: categoría y concepto del gasto.
 * - `amount`/`paid_amount` (decimal:2): monto y monto pagado.
 * - `expense_date` (date): fecha del gasto.
 * - `observations`: observaciones de la fuente.
 * - `raw_payload` (array): fila original.
 * - `attribution_method`/`attribution_source`/`attribution_confidence` (decimal:2):
 *   cómo y con qué confianza se atribuyó a sucursal/colaborador.
 * - `attribution_needs_review` (bool): si la atribución requiere revisión.
 */
class Expense extends Model
{
    protected $table = 'fact_expenses';

    protected $fillable = [
        'period_id',
        'report_upload_id',
        'employee_id',
        'branch_id',
        'category',
        'concept',
        'amount',
        'paid_amount',
        'expense_date',
        'observations',
        'raw_payload',
        'attribution_method',
        'attribution_confidence',
        'attribution_source',
        'attribution_needs_review',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'expense_date' => 'date',
        'raw_payload' => 'array',
        'attribution_confidence' => 'decimal:2',
        'attribution_needs_review' => 'boolean',
    ];

    /** Periodo del gasto. */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** Archivo cargado del que proviene. */
    public function reportUpload(): BelongsTo
    {
        return $this->belongsTo(ReportUpload::class);
    }

    /** Colaborador al que se atribuyó el gasto (si aplica). */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Sucursal a la que se atribuyó el gasto. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
