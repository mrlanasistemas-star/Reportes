<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Carta compromiso de un objetivo OKR (documento PDF con folio).
 *
 * Tabla: `okr_commitment_letters`. Cada fila es una carta emitida para el
 * responsable de un objetivo, con la foto de las metas al momento de emitirla y,
 * opcionalmente, el PDF firmado que se sube después. Una carta anulada se
 * conserva (PDF y folio) pero deja de ser vigente.
 *
 * Columnas clave:
 * - `folio`: folio del documento.
 * - `snapshot` (array): datos del objetivo/KR congelados al emitir.
 * - `place`: lugar de emisión.
 * - `generated_by`/`generated_at`, `stored_path`/`disk`: emisión y PDF generado.
 * - `signed_stored_path`/`signed_disk`/`signed_uploaded_by`/`signed_uploaded_at`: PDF firmado.
 * - `subject_employee_id`: colaborador al que va dirigida.
 * - `status`: STATUS_ISSUED o STATUS_VOIDED; `voided_at`/`voided_by`/`void_reason`.
 * - `replaces_letter_id`: carta anterior a la que sustituye.
 * - `current_guard`: 1 solo mientras está vigente (ver booted()).
 */
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

    /** Carta emitida y vigente. */
    public const STATUS_ISSUED = 'issued';

    /** Carta anulada (se conserva, pero ya no es vigente). */
    public const STATUS_VOIDED = 'voided';

    /**
     * 4.9 (19-oct-2026): `current_guard` = 1 solo mientras la carta está
     * vigente; el índice único de BD impide dos cartas vigentes del mismo OKR
     * (doble clic, dos administradores a la vez). Anulada → NULL (se conserva).
     */
    protected static function booted(): void
    {
        static::saving(function (self $letter) {
            $letter->current_guard = $letter->status === self::STATUS_ISSUED ? 1 : null;
        });
    }

    /**
     * PDF emitido presente en el almacenamiento (nunca "emitida" sin PDF).
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

    /** Colaborador al que va dirigida la carta. */
    public function subjectEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'subject_employee_id');
    }

    /** Usuario que anuló la carta. */
    public function voidedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    /**
     * 15-oct-2026: un documento anulado conserva su PDF y folio — solo deja de ser vigente.
     *
     * @return bool true si la carta está anulada.
     */
    public function isVoided(): bool
    {
        return $this->status === self::STATUS_VOIDED;
    }

    /** Objetivo OKR de la carta. */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    /** Usuario que emitió la carta. */
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
