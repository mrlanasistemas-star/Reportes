<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OkrCommitmentLetter extends Model
{
    protected $fillable = [
        'folio', 'okr_objective_id', 'snapshot', 'place', 'generated_by', 'generated_at',
        'stored_path', 'disk', 'signed_stored_path', 'signed_disk', 'signed_uploaded_by', 'signed_uploaded_at',
        'subject_employee_id', 'status', 'voided_at', 'voided_by', 'void_reason', 'replaces_letter_id',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'generated_at' => 'datetime',
        'signed_uploaded_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public const STATUS_ISSUED = 'issued';
    public const STATUS_VOIDED = 'voided';

    public function subjectEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'subject_employee_id');
    }

    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /** 15-oct-2026: un documento anulado conserva su PDF y folio — solo deja de ser vigente. */
    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

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
