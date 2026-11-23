<?php

namespace App\Models\EstadoResultados;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de una tabla de amortización: un pago con su capital e interés
 * (tabla `estado_resultados_amortization_rows`).
 *
 * Pertenece a un crédito de la empresa (`credit_id`) y a la carga que lo trajo (`upload_id`).
 * Sirve para calcular el gasto financiero (intereses LEROM/BBVA) que va entre EBIT y EBT
 * según la fecha de pago (`payment_date`) que cae en el periodo.
 *
 * Columnas clave: `payment_number`, `payment_date`, `payment_total` (pago completo),
 * `principal` (capital), `interest` (interés), `interest_vat` (IVA del interés),
 * `balance` (saldo insoluto), `origin` (archivo | captura) y `source_row` (fila del archivo).
 */
class EstadoResultadosAmortizationRow extends Model
{
    protected $table = 'estado_resultados_amortization_rows';

    protected $guarded = ['id'];

    /** El renglón se leyó del archivo de amortización cargado. */
    public const ORIGEN_ARCHIVO = 'archivo';

    /** El renglón se capturó manualmente en pantalla. */
    public const ORIGEN_CAPTURA = 'captura';

    /** Fecha de pago como fecha e importes como decimales de 2 posiciones. */
    protected $casts = [
        'payment_date' => 'date',
        'payment_total' => 'decimal:2',
        'principal' => 'decimal:2',
        'interest' => 'decimal:2',
        'interest_vat' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    /**
     * Carga (archivo de amortización) de la que proviene el renglón.
     */
    public function upload(): BelongsTo
    {
        return $this->belongsTo(EstadoResultadosAmortizationUpload::class, 'upload_id');
    }

    /**
     * Crédito de la empresa al que corresponde el pago.
     */
    public function credit(): BelongsTo
    {
        return $this->belongsTo(EstadoResultadosCredit::class, 'credit_id');
    }
}
