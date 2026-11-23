<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora _AUDITORIA_EFECTIVIDAD de una ejecución V4 (una fila por incidencia).
 *
 * Tabla: `efectividad_cobranza_run_incidents`. Cada fila es una incidencia
 * detectada al calcular la efectividad de cobranza (ej. contrato excluido,
 * pago sin cuota), en el orden en que se registró.
 *
 * Casts: `occurred_on` (fecha del hecho), `amount` (monto en float),
 * `position` (orden dentro de la bitácora). Solo tiene `created_at`.
 */
class EfectividadCobranzaRunIncident extends Model
{
    /** La tabla no maneja `updated_at`: las incidencias son inmutables. */
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'occurred_on' => 'date',
        'amount' => 'float',
        'position' => 'integer',
    ];

    /** Ejecución de efectividad de cobranza a la que pertenece la incidencia. */
    public function run(): BelongsTo
    {
        return $this->belongsTo(EfectividadCobranzaRun::class, 'efectividad_cobranza_run_id');
    }
}
