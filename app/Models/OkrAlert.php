<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alertas INTERNAS del módulo OKR (nunca WhatsApp/SMS/correo/push externo en esta fase).
 *
 * Tabla: `okr_alerts`. Cada fila es un aviso sobre un objetivo (en riesgo,
 * check-in pendiente, fecha límite cercana, fuera de ruta) que se muestra dentro
 * del sistema hasta que alguien lo marca como leído.
 *
 * Columnas clave:
 * - `type`: una de las constantes TYPE_*.
 * - `message`: texto de la alerta.
 * - `dedupe_key`: llave para no generar la misma alerta dos veces.
 * - `read_at` (datetime) / `read_by`: cuándo y quién la marcó como leída.
 */
class OkrAlert extends Model
{
    /** El objetivo está en riesgo de no cumplirse. */
    public const TYPE_RISK = 'risk';

    /** Falta registrar el check-in semanal. */
    public const TYPE_CHECKIN_PENDING = 'checkin_pending';

    /** La fecha límite del objetivo está próxima. */
    public const TYPE_DEADLINE_NEAR = 'deadline_near';

    /** El objetivo va fuera de ruta respecto a la meta. */
    public const TYPE_OFF_TRACK = 'off_track';

    protected $fillable = [
        'okr_objective_id', 'type', 'message', 'dedupe_key', 'read_at', 'read_by',
    ];

    protected $casts = [
        'read_at' => 'datetime',
    ];

    /** Objetivo OKR al que se refiere la alerta. */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    /**
     * Indica si la alerta ya fue marcada como leída.
     *
     * @return bool true si `read_at` tiene fecha.
     */
    public function isRead(): bool
    {
        return $this->read_at !== null;
    }
}
