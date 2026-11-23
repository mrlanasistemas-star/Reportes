<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de cambios de un ajuste contable del Estado de Resultados
 * (tabla `estado_resultados_adjustment_logs`).
 *
 * Cada fila registra un cambio de un `EstadoResultadosAdjustment` (`adjustment_id`):
 * `old_state`/`old_amount` → `new_state`/`new_amount`, con notas y el usuario que lo hizo.
 * Sólo tiene `created_at` (UPDATED_AT = null): es un registro de auditoría que no se edita.
 */
class EstadoResultadosAdjustmentLog extends Model
{
    /** Sin columna updated_at: la bitácora sólo se inserta, nunca se actualiza. */
    public const UPDATED_AT = null;

    protected $table = 'estado_resultados_adjustment_logs';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    /**
     * Usuario que realizó el cambio registrado.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
