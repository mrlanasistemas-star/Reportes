<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Histórico semanal por Key Result — ver OkrSnapshotService (idempotente). */
class OkrProgressSnapshot extends Model
{
    public const QUALITY_EXACT          = 'exact';
    public const QUALITY_MONTHLY_PROXY  = 'monthly_proxy';
    public const QUALITY_LAST_AVAILABLE = 'last_available';
    public const QUALITY_MANUAL_CHECKIN = 'manual_checkin';

    protected $fillable = [
        'okr_key_result_id', 'week_number', 'snapshot_date',
        'actual_value', 'expected_value', 'actual_progress_percentage',
        'expected_progress_percentage', 'deviation_pp', 'projected_value',
        'projected_compliance_percentage', 'health_status', 'source_reference',
        'source_period_id', 'source_period_code', 'source_date', 'source_granularity', 'source_quality',
        'calculated_at',
    ];

    protected $casts = [
        'snapshot_date'                    => 'date',
        'source_date'                      => 'date',
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
