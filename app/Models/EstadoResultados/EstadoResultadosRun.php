<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Corrida de procesamiento (conciliación + clasificación) del Estado de Resultados
 * (tabla `estado_resultados_runs`).
 *
 * Cada fila es un «Procesar conciliación» de un periodo. Columnas clave: `status` (ok | error),
 * `rules_version` (versión de reglas usada), `erp_signature` (firma de los datos ERP leídos),
 * `summary` (JSON con totales/contadores del resultado), `error` (mensaje si falló),
 * `recognition_version` y `recognition_criterion` (criterio de reconocimiento versionado),
 * `input_signature` (firma de todos los insumos, para detectar si cambiaron desde la corrida)
 * y tiempos `started_at`/`finished_at`.
 */
class EstadoResultadosRun extends Model
{
    protected $table = 'estado_resultados_runs';

    protected $guarded = ['id'];

    /** Resumen y criterio de reconocimiento en JSON; tiempos como fechas. */
    protected $casts = [
        'summary' => 'array',
        'recognition_criterion' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Usuario que lanzó la corrida.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
