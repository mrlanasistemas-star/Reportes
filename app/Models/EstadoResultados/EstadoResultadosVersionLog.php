<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de versiones del Estado de Resultados (tabla `estado_resultados_version_logs`).
 *
 * Cada fila registra una acción sobre las versiones de un periodo: `action` (aprobado |
 * nueva_version | sustituido), la versión afectada (`version_id`, `version_number`), el motivo
 * (`reason`), detalles en JSON (`details`) y el usuario. Sólo tiene `created_at`.
 */
class EstadoResultadosVersionLog extends Model
{
    /** Sin columna updated_at: la bitácora sólo se inserta. */
    public const UPDATED_AT = null;

    protected $table = 'estado_resultados_version_logs';

    protected $guarded = ['id'];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    /**
     * Usuario que realizó la acción.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
