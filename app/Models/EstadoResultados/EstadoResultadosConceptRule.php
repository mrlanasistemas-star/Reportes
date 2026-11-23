<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Regla del catálogo de conceptos (global): texto del estado de cuenta → concepto del reporte.
 * La lógica (normalización, coincidencia, bitácora) vive en ConceptCatalogService.
 *
 * Tabla `estado_resultados_concept_rules` (con borrado lógico, `deleted_at`).
 * Columnas clave:
 *  - `name`: concepto del reporte («Renta de oficinas»).
 *  - `match_text` / `match_normalized`: texto tal como lo capturó el usuario y su versión
 *    normalizada (MAYÚSCULAS, sin acentos ni espacios dobles) contra la que se compara.
 *  - `match_mode`: contiene | empieza | exacto.
 *  - `fields`: columnas del movimiento donde se busca (concept, observations, beneficiary,
 *    concept_gasto, bank_reference).
 *  - `account_slot`: cuenta a la que aplica (null = cualquiera).
 *  - `direction`: cargo | abono | cualquiera.
 *  - `treatment`: tratamiento contable que asigna la regla.
 *  - `priority`: desempate cuando varias reglas coinciden (0–1000, por defecto 100; gana la
 *    de MAYOR prioridad y luego el texto más largo); `active`: si participa.
 */
class EstadoResultadosConceptRule extends Model
{
    use SoftDeletes;

    protected $table = 'estado_resultados_concept_rules';

    protected $guarded = ['id'];

    /** `fields` es la lista JSON de columnas a revisar; `active` booleano; `priority` entero. */
    protected $casts = [
        'fields' => 'array',
        'active' => 'boolean',
        'priority' => 'integer',
    ];

    /**
     * Usuario que creó la regla (columna `created_by`).
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Usuario que editó por última vez la regla (columna `updated_by`).
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
