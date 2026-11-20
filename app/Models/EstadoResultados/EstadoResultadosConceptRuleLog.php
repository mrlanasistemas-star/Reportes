<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Bitácora append-only de cambios al catálogo de conceptos. */
class EstadoResultadosConceptRuleLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'estado_resultados_concept_rule_logs';

    protected $guarded = ['id'];

    protected $casts = ['before' => 'array', 'after' => 'array', 'created_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
