<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de auditoría del módulo OKR — ver OkrAuditLogger.
 * "Una meta no se puede modificar silenciosamente."
 *
 * Tabla: `okr_audit_logs`. Cada fila registra un cambio sobre un objetivo o un
 * resultado clave: quién (`user_id`), qué acción, qué campo, valor anterior y
 * nuevo, y el motivo capturado.
 *
 * Columnas clave: `auditable_type` (TYPE_OBJECTIVE o TYPE_KEY_RESULT),
 * `auditable_id`, `action`, `field`, `old_value`, `new_value`, `reason`.
 */
class OkrAuditLog extends Model
{
    /** Valor de `auditable_type` para cambios sobre un objetivo. */
    public const TYPE_OBJECTIVE = 'objective';

    /** Valor de `auditable_type` para cambios sobre un resultado clave. */
    public const TYPE_KEY_RESULT = 'key_result';

    protected $fillable = [
        'auditable_type', 'auditable_id', 'user_id', 'action',
        'field', 'old_value', 'new_value', 'reason',
    ];

    /** Usuario que realizó el cambio auditado. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
