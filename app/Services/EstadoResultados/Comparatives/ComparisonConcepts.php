<?php

namespace App\Services\EstadoResultados\Comparatives;

/**
 * Catálogo de conceptos comparables del Estado de Resultados (modelo de UN solo EBITDA):
 *
 *   Ingresos operativos − OPEX (incluye nómina, corporativo y DG operativo como categorías) = EBITDA
 *   EBITDA − D&A = EBIT;  EBIT + Resultado financiero neto (con signo) = EBT;  EBT − ISR = Utilidad neta
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
    ];

    /** Márgenes = importe / ingresos operativos × 100. */
    public const MARGINS = [
        'margen_ebitda' => ['label' => 'Margen EBITDA', 'kind' => self::MARGIN, 'of' => 'ebitda'],
        'margen_ebit' => ['label' => 'Margen EBIT', 'kind' => self::MARGIN, 'of' => 'ebit'],
        'margen_neto' => ['label' => 'Margen neto', 'kind' => self::MARGIN, 'of' => 'utilidad_neta'],
    ];

    /** Orden de presentación de la comparación por concepto (importes y márgenes intercalados). */
    public const ORDER = [
        'ingresos_operativos', 'opex_total', 'ebitda', 'margen_ebitda', 'depreciacion_amortizacion',
        'ebit', 'margen_ebit', 'resultado_financiero_neto', 'ebt', 'isr', 'utilidad_neta', 'margen_neto',
    ];

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

    public static function meta(string $key): array
    {
        return self::AMOUNTS[$key] ?? self::MARGINS[$key] ?? throw new \InvalidArgumentException('Concepto desconocido: '.$key);
    }

    /** @return list<array{key:string,label:string,kind:string,kind_label:string}> */
    public static function catalog(): array
    {
        return array_map(fn ($k) => [
            'key' => $k, 'label' => self::meta($k)['label'], 'kind' => self::meta($k)['kind'],
            'kind_label' => self::KIND_LABELS[self::meta($k)['kind']],
        ], self::ORDER);
    }
}
