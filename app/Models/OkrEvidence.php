<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    public function keyResult(): BelongsTo
    {
        return $this->belongsTo(OkrKeyResult::class, 'okr_key_result_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
