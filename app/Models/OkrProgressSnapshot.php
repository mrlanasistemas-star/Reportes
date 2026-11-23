<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Histórico semanal por Key Result — ver OkrSnapshotService (idempotente).
 *
 * Tabla: `okr_progress_snapshots`. Cada fila es la foto del avance de un
 * resultado clave en una semana: valor real vs esperado, desviación, proyección
 * y semáforo, junto con la procedencia y calidad del dato usado.
 *
 * Columnas clave:
 * - `week_number`/`snapshot_date`: semana y fecha de la foto.
 * - `actual_value`/`expected_value` (float): valor real y esperado a la fecha.
 * - `actual_progress_percentage`/`expected_progress_percentage` (float): avances en %.
 * - `deviation_pp` (float): desviación en puntos porcentuales.
 * - `projected_value`/`projected_compliance_percentage` (float): proyección al cierre.
 * - `health_status`: semáforo del KR.
 * - `source_*`: de qué periodo/fecha/granularidad salió el dato.
 * - `source_quality`: una de las constantes QUALITY_*.
 * - `calculated_at` (datetime): cuándo se calculó.
 */
class OkrProgressSnapshot extends Model
{
    /** El dato corresponde exactamente a la semana. */
    public const QUALITY_EXACT = 'exact';

    /** Se usó el dato mensual como aproximación de la semana. */
    public const QUALITY_MONTHLY_PROXY = 'monthly_proxy';

    /** Se usó el último dato disponible (no hay uno de la semana). */
    public const QUALITY_LAST_AVAILABLE = 'last_available';

    /** El valor proviene de un check-in capturado a mano. */
    public const QUALITY_MANUAL_CHECKIN = 'manual_checkin';

    /** Parte 8.1 del cierre (04-oct-2026): sin archivo de colocación semanal para esta semana — nunca se finge 0. */
    public const QUALITY_MISSING = 'missing';

    protected $fillable = [
        'okr_key_result_id', 'week_number', 'snapshot_date',
        'actual_value', 'expected_value', 'actual_progress_percentage',
        'expected_progress_percentage', 'deviation_pp', 'projected_value',
        'projected_compliance_percentage', 'health_status', 'source_reference',
        'source_period_id', 'source_period_code', 'source_date', 'source_granularity', 'source_quality',
        'calculated_at',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'source_date' => 'date',
        'actual_value' => 'float',
        'expected_value' => 'float',
        'actual_progress_percentage' => 'float',
        'expected_progress_percentage' => 'float',
        'deviation_pp' => 'float',
        'projected_value' => 'float',
        'projected_compliance_percentage' => 'float',
        'calculated_at' => 'datetime',
    ];

    /** Resultado clave al que pertenece la foto semanal. */
    public function keyResult(): BelongsTo
    {
        return $this->belongsTo(OkrKeyResult::class, 'okr_key_result_id');
    }
}
