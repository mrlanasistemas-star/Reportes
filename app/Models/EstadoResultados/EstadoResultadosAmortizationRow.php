<?php

namespace App\Models\EstadoResultados;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Renglón de una tabla de amortización: un pago con su capital e interés. */
class EstadoResultadosAmortizationRow extends Model
{
    protected $table = 'estado_resultados_amortization_rows';

    protected $guarded = ['id'];

    public const ORIGEN_ARCHIVO = 'archivo';

    public const ORIGEN_CAPTURA = 'captura';

    protected $casts = [
        'payment_date' => 'date',
        'payment_total' => 'decimal:2',
        'principal' => 'decimal:2',
        'interest' => 'decimal:2',
        'interest_vat' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(EstadoResultadosAmortizationUpload::class, 'upload_id');
    }

    public function credit(): BelongsTo
    {
        return $this->belongsTo(EstadoResultadosCredit::class, 'credit_id');
    }
}
