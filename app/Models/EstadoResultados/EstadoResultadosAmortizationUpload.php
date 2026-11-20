<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Carga de una tabla de amortización (bitácora: archivo original, hash, quién y cuándo). */
class EstadoResultadosAmortizationUpload extends Model
{
    protected $table = 'estado_resultados_amortization_uploads';

    protected $guarded = ['id'];

    public const VIGENTE = 'vigente';

    public const SUSTITUIDA = 'sustituida';

    protected $casts = [
        'first_payment_date' => 'date',
        'last_payment_date' => 'date',
        'column_map' => 'array',
        'warnings' => 'array',
        'total_payment' => 'decimal:2',
        'total_principal' => 'decimal:2',
        'total_interest' => 'decimal:2',
        'total_interest_vat' => 'decimal:2',
        'superseded_at' => 'datetime',
    ];

    public function credit(): BelongsTo
    {
        return $this->belongsTo(EstadoResultadosCredit::class, 'credit_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(EstadoResultadosAmortizationRow::class, 'upload_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
