<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Archivo de evidencia subido para un objetivo o resultado clave OKR.
 *
 * Tabla: `okr_evidences`. Cada fila es un archivo (foto, PDF, etc.) que respalda
 * el avance de una semana.
 *
 * Columnas clave:
 * - `okr_key_result_id`: KR al que aplica (opcional; si es null aplica al objetivo).
 * - `week_number`: semana a la que corresponde la evidencia.
 * - `original_name`/`stored_path`/`disk`: nombre original y ubicación en almacenamiento.
 * - `mime_type`/`size_bytes`: tipo y tamaño del archivo.
 * - `uploaded_by`/`comment`: quién lo subió y comentario.
 */
class OkrEvidence extends Model
{
    // "Evidence" es incontable para el pluralizador de Eloquent (como "sheep") —
    // adivinaría la tabla "okr_evidence" (singular), pero la migración creó
    // "okr_evidences" (plural, consistente con el resto del módulo). Se fija
    // explícito para no depender de esa adivinanza.
    protected $table = 'okr_evidences';

    protected $fillable = [
        'okr_objective_id', 'okr_key_result_id', 'week_number',
        'original_name', 'stored_path', 'disk', 'mime_type', 'size_bytes',
        'uploaded_by', 'comment',
    ];

    /** Objetivo OKR al que pertenece la evidencia. */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    /** Resultado clave al que aplica la evidencia (si se especificó). */
    public function keyResult(): BelongsTo
    {
        return $this->belongsTo(OkrKeyResult::class, 'okr_key_result_id');
    }

    /** Usuario que subió el archivo. */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
