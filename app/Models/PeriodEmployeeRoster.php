<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Plantilla (roster) de colaboradores de un periodo.
 *
 * Tabla: `period_employee_rosters`. Cada fila es un colaborador considerado en el
 * periodo, deduplicado por nombre (PeriodEmployeeRosterService) a partir de la
 * nómina NOI normal y fiscal. Es la base de IMSS y Rotación (altas/bajas).
 *
 * Columnas clave:
 * - `employee_key`/`nombre_normalizado`/`nombre_original`: identidad del colaborador.
 * - `branch_id`/`branch_name`/`is_branch_operativa` (bool): sucursal y si es operativa.
 * - `source`: fuente que lo aportó.
 * - `appears_in_nomina_normal`/`appears_in_nomina_fiscal` (bool): en qué nómina aparece.
 * - `is_active_for_period` (bool): si cuenta como activo en el periodo.
 * - `movement_type`: tipo de movimiento (alta, baja, permanencia...).
 * - `reconciliation_status`/`reconciliation_note`: estado de conciliación.
 * - `movement_evidence` (array): evidencia de la alta/baja.
 * - `requires_review` (bool): si necesita revisión manual.
 */
class PeriodEmployeeRoster extends Model
{
    protected $fillable = [
        'period_id',
        'employee_id',
        'employee_key',
        'nombre_normalizado',
        'nombre_original',
        'branch_id',
        'branch_name',
        'is_branch_operativa',
        'source',
        'appears_in_nomina_normal',
        'appears_in_nomina_fiscal',
        'is_active_for_period',
        'movement_type',
        'reconciliation_status',
        'reconciliation_note',
        'movement_evidence',
        'requires_review',
    ];

    protected $casts = [
        'is_branch_operativa' => 'boolean',
        'appears_in_nomina_normal' => 'boolean',
        'appears_in_nomina_fiscal' => 'boolean',
        'is_active_for_period' => 'boolean',
        'movement_evidence' => 'array',
        'requires_review' => 'boolean',
    ];

    /** Periodo de la plantilla. */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** Registro Employee vinculado (si se resolvió). */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
