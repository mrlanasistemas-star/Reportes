<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ajuste contable tipificado del Estado de Resultados (tabla `estado_resultados_adjustments`).
 *
 * Cada fila es UN concepto de ajuste (p.ej. depreciación, ISR, intereses de un crédito) para
 * un periodo y un alcance (`scope_key`, por defecto CONSOLIDADO o una sucursal). Es único por
 * (period_id, scope_key, concept). Existe para distinguir explícitamente entre «no hay
 * información» y «es cero confirmado», de modo que un ajuste faltante no pase como $0.
 *
 * Columnas clave:
 *  - `state`: sin_informacion | confirmado_cero | capturado.
 *  - `amount`: importe capturado (null si no aplica).
 *  - `notes`: justificación del capturista.
 *  - `updated_by`: último usuario que lo modificó.
 *
 * Cada cambio queda en su bitácora (`EstadoResultadosAdjustmentLog`).
 */
class EstadoResultadosAdjustment extends Model
{
    protected $table = 'estado_resultados_adjustments';

    protected $guarded = ['id'];

    /**
     * Historial de cambios de este ajuste (estado/importe anterior y nuevo, quién y cuándo).
     */
    public function logs(): HasMany
    {
        return $this->hasMany(EstadoResultadosAdjustmentLog::class, 'adjustment_id');
    }

    /**
     * Usuario que modificó por última vez el ajuste (columna `updated_by`).
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
