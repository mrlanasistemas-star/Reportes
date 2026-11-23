<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora append-only de cambios al catálogo de conceptos
 * (tabla `estado_resultados_concept_rule_logs`).
 *
 * Cada fila registra una acción sobre una regla (`rule_id`): creada | editada | activada |
 * desactivada | borrada, con la foto de la regla antes (`before`) y después (`after`) y el
 * usuario que la hizo. `rule_id` no tiene llave foránea para conservar el historial aun si
 * la regla se elimina.
 */
class EstadoResultadosConceptRuleLog extends Model
{
    /** Sin columna updated_at: la bitácora sólo se inserta. */
    public const UPDATED_AT = null;

    protected $table = 'estado_resultados_concept_rule_logs';

    protected $guarded = ['id'];

    /** `before`/`after` son la regla completa en JSON. */
    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];

    /**
     * Usuario que realizó el cambio al catálogo.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
