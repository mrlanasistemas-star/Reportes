<?php

namespace App\Services\Okr;

use App\Models\OkrKpi;
use App\Models\Period;
use App\Services\RadiografiaExportService;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Módulo OKR (08-sep-2026) — FUENTE ÚNICA para obtener el valor real de un KPI
 * automático. Nunca hace queries financieras propias: siempre delega en
 * RadiografiaExportService::buildSnapshot() — la MISMA función canónica que
 * usa Web (scopedData), Excel y PDF. Si Reportería no tiene radiografía
 * generada para el periodo, o el alcance no tiene datos, devuelve null — el
 * módulo OKR NUNCA inventa un valor.
 */
class OkrKpiValueResolver
{
    public function __construct(
        private readonly RadiografiaExportService $exportService,
        private readonly OkrKpiProviderRegistry $registry,
    ) {
    }

    /**
     * @param  string  $scopeType  'general'|'branch'|'employee'
     */
    public function getValue(OkrKpi $kpi, string $scopeType, ?int $branchId, ?int $employeeId, Period $period): ?float
    {
        if (!$kpi->isAutomatic() || !$kpi->supportsScope($scopeType)) {
            return null;
        }

        if ($this->registry->isSpecial($kpi->provider_key)) {
            return $this->resolveSpecial($kpi->provider_key, $scopeType, $branchId, $period);
        }

        $summaryKey = $this->registry->summaryKeyFor($kpi->provider_key);
        if ($summaryKey === null) {
            return null;
        }

        $snapshot = $this->buildSnapshotSafely($period, $this->configFor($scopeType, $branchId, $employeeId));
        if ($snapshot === null) {
            return null;
        }

        if ($scopeType !== self::scopeGeneral() && (($snapshot['scope']['available'] ?? true) === false)) {
            return null; // sin datos de radiografía para ese alcance en este periodo
        }

        return isset($snapshot['summary'][$summaryKey]) ? (float) $snapshot['summary'][$summaryKey] : null;
    }

    private static function scopeGeneral(): string
    {
        return OkrKpiProviderRegistry::SCOPE_GENERAL;
    }

    private function configFor(string $scopeType, ?int $branchId, ?int $employeeId): array
    {
        $config = ['scope' => $scopeType];
        if ($scopeType === OkrKpiProviderRegistry::SCOPE_BRANCH) {
            $config['branch_id'] = $branchId;
        }
        if ($scopeType === OkrKpiProviderRegistry::SCOPE_EMPLOYEE) {
            $config['employee_id'] = $employeeId;
        }

        return $config;
    }

    /**
     * buildSnapshot() lanza RuntimeException si el periodo no tiene radiografía
     * generada — un estado real y esperable (OKR puede crearse antes de que el
     * periodo tenga Reportería cerrada), nunca un error que deba romper la
     * pantalla OKR. Se registra en logs para diagnóstico, se devuelve null.
     */
    private function buildSnapshotSafely(Period $period, array $config): ?array
    {
        try {
            return $this->exportService->buildSnapshot($period, $config);
        } catch (RuntimeException $e) {
            Log::info('OkrKpiValueResolver: sin radiografía generada para el periodo — KPI automático no disponible todavía.', [
                'period_id' => $period->id, 'config' => $config, 'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Rotación de personal — branch/general únicamente (nunca por colaborador,
     * ver RadiographySnapshotBuilder: sections.rotation queda not_attributable
     * a nivel empleado). Lee sections.rotation.indice (general) o busca la fila
     * de la sucursal en sections.rotation.por_sucursal — MISMA agregación que
     * ya usa Reportería (buildRotationData()), nunca recalculada aquí.
     */
    private function resolveSpecial(string $providerKey, string $scopeType, ?int $branchId, Period $period): ?float
    {
        if ($providerKey !== 'reporteria.turnover' || $scopeType === OkrKpiProviderRegistry::SCOPE_EMPLOYEE) {
            return null;
        }

        // Siempre se pide el snapshot GENERAL (rotación no se filtra de forma
        // confiable al proyectar scope=branch) y se busca la fila de sucursal a
        // mano — evita depender de un comportamiento de filtrado no verificado.
        $snapshot = $this->buildSnapshotSafely($period, ['scope' => self::scopeGeneral()]);
        if ($snapshot === null) {
            return null;
        }

        $rotation = $snapshot['sections']['rotation'] ?? null;
        if (!is_array($rotation)) {
            return null;
        }

        if ($scopeType === OkrKpiProviderRegistry::SCOPE_GENERAL) {
            return isset($rotation['indice']) ? (float) $rotation['indice'] : null;
        }

        // scope=branch: busca por nombre de sucursal dentro de por_sucursal.
        $branchName = $branchId ? \App\Models\Branch::find($branchId)?->name : null;
        if (!$branchName) {
            return null;
        }
        $branchNameUpper = mb_strtoupper(trim($branchName));
        foreach ($rotation['por_sucursal'] ?? [] as $row) {
            if (mb_strtoupper(trim((string) ($row['sucursal'] ?? ''))) === $branchNameUpper) {
                return isset($row['indice_rotacion']) ? (float) $row['indice_rotacion'] : null;
            }
        }

        return null;
    }
}
