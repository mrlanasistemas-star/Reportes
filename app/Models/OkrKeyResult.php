<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Key Result del módulo OKR — cuantificado (ver docs/MANUAL-DESARROLLADOR.md). Los campos
 * current_value/expected_value/*_progress_percentage/deviation_pp/projected_*
 * son SNAPSHOT/CACHE de la última evaluación (OkrSnapshotService) — la fuente
 * real sigue viviendo en Reportería, nunca al revés.
 *
 * Tabla: `okr_key_results` (con borrado lógico, SoftDeletes). Cada fila es un
 * resultado clave medible de un objetivo, ligado a un KPI del catálogo.
 *
 * Columnas clave:
 * - `baseline_value`/`baseline_source`/`baseline_period_date`: línea base y de dónde salió.
 * - `baseline_locked_at` (datetime): cuándo se congeló la línea base (ya no se recalcula).
 * - `target_value` (float): meta; `weight` (float): peso dentro del objetivo.
 * - `current_value` (float) + `last_manual_input_by/at`: valor actual y última captura manual.
 * - `expected_value`, `*_progress_percentage`, `deviation_pp`, `projected_*`,
 *   `health_status`, `last_evaluated_at`: caché de la última evaluación.
 */
class OkrKeyResult extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'okr_objective_id', 'kpi_id', 'description',
        'baseline_value', 'baseline_source', 'baseline_period_date', 'baseline_locked_at',
        'target_value', 'weight',
        'current_value', 'last_manual_input_by', 'last_manual_input_at', 'expected_value', 'actual_progress_percentage',
        'expected_progress_percentage', 'deviation_pp', 'projected_value',
        'projected_compliance_percentage', 'health_status', 'last_evaluated_at',
    ];

    protected $casts = [
        'baseline_value' => 'float',
        'baseline_period_date' => 'date',
        'baseline_locked_at' => 'datetime',
        'target_value' => 'float',
        'weight' => 'float',
        'current_value' => 'float',
        'last_manual_input_at' => 'datetime',
        'expected_value' => 'float',
        'actual_progress_percentage' => 'float',
        'expected_progress_percentage' => 'float',
        'deviation_pp' => 'float',
        'projected_value' => 'float',
        'projected_compliance_percentage' => 'float',
        'last_evaluated_at' => 'datetime',
    ];

    /** Objetivo al que pertenece el resultado clave. */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    /** KPI del catálogo con el que se mide el resultado clave. */
    public function kpi(): BelongsTo
    {
        return $this->belongsTo(OkrKpi::class, 'kpi_id');
    }

    /** Histórico semanal de avance del resultado clave. */
    public function snapshots(): HasMany
    {
        return $this->hasMany(OkrProgressSnapshot::class, 'okr_key_result_id');
    }

    /** Evidencias subidas para este resultado clave. */
    public function evidences(): HasMany
    {
        return $this->hasMany(OkrEvidence::class, 'okr_key_result_id');
    }

    /**
     * Indica si la línea base ya está congelada.
     *
     * @return bool true si `baseline_locked_at` tiene fecha.
     */
    public function isBaselineLocked(): bool
    {
        return $this->baseline_locked_at !== null;
    }
}
