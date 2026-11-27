<?php

namespace App\Services\HardData;

/** Etiquetas en español de las llaves de la API v1 (espejo de resources/js/lib/hardData.ts). */
final class HardDataLabels
{
    public const BUCKETS = [
        'al_corriente' => 'Al corriente', 'mora_1_30' => '1–30 días', 'mora_31_60' => '31–60 días',
        'mora_61_90' => '61–90 días', 'mora_91_120' => '91–120 días', 'mora_120_plus' => '+120 días',
    ];

    public const BUCKET_COLORS = [
        'al_corriente' => '#10b981', 'mora_1_30' => '#84cc16', 'mora_31_60' => '#fbbf24',
        'mora_61_90' => '#f97316', 'mora_91_120' => '#f43f5e', 'mora_120_plus' => '#be123c',
    ];

    public const RECOVERY = [
        'capital' => 'Capital', 'interest' => 'Interés', 'tax' => 'Impuesto', 'late_charges' => 'Moratorios',
        'additional_charges' => 'Cargos adicionales', 'surplus' => 'Excedente', 'initial_charges' => 'Cargos de inicio',
        'opening_fee' => 'Comisión de apertura', 'crece_insurance_recognized' => 'Seguro CRECE', 'other' => 'Otros',
    ];

    public const PAYROLL = [
        'sueldo' => 'Sueldo', 'comisiones' => 'Comisiones', 'bonos' => 'Bonos', 'bonos_aceleradores' => 'Bonos aceleradores',
        'vacaciones' => 'Vacaciones', 'prima_vacacional' => 'Prima vacacional', 'otras_percepciones' => 'Otras percepciones',
    ];

    /** Color de la categoría EBITDA (mismos tonos que los chips de la pantalla). */
    public const CATEGORY_COLORS = [
        'DIAMANTE' => '#0ea5e9', 'MASTER' => '#8b5cf6', 'SENIOR' => '#10b981', 'JUNIOR' => '#f59e0b', 'MANTENIDO' => '#f43f5e', 'SIN CARTERA' => '#64748b',
    ];

    public static function payroll(string $key): string
    {
        return self::PAYROLL[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    public static function recovery(string $key): string
    {
        return self::RECOVERY[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    public static function categoryColor(?string $category): string
    {
        return self::CATEGORY_COLORS[mb_strtoupper((string) $category)] ?? self::CATEGORY_COLORS['MANTENIDO'];
    }
}
