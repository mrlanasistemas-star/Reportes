<?php

namespace App\Http\Controllers\Okr\Concerns;

use App\Models\Branch;
use App\Models\OkrObjective;
use App\Services\Catalog\BranchRouteCatalog;

/**
 * Módulo OKR — sucursales operativas. 16-oct-2026: el alcance OKR son las
 * ONCE sucursales del catálogo oficial (BranchRouteCatalog::OKR_BRANCHES);
 * Reportería conserva su propia lista de 13 (OperativeBranchService) para la
 * radiografía. Los filtros de seguimiento/histórico agregan cualquier otra
 * sucursal que ya tenga OKR registrados, para no ocultar históricos.
 */
trait ResolvesOperativeBranches
{
    /** @return array<int, string> */
    protected function operativeBranchNames(): array
    {
        return BranchRouteCatalog::OKR_BRANCHES;
    }

    /** Sucursales para NUEVAS asignaciones (solo las once operativas). */
    protected function assignableBranches(): \Illuminate\Support\Collection
    {
        return $this->withinDataScope(Branch::whereIn('name', $this->operativeBranchNames()))->orderBy('name')->get(['id', 'name']);
    }

    /** Sucursales para filtros: las once + las que ya tienen OKR históricos. */
    protected function filterBranches(): \Illuminate\Support\Collection
    {
        $historic = OkrObjective::withTrashed()->whereNotNull('branch_id')->distinct()->pluck('branch_id');

        return $this->withinDataScope(Branch::query())
            ->where(fn ($q) => $q->whereIn('name', $this->operativeBranchNames())->orWhereIn('id', $historic))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /** Fase 6.4 — solo las sucursales dentro del alcance del usuario (general = todas). */
    protected function withinDataScope(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $user = auth()->user();
        $allowed = $user ? app(\App\Services\Access\DataScopeService::class)->allowedBranchIds($user) : null;

        return $allowed === null ? $query : $query->whereIn('id', $allowed);
    }
}
