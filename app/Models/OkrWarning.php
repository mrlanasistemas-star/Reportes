<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OkrWarning extends Model
{
    protected $fillable = [
        'folio', 'okr_objective_id', 'week_number', 'week_start', 'week_end', 'snapshot',
        'corrective_actions', 'observations', 'generated_by', 'generated_at',
        'stored_path', 'disk', 'signed_stored_path', 'signed_disk', 'signed_uploaded_by', 'signed_uploaded_at',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'snapshot' => 'array',
        'generated_at' => 'datetime',
        'signed_uploaded_at' => 'datetime',
    ];

    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function signedUploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_uploaded_by');
    }

    public function isSigned(): bool
    {
        return $this->signed_stored_path !== null;
    }
}
