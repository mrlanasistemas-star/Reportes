<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Warning (llamada de atención) semanal de un objetivo OKR (documento PDF con folio).
 *
 * Tabla: `okr_warnings`. Cada fila es un aviso formal emitido al responsable de un
 * objetivo por bajo desempeño en una semana, con la foto de los datos, las
 * acciones correctivas y observaciones; puede subirse después el PDF firmado.
 * Un warning anulado se conserva pero deja de ser vigente.
 *
 * Columnas clave:
 * - `folio`, `week_number`/`week_start`/`week_end` (date): folio y semana.
 * - `snapshot` (array): datos del objetivo/KR congelados al emitir.
 * - `corrective_actions`/`observations`: textos del documento.
 * - `generated_by`/`generated_at`, `stored_path`/`disk`: emisión y PDF.
 * - `signed_*`: PDF firmado y quién/cuándo lo subió.
 * - `subject_employee_id`: colaborador al que va dirigido.
 * - `status`: STATUS_ISSUED o STATUS_VOIDED; `voided_at`/`voided_by`/`void_reason`.
 * - `current_guard`: 1 solo mientras está vigente (ver booted()).
 */
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

    /** Warning emitido y vigente. */
    public const STATUS_ISSUED = 'issued';

    /** Warning anulado (se conserva, pero ya no es vigente). */
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

    /**
     * PDF emitido presente en el almacenamiento (nunca "emitido" sin PDF).
     * Consulta el disco (por defecto `local`) para verificar que el archivo existe.
     *
     * @return bool true si hay ruta y el archivo existe en el disco.
     */
    public function hasStoredFile(): bool
    {
        return is_string($this->stored_path) && $this->stored_path !== ''
            && Storage::disk($this->disk ?: 'local')->exists($this->stored_path);
    }

    /**
     * Indica si el PDF firmado existe físicamente en el almacenamiento.
     *
     * @return bool true si hay ruta firmada y el archivo existe en el disco.
     */
    public function hasSignedFile(): bool
    {
        return $this->signed_stored_path !== null
            && Storage::disk($this->signed_disk ?: 'local')->exists($this->signed_stored_path);
    }

    /** Colaborador al que va dirigido el warning. */
    public function subjectEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'subject_employee_id');
    }

    /** Usuario que anuló el warning. */
    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * 15-oct-2026: un documento anulado conserva su PDF y folio — solo deja de ser vigente.
     *
     * @return bool true si el warning está anulado.
     */
    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    /** Objetivo OKR del warning. */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    /** Usuario que emitió el warning. */
    public function generatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /** Usuario que subió el PDF firmado. */
    public function signedUploadedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_uploaded_by');
    }

    /**
     * Indica si ya se registró el PDF firmado (solo revisa la ruta, no el disco).
     *
     * @return bool true si `signed_stored_path` tiene valor.
     */
    public function isSigned(): bool
    {
        return $this->signed_stored_path !== null;
    }
}
