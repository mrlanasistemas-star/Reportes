<?php

namespace App\Services\Okr;

/**
 * Módulo OKR (08-sep-2026) — registro ÚNICO de providers KPI (ver docs/MANUAL-DESARROLLADOR.md,
 * sección "cómo agregar un KPI nuevo"). Deliberadamente un array PHP fijo, NUNCA
 * editable como código desde la UI de administración (sección 51 del pedido:
 * "provider interno no debe poder escribirse libremente como PHP desde UI") —
 * la pantalla de catálogo solo puede ELEGIR entre las claves aquí registradas.
 *
 * Cada provider_key mapea a una clave de `RadiografiaExportService::
 * buildSnapshot($period, $config)['summary']` — la MISMA fuente que ya
 * consumen scopedData() (Web), Excel y PDF para general/sucursal/colaborador
 * (ver RadiographySnapshotBuilder::summaryFromRow()/buildEmployeesGestores()):
 * garantiza paridad EBITDA/OPEX/etc. por construcción, nunca por casualidad.
 *
 * 'reporteria.turnover' es un provider ESPECIAL (no vive en `summary`, sino en
 * `sections.rotation` — es branch/general únicamente, nunca por colaborador,
 * ver RadiographySnapshotBuilder — sections.rotation queda not_attributable a
 * nivel empleado) — resuelto aparte en OkrKpiValueResolver::resolveTurnover().
 */
class OkrKpiProviderRegistry
{
    /** Alcances posibles de un KPI: general (empresa), sucursal o colaborador. */
    public const SCOPE_GENERAL = 'general';

    public const SCOPE_BRANCH = 'branch';

    public const SCOPE_EMPLOYEE = 'employee';

    /** provider_key => summary key en buildSnapshot()['summary']. */
    private const SUMMARY_KEY_PROVIDERS = [
        'reporteria.ebitda' => 'ebitda_final',
        'reporteria.ebitda_margin' => 'margen_ebitda',
        'reporteria.opex' => 'opex_total',
        'reporteria.placement' => 'placement_total',
        'reporteria.recovery' => 'recovery_total',
        'reporteria.mora' => 'mora_index',
        'reporteria.portfolio' => 'portfolio_total',
        'reporteria.overdue_portfolio' => 'overdue_portfolio',
    ];

    /** Providers que no se leen de `summary` y tienen resolución propia (rotación). */
    private const SPECIAL_PROVIDERS = ['reporteria.turnover'];

    /**
     * Lista todas las provider_key registradas (normales + especiales).
     *
     * @return array<int, string> Todas las provider_key registradas (para el catálogo admin).
     */
    public function registeredKeys(): array
    {
        return array_values(array_merge(array_keys(self::SUMMARY_KEY_PROVIDERS), self::SPECIAL_PROVIDERS));
    }

    /**
     * Indica si la provider_key está registrada (valida lo que elige el catálogo admin).
     *
     * @param  string|null  $providerKey  Clave del provider a verificar.
     * @return bool true si existe en el registro.
     */
    public function isKnown(?string $providerKey): bool
    {
        return $providerKey !== null && in_array($providerKey, $this->registeredKeys(), true);
    }

    /**
     * Indica si el provider requiere resolución especial (no vive en `summary`).
     *
     * @param  string|null  $providerKey  Clave del provider.
     * @return bool true para 'reporteria.turnover'.
     */
    public function isSpecial(?string $providerKey): bool
    {
        return $providerKey !== null && in_array($providerKey, self::SPECIAL_PROVIDERS, true);
    }

    /**
     * Clave de `buildSnapshot()['summary']` de la que se lee el valor del provider.
     *
     * @param  string|null  $providerKey  Clave del provider.
     * @return string|null Clave del summary, o null si no es un provider de summary.
     */
    public function summaryKeyFor(?string $providerKey): ?string
    {
        return self::SUMMARY_KEY_PROVIDERS[$providerKey] ?? null;
    }
}
