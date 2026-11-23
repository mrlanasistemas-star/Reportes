<?php

namespace App\Models;

use App\Services\Catalog\BranchRouteCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sucursal/ruta operativa tal como aparece en las fuentes (Lendus, ERP, NOI).
 *
 * Tabla: `branches`. Cada fila es una sucursal o ruta a la que se atribuyen
 * colocación, cobranza, cartera, gastos y nómina. Puede apuntar a una Sucursal
 * institucional (`sucursal_id`) para el contrato de la API/OKR.
 *
 * Columnas clave:
 * - `code`/`name`/`normalized_name`: identificación (el nombre normalizado se usa para emparejar).
 * - `is_active` (bool): bandera propia; el estado efectivo es derivedActive().
 * - `sucursal_id`: sucursal institucional atribuida (nullable).
 */
class Branch extends Model
{
    protected $fillable = [
        'code',
        'name',
        'normalized_name',
        'is_active',
        'sucursal_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Sucursal institucional (una de las 11 de OKR) a la que se atribuyó este
     * branch. NULL cuando el branch no corresponde a ninguna de las 11
     * (San Juan del Río, Tenango del Valle, Aguascalientes, Corporativo,
     * agrupaciones de matriz/región, etc.): nunca se fabrica una sucursal.
     */
    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    /**
     * Estado operativo DERIVADO del branch:
     *   - false si el propio branch está inactivo (is_active = false);
     *   - cuando el nombre del branch coincide con una RUTA del catálogo
     *     (BranchRouteCatalog), se respeta además la bandera «activa» de esa
     *     ruta (una ruta inactiva vuelve inactivo al branch aunque is_active
     *     sea true);
     *   - en cualquier otro caso, el valor de is_active.
     *
     * No introduce un enum de estado nuevo: combina lo ya existente (is_active)
     * con el catálogo oficial de rutas.
     *
     * @return bool true si el branch se considera operativo.
     */
    public function derivedActive(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $route = BranchRouteCatalog::route($this->name);
        if ($route !== null) {
            return $route['active'];
        }

        return true;
    }

    /**
     * Lectura limpia para la capa de API: {id, codigo, nombre} de la sucursal
     * atribuida (o null), su región y el estado activo derivado del branch.
     *
     * @return array{sucursal: array{id:int, codigo:string, nombre:string}|null, region: string|null, activa_derivada: bool}
     */
    public function attributionSummary(): array
    {
        $sucursal = $this->sucursal;

        return [
            'sucursal' => $sucursal === null ? null : [
                'id' => $sucursal->id,
                'codigo' => $sucursal->codigo,
                'nombre' => $sucursal->nombre,
            ],
            'region' => $sucursal?->region,
            'activa_derivada' => $this->derivedActive(),
        ];
    }

    /** Asignaciones de colaboradores a esta sucursal por periodo. */
    public function employeeBranchAssignments(): HasMany
    {
        return $this->hasMany(EmployeeBranchAssignment::class);
    }

    /** Colocaciones de la sucursal. */
    public function placements(): HasMany
    {
        return $this->hasMany(Placement::class);
    }

    /** Pagos de cobranza de la sucursal. */
    public function recoveries(): HasMany
    {
        return $this->hasMany(Recovery::class);
    }

    /** Filas de cartera mensual de la sucursal. */
    public function portfolios(): HasMany
    {
        return $this->hasMany(Portfolio::class);
    }

    /** Gastos atribuidos a la sucursal. */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /** Resúmenes mensuales de colaboradores contabilizados en la sucursal. */
    public function monthlyEmployeeSummaries(): HasMany
    {
        return $this->hasMany(MonthlyEmployeeSummary::class);
    }
}
