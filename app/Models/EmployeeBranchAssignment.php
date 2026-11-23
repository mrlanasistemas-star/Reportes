<?php

namespace App\Models;

use App\Enums\MatchType;
use App\Enums\SourceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asignación de un colaborador a una sucursal en un periodo.
 *
 * Tabla: `employee_branch_assignments`. Cada fila dice en qué sucursal se
 * considera a un empleado durante un periodo y de dónde salió esa conclusión;
 * con ella se atribuyen nómina, gastos y resultados por sucursal. Se administra
 * desde EmployeeBranchAssignmentController.
 *
 * Columnas clave:
 * - `source_type` (enum SourceType): fuente que originó la asignación.
 * - `source_reference`: referencia dentro de esa fuente.
 * - `match_type` (enum MatchType): cómo se hizo el emparejamiento.
 * - `confidence` (decimal:2): confianza del emparejamiento.
 * - `was_manual_reviewed` (bool): si un usuario la revisó/confirmó manualmente.
 * - `notes`: notas de la revisión.
 */
class EmployeeBranchAssignment extends Model
{
    protected $fillable = [
        'period_id',
        'employee_id',
        'branch_id',
        'source_type',
        'source_reference',
        'match_type',
        'confidence',
        'was_manual_reviewed',
        'notes',
    ];

    protected $casts = [
        'confidence' => 'decimal:2',
        'was_manual_reviewed' => 'boolean',
        'source_type' => SourceType::class,
        'match_type' => MatchType::class,
    ];

    /** Periodo en que aplica la asignación. */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** Colaborador asignado. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Sucursal a la que se asigna el colaborador. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
