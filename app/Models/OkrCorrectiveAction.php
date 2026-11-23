<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Acción correctiva comprometida en un check-in OKR.
 *
 * Tabla: `okr_corrective_actions`. Cada fila es una tarea que un responsable se
 * compromete a hacer, con fecha límite, para corregir el rumbo de un objetivo.
 *
 * Columnas clave:
 * - `okr_check_in_id`: check-in donde se acordó.
 * - `description`: qué se va a hacer.
 * - `responsible_user_id`: usuario responsable.
 * - `due_date` (date): fecha compromiso.
 * - `status`: una de las constantes STATUS_*.
 * - `completed_at` (date): fecha en que se completó.
 * - `evidence_id`: evidencia que respalda el cumplimiento (si existe).
 */
class OkrCorrectiveAction extends Model
{
    /** Acción aún no completada. */
    public const STATUS_PENDING = 'pending';

    /** Acción completada. */
    public const STATUS_DONE = 'done';

    /** Acción marcada como vencida. */
    public const STATUS_OVERDUE = 'overdue';

    protected $fillable = [
        'okr_objective_id', 'okr_check_in_id', 'description', 'responsible_user_id',
        'due_date', 'status', 'completed_at', 'evidence_id',
    ];

    protected $casts = [
        'due_date' => 'date',
        'completed_at' => 'date',
    ];

    /** Objetivo OKR al que pertenece la acción. */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    /** Check-in en el que se acordó la acción. */
    public function checkIn(): BelongsTo
    {
        return $this->belongsTo(OkrCheckIn::class, 'okr_check_in_id');
    }

    /** Usuario responsable de ejecutar la acción. */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /**
     * Indica si la acción está vencida: sigue pendiente y su fecha compromiso ya pasó.
     * Se calcula al vuelo; no depende de que `status` se haya actualizado a `overdue`.
     *
     * @return bool true si está pendiente con `due_date` en el pasado.
     */
    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->due_date?->isPast();
    }
}
