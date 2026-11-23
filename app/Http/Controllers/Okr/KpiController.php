<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Okr\StoreKpiRequest;
use App\Models\OkrKpi;
use App\Services\Okr\OkrKpiProviderRegistry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Módulo OKR (08-sep-2026) — administración del catálogo KPI (sección 51 del
 * pedido). provider_key SIEMPRE viene de OkrKpiProviderRegistry (validado en
 * StoreKpiRequest) — nunca texto libre de PHP desde la UI.
 */
class KpiController extends Controller
{
    use AuthorizesRequests;

    /**
     * Página Inertia `Okr/KpiCatalog` con el catálogo KPI y los proveedores
     * (provider_key) registrados que pueden asignarse.
     *
     * @return Response Página con `kpis` y `availableProviders`.
     *
     * @throws AuthorizationException Si no tiene `okr.kpi.manage`.
     */
    public function index(): Response
    {
        $this->authorize('okr.kpi.manage');

        return Inertia::render('Okr/KpiCatalog', [
            'kpis' => OkrKpi::query()->orderBy('name')->get(),
            'availableProviders' => app(OkrKpiProviderRegistry::class)->registeredKeys(),
        ]);
    }

    /**
     * Crea un KPI del catálogo (escribe en okr_kpis). La autorización y la
     * validación viven en StoreKpiRequest.
     *
     * @param  StoreKpiRequest  $request  Datos validados del KPI.
     * @return RedirectResponse Regresa con mensaje de éxito.
     */
    public function store(StoreKpiRequest $request): RedirectResponse
    {
        OkrKpi::query()->create($request->validated());

        return back()->with('success', 'KPI creado.');
    }

    /**
     * Actualiza un KPI existente del catálogo (escribe en okr_kpis).
     *
     * @param  StoreKpiRequest  $request  Datos validados del KPI.
     * @param  OkrKpi  $kpi  KPI a modificar.
     * @return RedirectResponse Regresa con mensaje de éxito.
     */
    public function update(StoreKpiRequest $request, OkrKpi $kpi): RedirectResponse
    {
        $kpi->update($request->validated());

        return back()->with('success', 'KPI actualizado.');
    }
}
