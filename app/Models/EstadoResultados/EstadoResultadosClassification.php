<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Clasificación manual de un movimiento del Estado de Resultados
 * (tabla `estado_resultados_classifications`).
 *
 * Bitácora append-only: la clasificación vigente de un movimiento es su fila más reciente.
 * Nunca se sobreescribe el dato de origen; cada reclasificación agrega una fila nueva.
 *
 * Columnas clave: `period_id`, `item_fingerprint` (huella del movimiento bancario o renglón
 * ERP), `item_source` (banco | erp), `treatment` (tratamiento elegido), `previous_treatment`
 * (el que tenía antes), `notes` (justificación obligatoria) y `user_id`.
 */
class EstadoResultadosClassification extends Model
{
    /** Sin columna updated_at: sólo se insertan filas nuevas. */
    public const UPDATED_AT = null;

    protected $table = 'estado_resultados_classifications';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    /**
     * Usuario que registró la clasificación.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
