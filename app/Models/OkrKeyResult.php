<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Key Result del módulo OKR — cuantificado (ver docs/OKR.md). Los campos
 * current_value/expected_value/*_progress_percentage/deviation_pp/projected_*
 * son SNAPSHOT/CACHE de la última evaluación (OkrSnapshotService) — la fuente
 * real sigue viviendo en Reportería, nunca al revés.
 */
class OkrKeyResult extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'okr_objective_id', 'kpi_id', 'description',
        'baseline_value', 'baseline_source', 'baseline_period_date', 'baseline_locked_at',
        'target_value', 'weight',
        'current_value', 'expected_value', 'actual_progress_percentage',
        'expected_progress_percentage', 'deviation_pp', 'projected_value',
        'projected_compliance_percentage', 'health_status', 'last_evaluated_at',
    ];

    protected $casts = [
        'baseline_value'                   => 'float',
        'baseline_period_date'             => 'date',
        'baseline_locked_at'               => 'datetime',
        'target_value'                     => 'float',
        'weight'                           => 'float',
        'current_value'                    => 'float',
        'expected_value'                   => 'float',
        'actual_progress_percentage'       => 'float',
        'expected_progress_percentage'     => 'float',
        'deviation_pp'                     => 'float',
        'projected_value'                  => 'float',
        'projected_compliance_percentage'  => 'float',
        'last_evaluated_at'                => 'datetime',
    ];

    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    public function kpi(): BelongsTo
    {
        return $this->belongsTo(OkrKpi::class, 'kpi_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(OkrProgressSnapshot::class, 'okr_key_result_id');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(OkrEvidence::class, 'okr_key_result_id');
    }

    public function isBaselineLocked(): bool
    {
        return $this->baseline_locked_at !== null;
    }
}
