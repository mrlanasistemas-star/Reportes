<?php

namespace App\Http\Controllers\Okr\Concerns;

use App\Models\Branch;
use App\Models\OkrObjective;
use App\Services\Access\DataScopeService;
use App\Services\Catalog\BranchRouteCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Módulo OKR — sucursales operativas. 16-oct-2026: el alcance OKR son las
 * ONCE sucursales del catálogo oficial (BranchRouteCatalog::OKR_BRANCHES);
 * Reportería conserva su propia lista de 13 (OperativeBranchService) para la
 * radiografía. Los filtros de seguimiento/histórico agregan cualquier otra
 * sucursal que ya tenga OKR registrados, para no ocultar históricos.
 */
trait ResolvesOperativeBranches
{
    /**
     * Nombres de las sucursales operativas del alcance OKR.
     *
     * @return array<int, string> Lista fija de BranchRouteCatalog::OKR_BRANCHES.
     */
    protected function operativeBranchNames(): array
    {
        return BranchRouteCatalog::OKR_BRANCHES;
    }

    /**
     * Sucursales para NUEVAS asignaciones (solo las once operativas), limitadas
     * al alcance de datos del usuario autenticado.
     *
     * @return Collection<int, Branch> Sucursales (id, name) ordenadas por nombre.
     */
    protected function assignableBranches(): Collection
    {
        return $this->withinDataScope(Branch::whereIn('name', $this->operativeBranchNames()))->orderBy('name')->get(['id', 'name']);
    }

    /**
     * Sucursales para filtros: las once + las que ya tienen OKR históricos
     * (incluidos OKR eliminados lógicamente), limitadas al alcance del usuario.
     *
     * @return Collection<int, Branch> Sucursales (id, name) ordenadas por nombre.
     */
    protected function filterBranches(): Collection
    {
        $historic = OkrObjective::withTrashed()->whereNotNull('branch_id')->distinct()->pluck('branch_id');

        return $this->withinDataScope(Branch::query())
            ->where(fn ($q) => $q->whereIn('name', $this->operativeBranchNames())->orWhereIn('id', $historic))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Fase 6.4 — restringe una consulta de sucursales al alcance de datos del
     * usuario autenticado (DataScopeService). Alcance general (null) = todas.
     *
     * @param  Builder  $query  Consulta sobre `branches`.
     * @return Builder La misma consulta, con `whereIn('id', ...)` si aplica.
     */
    protected function withinDataScope(Builder $query): Builder
    {
        $user = auth()->user();
        $allowed = $user ? app(DataScopeService::class)->allowedBranchIds($user) : null;

        return $allowed === null ? $query : $query->whereIn('id', $allowed);
    }
}
