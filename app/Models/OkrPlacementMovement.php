<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OkrPlacementMovement extends Model
{
    protected $fillable = [
        'okr_placement_upload_id', 'employee_id', 'employee_name_raw',
        'amount', 'operation_date', 'fingerprint', 'raw_payload',
    ];

    protected $casts = [
        'operation_date' => 'date',
        'amount' => 'float',
        'raw_payload' => 'array',
    ];

    public function upload(): BelongsTo
    {
        return $this->belongsTo(OkrPlacementUpload::class, 'okr_placement_upload_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
