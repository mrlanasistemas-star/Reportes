<?php

namespace App\Services\Radiography;

/**
 * Tono semántico de una variación (↑/↓) según la métrica — MISMA fuente que
 * resources/js/lib/comparative-metrics.ts (DIRECTIONS/toneFor()), para que Web y
 * TODOS los PDF coincidan siempre. Bug real documentado ahí y corregido en el PDF
 * comparativo (retoma 21-sep-2026): "var_pct > 0 = verde" es incorrecto para
 * Mora/OPEX/Cartera vencida/Rotación, donde BAJAR es la mejora. La cifra numérica
 * NUNCA cambia — solo qué color (verde/rojo/neutro) se le aplica.
 */
class RadiographyMetricToneHelper
{
    private const INCREASE_GOOD = 'increase_good';
    private const DECREASE_GOOD = 'decrease_good';
    private const NEUTRAL       = 'neutral';

    private const DIRECTIONS = [
        'Recuperación'                   => self::INCREASE_GOOD,
        'Ingreso base EBITDA'            => self::INCREASE_GOOD,
        'Colocación'                     => self::INCREASE_GOOD,
        'Valor cartera'                  => self::INCREASE_GOOD,
        'Cartera vencida'                => self::DECREASE_GOOD,
        'Mora %'                         => self::DECREASE_GOOD,
        'OPEX'                           => self::DECREASE_GOOD,
        'Gastos'                         => self::DECREASE_GOOD,
        'Gastos Totales'                 => self::DECREASE_GOOD,
        'EBITDA'                         => self::INCREASE_GOOD,
        'Margen EBITDA'                  => self::INCREASE_GOOD,
        'Préstamos activos (contratos)'  => self::INCREASE_GOOD,
        'Bajas del periodo'              => self::DECREASE_GOOD,
        'Rotación %'                     => self::DECREASE_GOOD,
        'Nómina y Capital Humano'        => self::NEUTRAL,
        'IMSS'                           => self::NEUTRAL,
        'Percepciones'                   => self::NEUTRAL,
        'Deducciones (informativo)'      => self::NEUTRAL,
        'Neto pagado a trabajadores'     => self::NEUTRAL,
        'Plantilla'                      => self::NEUTRAL,
        'Altas del periodo'              => self::NEUTRAL,
    ];

    /** 'good' | 'bad' | 'neutral' — nunca cambia la cifra, solo el tono a aplicar. */
    public static function toneFor(string $label, float $varPct): string
    {
        if ($varPct == 0.0) {
            return 'neutral';
        }

        $direction = self::DIRECTIONS[$label] ?? self::NEUTRAL;
        if ($direction === self::NEUTRAL) {
            return 'neutral';
        }

        $isIncrease = $varPct > 0;

        return ($direction === self::INCREASE_GOOD) === $isIncrease ? 'good' : 'bad';
    }

    /** Clase CSS lista para usar en los blades PDF ('pos'|'negv'|'') — ver pdf/layout.blade.php. */
    public static function toneClass(string $label, float $varPct): string
    {
        return match (self::toneFor($label, $varPct)) {
            'good' => 'pos',
            'bad'  => 'negv',
            default => '',
        };
    }
}
