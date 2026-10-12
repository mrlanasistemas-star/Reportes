<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bitácora _AUDITORIA_EFECTIVIDAD de una ejecución V4 (una fila por incidencia). */
class EfectividadCobranzaRunIncident extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'occurred_on' => 'date',
        'amount' => 'float',
        'position' => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(EfectividadCobranzaRun::class, 'efectividad_cobranza_run_id');
    }
}
