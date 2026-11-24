<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Carga de una tabla de amortización (bitácora: archivo original, hash, quién y cuándo)
 * (tabla `estado_resultados_amortization_uploads`).
 *
 * Cada fila es un archivo subido para un crédito (`credit_id`). Sólo una carga por crédito
 * está `vigente`; al subir otra, la anterior pasa a `sustituida` (`superseded_at`,
 * `superseded_by_upload_id`) y se conserva como historial.
 *
 * Columnas clave: `file_hash` (sha256 del archivo), `rows_count`, rango de fechas de pago
 * (`first_payment_date`/`last_payment_date`), totales de pago/capital/interés/IVA,
 * `column_map` (encabezado detectado por cada campo) y `warnings` (avisos de lectura).
 * `document_kind` (24-nov-2026): simulacion (default; documento no vinculante) | definitivo, y
 * totales `total_commission` / `total_commission_vat`.
 */
class EstadoResultadosAmortizationUpload extends Model
{
    protected $table = 'estado_resultados_amortization_uploads';

    protected $guarded = ['id'];

    /** Carga vigente: es la tabla de amortización que se usa en los cálculos. */
    public const VIGENTE = 'vigente';

    /** Carga reemplazada por una más reciente (sólo historial). */
    public const SUSTITUIDA = 'sustituida';

    /** Fechas, JSON (mapa de columnas y avisos) e importes decimales. */
    protected $casts = [
        'first_payment_date' => 'date',
        'last_payment_date' => 'date',
        'column_map' => 'array',
        'warnings' => 'array',
        'total_payment' => 'decimal:2',
        'total_principal' => 'decimal:2',
        'total_interest' => 'decimal:2',
        'total_interest_vat' => 'decimal:2',
        'total_commission' => 'decimal:2',
        'total_commission_vat' => 'decimal:2',
        'superseded_at' => 'datetime',
    ];

    /**
     * Crédito de la empresa al que pertenece esta tabla de amortización.
     */
    public function credit(): BelongsTo
    {
        return $this->belongsTo(EstadoResultadosCredit::class, 'credit_id');
    }

    /**
     * Renglones (pagos) leídos de esta carga.
     */
    public function rows(): HasMany
    {
        return $this->hasMany(EstadoResultadosAmortizationRow::class, 'upload_id');
    }

    /**
     * Usuario que subió el archivo (columna `uploaded_by`).
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
