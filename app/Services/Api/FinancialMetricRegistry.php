<?php

namespace App\Services\Api;

/**
 * Fase 1.3 — catálogo ESTABLE de métricas expuestas por la API. El `code`
 * público es independiente del nombre interno de `summary` en
 * RadiografiaExportService::buildSnapshot() a propósito: si Reportería
 * renombra una clave interna, el contrato de la API no se rompe — solo
 * cambia el mapeo aquí (ver FinancialMetricResolver).
 *
 * `source`:
 *  - 'summary'  → value directo de buildSnapshot()['summary'][$summaryKey]
 *  - 'derived'  → combinación aritmética trivial de dos claves de summary
 *                 (nunca una regla de negocio nueva — documentado por métrica)
 *  - 'special'  → resuelto aparte (rotación, ver FinancialMetricResolver)
 *  - 'legacy_section' → sections.efectividad_cobranza (versión YA EXISTENTE,
 *                 nunca la "Aprobada" — ver docs/efectividad-cobranza-aprobada.md)
 */
class FinancialMetricRegistry
{
    public const DEFINITION_VERSION = 'v1';

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            'ebitda' => [
                'label' => 'EBITDA', 'unit' => 'MXN', 'direction' => 'increase',
                'source' => 'summary', 'summary_key' => 'ebitda_final',
                'scopes' => ['general', 'branch', 'employee'],
            ],
            'ebitda_margin' => [
                'label' => 'Margen EBITDA', 'unit' => 'percentage', 'direction' => 'increase',
                'source' => 'summary', 'summary_key' => 'margen_ebitda',
                'scopes' => ['general', 'branch', 'employee'],
            ],
            'opex' => [
                'label' => 'OPEX', 'unit' => 'MXN', 'direction' => 'decrease',
                'source' => 'summary', 'summary_key' => 'opex_total',
                'scopes' => ['general', 'branch', 'employee'],
            ],
            'placement' => [
                'label' => 'Colocación', 'unit' => 'MXN', 'direction' => 'increase',
                'source' => 'summary', 'summary_key' => 'placement_total',
                'scopes' => ['general', 'branch', 'employee'],
            ],
            'recovery' => [
                'label' => 'Recuperación', 'unit' => 'MXN', 'direction' => 'increase',
                'source' => 'summary', 'summary_key' => 'recovery_total',
                'scopes' => ['general', 'branch', 'employee'],
            ],
            'mora' => [
                'label' => 'Mora', 'unit' => 'percentage', 'direction' => 'decrease',
                'source' => 'summary', 'summary_key' => 'mora_index',
                'scopes' => ['general', 'branch', 'employee'],
                'definition' => 'Cartera vencida / cartera total del periodo (ver RadiographySnapshotBuilder).',
            ],
            'portfolio_total' => [
                'label' => 'Cartera total', 'unit' => 'MXN', 'direction' => 'neutral',
                'source' => 'summary', 'summary_key' => 'portfolio_total',
                'scopes' => ['general', 'branch', 'employee'],
            ],
            'portfolio_overdue' => [
                'label' => 'Cartera vencida', 'unit' => 'MXN', 'direction' => 'decrease',
                'source' => 'summary', 'summary_key' => 'overdue_portfolio',
                'scopes' => ['general', 'branch', 'employee'],
            ],
            'portfolio_current' => [
                'label' => 'Cartera vigente', 'unit' => 'MXN', 'direction' => 'increase',
                'source' => 'derived', 'derived_from' => ['portfolio_total', 'overdue_portfolio'],
                'scopes' => ['general', 'branch', 'employee'],
                'definition' => 'portfolio_total - portfolio_overdue (resta aritmética directa, no una regla nueva).',
            ],
            'turnover' => [
                'label' => 'Rotación de personal', 'unit' => 'percentage', 'direction' => 'decrease',
                'source' => 'special',
                'scopes' => ['general', 'branch'],
                'definition' => 'sections.rotation — branch/general únicamente, nunca por colaborador (not_attributable a nivel individual).',
            ],
            'collections_effectiveness_legacy' => [
                'label' => 'Efectividad de cobranza (histórica)', 'unit' => 'percentage', 'direction' => 'increase',
                'source' => 'legacy_section',
                'scopes' => ['general', 'branch', 'employee'],
                'definition' => 'Vigente/Atrasado/Vencido + % recuperado de mora respecto al mes anterior. Versión YA EXISTENTE — NO la calculadora "Aprobada" (bloqueada, ver docs/efectividad-cobranza-aprobada.md).',
            ],
        ];
    }

    public static function exists(string $code): bool
    {
        return array_key_exists($code, self::definitions());
    }

    public static function get(string $code): ?array
    {
        return self::definitions()[$code] ?? null;
    }
}
