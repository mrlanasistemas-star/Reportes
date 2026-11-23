<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Colaborador (empleado) de la empresa.
 *
 * Tabla: `employees`. Cada fila es una persona identificada en las fuentes
 * (NOI, Lendus, ERP). Es la identidad a la que se atribuyen nómina, gastos,
 * colocación, cobranza y metas OKR; puede tener un User asociado para acceso.
 *
 * Columnas clave:
 * - `employee_code`: código del empleado en la fuente.
 * - `full_name`/`normalized_name`: nombre completo y normalizado (para emparejar).
 * - `first_name`/`paternal_last_name`/`maternal_last_name`: nombre desglosado.
 * - `position`: puesto.
 * - `is_active` (bool): si sigue activo.
 * - `source_system`: sistema de origen del registro.
 */
class Employee extends Model
{
    protected $fillable = [
        'employee_code',
        'full_name',
        'position',
        'normalized_name',
        'first_name',
        'paternal_last_name',
        'maternal_last_name',
        'is_active',
        'source_system',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Asignaciones del colaborador a sucursales por periodo. */
    public function employeeBranchAssignments(): HasMany
    {
        return $this->hasMany(EmployeeBranchAssignment::class);
    }

    /** Movimientos de nómina NOI del colaborador. */
    public function noiMovements(): HasMany
    {
        return $this->hasMany(NoiMovement::class);
    }

    /** Gastos atribuidos al colaborador. */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** Resúmenes mensuales (nómina + gastos) del colaborador. */
    public function monthlyEmployeeSummaries(): HasMany
    {
        return $this->hasMany(MonthlyEmployeeSummary::class);
    }

    /** 05-oct-2026 — identidad persistente User↔Employee (0/1 User por Employee, ver migración users.employee_id). */
    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    /** Fase 1 (API) — correspondencias con identificadores de sistemas externos. */
    public function externalIdentities(): HasMany
    {
        return $this->hasMany(EmployeeExternalIdentity::class);
    }
}
