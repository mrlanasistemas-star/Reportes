<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class OkrWarning extends Model
{
    protected $fillable = [
        'folio', 'okr_objective_id', 'week_number', 'week_start', 'week_end', 'snapshot',
        'corrective_actions', 'observations', 'generated_by', 'generated_at',
        'stored_path', 'disk', 'signed_stored_path', 'signed_disk', 'signed_uploaded_by', 'signed_uploaded_at',
        'subject_employee_id', 'status', 'voided_at', 'voided_by', 'void_reason',
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'snapshot' => 'array',
        'generated_at' => 'datetime',
        'signed_uploaded_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public const STATUS_ISSUED = 'issued';
    public const STATUS_VOIDED = 'voided';

    /**
     * 4.9 (19-oct-2026): `current_guard` = 1 solo mientras el Warning está
     * vigente; el índice único (objetivo, semana, current_guard) impide dos
     * Warnings vigentes de la misma semana. Anulado → NULL (se conserva).
     */
    protected static function booted(): void
    {
        static::saving(function (self $warning) {
            $warning->current_guard = $warning->status === self::STATUS_ISSUED ? 1 : null;
        });
    }

    /** PDF emitido presente en el almacenamiento (nunca "emitido" sin PDF). */
    public function hasStoredFile(): bool
    {
        return is_string($this->stored_path) && $this->stored_path !== ''
            && Storage::disk($this->disk ?: 'local')->exists($this->stored_path);
    }

    public function hasSignedFile(): bool
    {
        return $this->signed_stored_path !== null
            && Storage::disk($this->signed_disk ?: 'local')->exists($this->signed_stored_path);
    }

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
