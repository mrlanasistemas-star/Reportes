<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Regla del catálogo de conceptos (global): texto del estado de cuenta → concepto del reporte.
 * La lógica (normalización, coincidencia, bitácora) vive en ConceptCatalogService.
 */
class EstadoResultadosConceptRule extends Model
{
    use SoftDeletes;

    protected $table = 'estado_resultados_concept_rules';

    protected $guarded = ['id'];

    protected $casts = [
        'fields' => 'array',
        'active' => 'boolean',
        'priority' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
