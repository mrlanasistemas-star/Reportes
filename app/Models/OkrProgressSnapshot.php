<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Histórico semanal por Key Result — ver OkrSnapshotService (idempotente). */
class OkrProgressSnapshot extends Model
{
    protected $fillable = [
        'okr_key_result_id', 'week_number', 'snapshot_date',
        'actual_value', 'expected_value', 'actual_progress_percentage',
        'expected_progress_percentage', 'deviation_pp', 'projected_value',
        'projected_compliance_percentage', 'health_status', 'source_reference',
        'calculated_at',
    ];

    protected $casts = [
        'snapshot_date'                    => 'date',
        'actual_value'                     => 'float',
        'expected_value'                   => 'float',
        'actual_progress_percentage'       => 'float',
        'expected_progress_percentage'     => 'float',
        'deviation_pp'                     => 'float',
        'projected_value'                  => 'float',
        'projected_compliance_percentage'  => 'float',
        'calculated_at'                    => 'datetime',
    ];

    public function keyResult(): BelongsTo
    {
        return $this->belongsTo(OkrKeyResult::class, 'okr_key_result_id');
    }
}
