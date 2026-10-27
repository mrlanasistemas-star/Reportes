<?php

namespace App\Models;

use App\Services\Catalog\BranchRouteCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function employeeBranchAssignments(): HasMany
    {
        return $this->hasMany(EmployeeBranchAssignment::class);
    }

    public function placements(): HasMany
    {
        return $this->hasMany(Placement::class);
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(Recovery::class);
    }

    public function portfolios(): HasMany
    {
        return $this->hasMany(Portfolio::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function monthlyEmployeeSummaries(): HasMany
    {
        return $this->hasMany(MonthlyEmployeeSummary::class);
    }
}
