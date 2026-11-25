<?php

namespace App\Services\EstadoResultados\Comparatives;

/**
 * Catálogo de conceptos comparables del Estado de Resultados (modelo de UN solo EBITDA):
 *
 *   Ingresos operativos − OPEX (incluye nómina y corporativo como categorías) = EBITDA
 *   EBITDA − D&A = EBIT;  EBIT + Resultado financiero neto (con signo) = EBT;  EBT − ISR = Utilidad neta
 *   Utilidad neta (o EBT si el ISR está pendiente) − Retiros DG y socios = Resultado después de retiros
 *   (25-nov-2026: DG y socios salen del OPEX/EBITDA; los dos últimos conceptos sólo existen en consolidado).
 *
 * `kind` define la dirección favorable:
 *   income        → subir es favorable
 *   expense       → subir es DESFAVORABLE (OPEX, D&A, ISR, categorías de OPEX)
 *   result        → subir es favorable (EBITDA, EBIT, EBT, utilidad neta)
 *   signed_result → importe con signo (gastos negativos, ingresos positivos): subir es favorable
 *   margin        → porcentaje sobre ingresos: subir es favorable; la variación es en puntos porcentuales
 */
final class ComparisonConcepts
{
    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    public const RESULT = 'result';

    public const SIGNED_RESULT = 'signed_result';

    public const MARGIN = 'margin';

    /** Importes del modelo (results.*) en el orden de presentación. */
    public const AMOUNTS = [
        'ingresos_operativos' => ['label' => 'Ingresos operativos', 'kind' => self::INCOME],
        'opex_total' => ['label' => 'OPEX total', 'kind' => self::EXPENSE],
        'ebitda' => ['label' => 'EBITDA', 'kind' => self::RESULT],
        'depreciacion_amortizacion' => ['label' => 'Depreciación y amortización', 'kind' => self::EXPENSE],
        'ebit' => ['label' => 'EBIT', 'kind' => self::RESULT],
        'resultado_financiero_neto' => ['label' => 'Resultado financiero y no operativo neto', 'kind' => self::SIGNED_RESULT],
        'ebt' => ['label' => 'EBT (utilidad antes de impuestos)', 'kind' => self::RESULT],
        'isr' => ['label' => 'ISR', 'kind' => self::EXPENSE],
        'utilidad_neta' => ['label' => 'Utilidad neta', 'kind' => self::RESULT],
        'retiros_dg_socios' => ['label' => 'Retiros DG y socios', 'kind' => self::EXPENSE],
        'resultado_despues_retiros' => ['label' => 'Resultado después de retiros', 'kind' => self::RESULT],
    ];

    /** Márgenes = importe / ingresos operativos × 100. */
    public const MARGINS = [
        'margen_ebitda' => ['label' => 'Margen EBITDA', 'kind' => self::MARGIN, 'of' => 'ebitda'],
        'margen_ebit' => ['label' => 'Margen EBIT', 'kind' => self::MARGIN, 'of' => 'ebit'],
        'margen_neto' => ['label' => 'Margen neto', 'kind' => self::MARGIN, 'of' => 'utilidad_neta'],
        'margen_despues_retiros' => ['label' => 'Margen después de retiros', 'kind' => self::MARGIN, 'of' => 'resultado_despues_retiros'],
    ];

    /** Orden de presentación de la comparación por concepto (importes y márgenes intercalados). */
    public const ORDER = [
        'ingresos_operativos', 'opex_total', 'ebitda', 'margen_ebitda', 'depreciacion_amortizacion',
        'ebit', 'margen_ebit', 'resultado_financiero_neto', 'ebt', 'isr', 'utilidad_neta', 'margen_neto',
        'retiros_dg_socios', 'resultado_despues_retiros', 'margen_despues_retiros',
    ];

    /** Conceptos que sólo existen en el consolidado (por sucursal no se presentan). */
    public const CONSOLIDATED_ONLY = ['retiros_dg_socios', 'resultado_despues_retiros', 'margen_despues_retiros'];

    /** Conceptos con información de Dirección General: sin `ver_dg` se marcan restringidos (el total se conserva). */
    public const SENSITIVE_CONCEPTS = ['retiros_dg_socios' => 'dg', 'resultado_despues_retiros' => 'dg', 'margen_despues_retiros' => 'dg'];

    /** Categorías de OPEX de DG en periodos calculados ANTES del 25-nov-2026 (se reexpresan como retiros). */
    public const LEGACY_DG_CATEGORIES = ['dg', 'opex_dg'];

    /** Categorías de OPEX que se gestionan de forma CENTRALIZADA (nunca se asignan a sucursales). */
    public const CENTRALIZED_CATEGORIES = ['opex_corporativo', 'nomina_corporativa', 'dg', 'opex_dg'];

    /** Categorías con detalle sensible (la redacción conserva sólo el total). */
    public const SENSITIVE = [
        'nomina_sucursales' => 'nomina', 'nomina_corporativa' => 'nomina', 'dg' => 'dg', 'opex_dg' => 'dg',
    ];

    public const KIND_LABELS = [
        self::INCOME => 'Ingreso (subir es favorable)',
        self::EXPENSE => 'Gasto (subir es desfavorable)',
        self::RESULT => 'Resultado (subir es favorable)',
        self::SIGNED_RESULT => 'Resultado con signo (subir es favorable)',
        self::MARGIN => 'Margen (subir es favorable; variación en puntos porcentuales)',
    ];

    public const ASSESSMENT_LABELS = [
        'favorable' => 'Favorable',
        'desfavorable' => 'Desfavorable',
        'neutral' => 'Sin cambio',
    ];

    /**
     * Metadatos (label, kind y, en márgenes, 'of') de un concepto importe o margen.
     *
     * @param  string  $key  clave del concepto (de AMOUNTS o MARGINS)
     * @return array{label:string, kind:string, of?:string}
     *
     * @throws \InvalidArgumentException si el concepto no existe
     */
    public static function meta(string $key): array
    {
        return self::AMOUNTS[$key] ?? self::MARGINS[$key] ?? throw new \InvalidArgumentException('Concepto desconocido: '.$key);
    }

    /**
     * Catálogo público de conceptos en orden de presentación (lo envía ComparisonService al frontend).
     *
     * @return list<array{key:string,label:string,kind:string,kind_label:string,consolidated_only:bool,sensitive:?string}>
     */
    public static function catalog(): array
    {
        return array_map(fn ($k) => [
            'key' => $k, 'label' => self::meta($k)['label'], 'kind' => self::meta($k)['kind'],
            'kind_label' => self::KIND_LABELS[self::meta($k)['kind']],
            'consolidated_only' => in_array($k, self::CONSOLIDATED_ONLY, true),
            'sensitive' => self::SENSITIVE_CONCEPTS[$k] ?? null,
        ], self::ORDER);
    }
}
