<?php

namespace App\Services\EstadoResultados\Support;

/**
 * Catálogo ÚNICO de tratamientos financieros (clasificación) de un movimiento
 * dentro del Estado de Resultados.
 *
 * Cada tratamiento declara a qué línea del ER afecta (o si queda FUERA del ER)
 * y la explicación que se muestra al usuario. Ninguna capa (web, Excel, PDF)
 * define su propia lista: todas leen de aquí.
 */
final class Treatment
{
    // ── Afectan el Estado de Resultados ────────────────────────────────────
    public const OPEX_SUCURSAL = 'opex_sucursal';

    public const NOMINA_SUCURSAL = 'nomina_sucursal';

    public const OPEX_CORPORATIVO = 'opex_corporativo';

    public const NOMINA_CORPORATIVA = 'nomina_corporativa';

    public const DG_OPERATIVO = 'dg_operativo';

    public const DG_REMUNERACION = 'dg_remuneracion';

    public const GASTO_FINANCIERO = 'gasto_financiero';

    public const INGRESO_NO_OPERATIVO = 'ingreso_no_operativo';

    // ── Fuera del Estado de Resultados ─────────────────────────────────────
    public const CAPEX = 'capex';

    public const CAPITAL_DEUDA = 'capital_deuda';

    public const TRANSFERENCIA_INTERNA = 'transferencia_interna';

    public const PERSONAL_SOCIOS = 'personal_socios';

    public const RECUPERABLE = 'recuperable';

    public const BALANCE = 'balance';

    public const PUENTE_TERCEROS = 'puente_terceros';

    public const CUBIERTO_RADIOGRAFIA = 'cubierto_radiografia';

    // ── Estados de sistema (no seleccionables manualmente) ─────────────────
    public const CONCILIADO_ERP = 'conciliado_erp';

    public const DUPLICADO = 'duplicado';

    public const FUERA_PERIODO = 'fuera_periodo';

    public const PENDIENTE = 'pendiente';

    public const REVERSO = 'reverso';

    /**
     * Categoría financiera EXCLUSIVA: cada peso reconocido contribuye exactamente a una.
     * D&A e ISR sólo provienen de Ajustes contables (nunca de un movimiento).
     */
    public const CATEGORIES = [
        'opex_sucursales' => 'OPEX sucursales',
        'nomina_sucursales' => 'Nómina sucursales',
        'opex_corporativo' => 'OPEX Corporativo',
        'nomina_corporativa' => 'Nómina Corporativo',
        'opex_dg' => 'OPEX Dirección General',
        'da' => 'Depreciación y amortización',
        'financiero' => 'Financiero / no operativo',
        'isr' => 'ISR',
    ];

    /** Tratamiento contable (dimensión independiente de la conciliación y de la línea del ER). */
    public const ACCOUNTING = [
        'gasto_operativo' => 'Gasto operativo del periodo',
        'gasto_financiero' => 'Gasto financiero',
        'ingreso_no_operativo' => 'Ingreso financiero / no operativo',
        'reconocido_en_otra_fuente' => 'Reconocido en otra fuente (ERP / Radiografía / NOI)',
        'inversion' => 'Inversión (CAPEX)',
        'pasivo' => 'Pago de pasivo (capital de deuda)',
        'eliminacion_interna' => 'Movimiento interno (se elimina en consolidación)',
        'patrimonial' => 'Movimiento patrimonial / socios',
        'cuenta_por_cobrar' => 'Cuenta por cobrar / reintegro',
        'pasivo_terceros' => 'Pasivo de terceros (retenciones)',
        'puente_terceros' => 'Puente de terceros',
        'duplicado' => 'Duplicado',
        'reverso' => 'Reverso (neto $0)',
        'otro_periodo' => 'Pertenece a otro periodo',
        'sin_definir' => 'Sin definir (requiere revisión)',
    ];

    /**
     * line: clave de la línea/sección del ER que afecta (null = fuera del ER).
     * sign: +1 suma a la línea (gasto o ingreso según la sección).
     */
    private const META = [
        self::OPEX_SUCURSAL => [
            'label' => 'OPEX sucursal (complementario)',
            'line' => 'opex_sucursales',
            'group' => 'er',
            'tone' => 'warning',
            'explain' => 'Suma al OPEX de sucursales como línea complementaria. Radiografía no lo contiene: el EBITDA de sucursales dejará de coincidir con Radiografía por este importe (se muestra en la conciliación).',
        ],
        self::NOMINA_SUCURSAL => [
            'label' => 'Nómina sucursal (complementaria)',
            'line' => 'nomina_sucursales',
            'group' => 'er',
            'tone' => 'warning',
            'explain' => 'Suma a Nómina y Capital Humano de sucursales como línea complementaria. Radiografía no la contiene: se refleja como diferencia explicada en la conciliación.',
        ],
        self::OPEX_CORPORATIVO => [
            'label' => 'OPEX Corporativo',
            'line' => 'opex_corporativo',
            'group' => 'er',
            'tone' => 'success',
            'explain' => 'Gasto operativo real de Corporativo. Reduce el EBITDA corporativo.',
        ],
        self::NOMINA_CORPORATIVA => [
            'label' => 'Nómina corporativa',
            'line' => 'nomina_corporativa',
            'group' => 'er',
            'tone' => 'success',
            'explain' => 'Remuneración del personal de Corporativo. Reduce el EBITDA corporativo.',
        ],
        self::DG_OPERATIVO => [
            'label' => 'Gasto DG operativo',
            'line' => 'dg',
            'group' => 'er',
            'tone' => 'success',
            'explain' => 'Gasto empresarial de Dirección General. Reduce el EBITDA final.',
        ],
        self::DG_REMUNERACION => [
            'label' => 'Remuneración DG',
            'line' => 'dg',
            'group' => 'er',
            'tone' => 'success',
            'explain' => 'Remuneración laboral real de Dirección General. Reduce el EBITDA final.',
        ],
        self::GASTO_FINANCIERO => [
            'label' => 'Gasto financiero (intereses/comisiones)',
            'line' => 'financiero',
            'group' => 'er',
            'tone' => 'success',
            'explain' => 'Intereses o comisiones financieras. Reduce el EBT, no el EBITDA ni el EBIT. Nunca incluya aquí el capital de un préstamo.',
        ],
        self::INGRESO_NO_OPERATIVO => [
            'label' => 'Ingreso financiero / no operativo',
            'line' => 'financiero',
            'group' => 'er',
            'tone' => 'success',
            'explain' => 'Ingreso fuera de la operación (financiero/no operativo). Aumenta el EBT; no altera los ingresos operativos ni los EBITDA.',
        ],
        self::CAPEX => [
            'label' => 'CAPEX (inversión)',
            'line' => null,
            'group' => 'fuera',
            'tone' => 'info',
            'explain' => 'Compra de activo/inversión. No reduce EBITDA: se registra como flujo de inversión.',
        ],
        self::CAPITAL_DEUDA => [
            'label' => 'Pago de deuda (capital)',
            'line' => null,
            'group' => 'fuera',
            'tone' => 'info',
            'explain' => 'Pago a acreedores (BBVA/LEROM u otros). El capital no es gasto: reduce pasivo y efectivo. Sólo los intereses afectan el EBT y se capturan en Ajustes contables.',
        ],
        self::TRANSFERENCIA_INTERNA => [
            'label' => 'Transferencia interna',
            'line' => null,
            'group' => 'fuera',
            'tone' => 'muted',
            'explain' => 'Flujo entre cuentas o entre sucursales y Corporativo de la misma organización. No es ingreso ni gasto.',
        ],
        self::PERSONAL_SOCIOS => [
            'label' => 'Personal / socios / CEO',
            'line' => null,
            'group' => 'fuera',
            'tone' => 'danger',
            'explain' => 'Movimiento personal, patrimonial o societario. No es OPEX; se controla fuera del resultado.',
        ],
        self::RECUPERABLE => [
            'label' => 'Recuperable / reintegro',
            'line' => null,
            'group' => 'fuera',
            'tone' => 'muted',
            'explain' => 'Préstamo a empleados, descuento recuperado o devolución de requisición. Es cuenta por cobrar/reintegro, no ingreso ni gasto.',
        ],
        self::BALANCE => [
            'label' => 'Balance (retenciones / pasivos)',
            'line' => null,
            'group' => 'fuera',
            'tone' => 'muted',
            'explain' => 'Entero de retenciones o pasivos de terceros (SAT retenciones, FONACOT, pensión alimenticia). No es gasto de la empresa.',
        ],
        self::PUENTE_TERCEROS => [
            'label' => 'Puente de terceros (seguros)',
            'line' => null,
            'group' => 'fuera',
            'tone' => 'muted',
            'explain' => 'Cobro/pago por cuenta de terceros (pólizas). Mismo criterio que Radiografía: no es ingreso ni OPEX.',
        ],
        self::CUBIERTO_RADIOGRAFIA => [
            'label' => 'Ya incluido en Radiografía',
            'line' => null,
            'group' => 'fuera',
            'tone' => 'muted',
            'explain' => 'El gasto ya está dentro del EBITDA de sucursales (NOI, ERP, Lendus o IMSS). Sumarlo otra vez lo duplicaría.',
        ],
        self::CONCILIADO_ERP => [
            'label' => 'Conciliado con ERP',
            'line' => null,
            'group' => 'fuera',
            'tone' => 'success',
            'explain' => 'Es el mismo gasto que ya está registrado en el ERP. Se cuenta una sola vez (desde el ERP) y no se vuelve a sumar.',
        ],
        self::DUPLICADO => [
            'label' => 'Duplicado (UUID)',
            'line' => null,
            'group' => 'fuera',
            'tone' => 'muted',
            'explain' => 'Mismo CFDI (UUID) y mismo importe que otro movimiento ya considerado. No se cuenta dos veces.',
        ],
        self::FUERA_PERIODO => [
            'label' => 'Fuera del periodo',
            'line' => null,
            'group' => 'fuera',
            'tone' => 'muted',
            'explain' => 'La fecha del movimiento no cae dentro del periodo seleccionado. Se considera en el periodo al que pertenece su fecha.',
        ],
        self::PENDIENTE => [
            'label' => 'Requiere revisión',
            'line' => null,
            'group' => 'pendiente',
            'tone' => 'warning',
            'explain' => 'Movimiento ambiguo: NO se suma a ninguna línea hasta que un usuario autorizado lo clasifique.',
        ],
        self::REVERSO => [
            'label' => 'Reverso / cancelación',
            'line' => null,
            'group' => 'fuera',
            'tone' => 'muted',
            'explain' => 'Cargo y abono de la misma cuenta por el mismo importe que se anulan entre sí (reverso o cancelación). Neto $0: no es gasto ni ingreso.',
        ],
    ];

    /** Tratamientos que un usuario puede asignar manualmente. */
    public const ASSIGNABLE = [
        self::OPEX_CORPORATIVO,
        self::NOMINA_CORPORATIVA,
        self::DG_OPERATIVO,
        self::DG_REMUNERACION,
        self::OPEX_SUCURSAL,
        self::NOMINA_SUCURSAL,
        self::GASTO_FINANCIERO,
        self::INGRESO_NO_OPERATIVO,
        self::CAPEX,
        self::CAPITAL_DEUDA,
        self::TRANSFERENCIA_INTERNA,
        self::PERSONAL_SOCIOS,
        self::RECUPERABLE,
        self::BALANCE,
        self::PUENTE_TERCEROS,
        self::CUBIERTO_RADIOGRAFIA,
        self::PENDIENTE,
    ];

    public static function all(): array
    {
        return array_keys(self::META);
    }

    public static function exists(string $treatment): bool
    {
        return isset(self::META[$treatment]);
    }

    public static function isAssignable(string $treatment): bool
    {
        return in_array($treatment, self::ASSIGNABLE, true);
    }

    public static function label(string $treatment): string
    {
        return self::META[$treatment]['label'] ?? $treatment;
    }

    public static function line(string $treatment): ?string
    {
        return self::META[$treatment]['line'] ?? null;
    }

    public static function group(string $treatment): string
    {
        return self::META[$treatment]['group'] ?? 'pendiente';
    }

    public static function affectsStatement(string $treatment): bool
    {
        return self::line($treatment) !== null;
    }

    public static function explain(string $treatment): string
    {
        return self::META[$treatment]['explain'] ?? '';
    }

    /** Categoría financiera exclusiva (clave de CATEGORIES) o null si no afecta el ER. */
    public static function category(string $treatment): ?string
    {
        return match (self::line($treatment)) {
            'opex_sucursales' => 'opex_sucursales',
            'nomina_sucursales' => 'nomina_sucursales',
            'opex_corporativo' => 'opex_corporativo',
            'nomina_corporativa' => 'nomina_corporativa',
            'dg' => 'opex_dg',
            'financiero' => 'financiero',
            default => null,
        };
    }

    public static function accounting(string $treatment): string
    {
        return match ($treatment) {
            self::OPEX_SUCURSAL, self::NOMINA_SUCURSAL, self::OPEX_CORPORATIVO, self::NOMINA_CORPORATIVA,
            self::DG_OPERATIVO, self::DG_REMUNERACION => 'gasto_operativo',
            self::GASTO_FINANCIERO => 'gasto_financiero',
            self::INGRESO_NO_OPERATIVO => 'ingreso_no_operativo',
            self::CONCILIADO_ERP, self::CUBIERTO_RADIOGRAFIA => 'reconocido_en_otra_fuente',
            self::CAPEX => 'inversion',
            self::CAPITAL_DEUDA => 'pasivo',
            self::TRANSFERENCIA_INTERNA => 'eliminacion_interna',
            self::PERSONAL_SOCIOS => 'patrimonial',
            self::RECUPERABLE => 'cuenta_por_cobrar',
            self::BALANCE => 'pasivo_terceros',
            self::PUENTE_TERCEROS => 'puente_terceros',
            self::DUPLICADO => 'duplicado',
            self::REVERSO => 'reverso',
            self::FUERA_PERIODO => 'otro_periodo',
            default => 'sin_definir',
        };
    }

    public static function tone(string $treatment): string
    {
        return self::META[$treatment]['tone'] ?? 'muted';
    }

    /** Catálogo serializable para la UI (mismo texto que Excel/PDF). */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::META as $key => $meta) {
            $out[] = [
                'key' => $key,
                'label' => $meta['label'],
                'group' => $meta['group'],
                'line' => $meta['line'],
                'tone' => $meta['tone'],
                'explain' => $meta['explain'],
                'assignable' => in_array($key, self::ASSIGNABLE, true),
                'category' => self::category($key),
                'accounting' => self::accounting($key),
            ];
        }

        return $out;
    }
}
