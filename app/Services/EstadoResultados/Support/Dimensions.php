<?php

namespace App\Services\EstadoResultados\Support;

use App\Services\EstadoResultados\Reconciliation\EstadoResultadosExpenseReconciler as R;

/**
 * Dimensiones INDEPENDIENTES de cada movimiento (banco o ERP). Ninguna mezcla
 * conciliación con clasificación:
 *
 *   import          ¿cómo entró el dato?                       (importación)
 *   reconciliation  ¿se emparejó con el ERP?                   (conciliación)
 *   category        ¿a qué categoría financiera exclusiva va?  (clasificación financiera)
 *   accounting      ¿qué es contablemente?                     (tratamiento contable)
 *   recognition     ¿en qué periodo gerencial se reconoce?     (reconocimiento)
 *   review          ¿requiere / tuvo revisión humana?          (revisión)
 *   inclusion       ¿entra al Estado de Resultados?            (inclusión en el reporte)
 *   source          ¿qué fuente reconoce el gasto UNA vez?     (fuente que reconoce)
 *
 * Catálogo único (claves + etiquetas en español) para web, Excel y PDF.
 */
final class Dimensions
{
    public const VERSION = '2026-11-06.1';

    public const IMPORT = [
        'importado' => 'Importado y validado contra total de control',
        'sin_control' => 'Importado (el archivo no trae total de control)',
        'control_no_cuadra' => 'Importado (el total de control no cuadra)',
        'erp' => 'Registro del ERP de gastos',
        'noi_corporativo' => 'NOI de corporativo (cargado en el Estado de Resultados)',
    ];

    public const RECONCILIATION = [
        'conciliado' => 'Conciliado',
        'conciliado_parcial' => 'Conciliado parcialmente',
        'pendiente' => 'Pendiente de conciliar (revisión)',
        'ambiguo' => 'Ambiguo (varias coincidencias posibles)',
        'sin_coincidencia' => 'Sin coincidencia',
        'duplicado' => 'Duplicado',
        'no_aplica' => 'No aplica',
        'sin_procesar' => 'Sin procesar',
    ];

    public const REVIEW = [
        'no_requiere' => 'No requiere revisión',
        'pendiente' => 'Pendiente de revisión',
        'revisado' => 'Revisado (clasificación manual)',
    ];

    public const INCLUSION = [
        'incluido' => 'Incluido en el Estado de Resultados',
        'incluido_parcial' => 'Incluido parcialmente (parte conciliada con ERP)',
        'excluido_justificado' => 'Excluido con justificación',
        'pendiente' => 'Pendiente (ni incluido ni excluido)',
        'fuera_periodo' => 'Fuera del periodo',
    ];

    public const SOURCES = [
        'banco' => 'Estado de cuenta',
        'erp' => 'ERP de gastos',
        'noi' => 'NOI (nómina, ya en Radiografía)',
        'radiografia' => 'Radiografía (ERP / Lendus / NOI de sucursales)',
        'noi_corp' => 'NOI de corporativo',
    ];

    public const OUT_OF_PERIOD = [
        'fecha_anterior_al_periodo' => 'Fecha anterior al periodo',
        'fecha_posterior_al_periodo' => 'Fecha posterior al periodo',
        'ajuste_periodo_anterior' => 'Ajuste de periodo anterior (el folio ERP es de un periodo previo)',
        'erp_periodo_posterior' => 'El folio ERP pertenece a un periodo posterior',
    ];

    public const BASIS = [
        'fecha_bancaria' => 'Fecha bancaria dentro del periodo',
        'periodo_erp' => 'Periodo de importación del ERP (mismo universo que Radiografía)',
        'periodo_noi' => 'Periodo de la nómina (NOI de corporativo del mes)',
    ];

    public const LINK_KINDS = [
        'conciliacion' => 'Conciliación con ERP',
        'evidencia_noi' => 'Evidencia de nómina NOI',
        'reverso' => 'Par de reverso',
        'reembolso' => 'Reembolso de un gasto',
        'traspaso' => 'Traspaso entre cuentas propias',
    ];

    /** Estado de conciliación (dimensión) a partir del estado detallado del reconciliador. */
    public static function reconciliationState(?string $status): string
    {
        return match (true) {
            $status === null, $status === 'sin_procesar' => 'sin_procesar',
            $status === R::PARCIAL, $status === 'erp_pago_parcial' => 'conciliado_parcial',
            in_array($status, R::CONCILIADOS, true), $status === 'erp_con_pago_bancario' => 'conciliado',
            in_array($status, [R::FOLIO_AMBIGUO, R::POSIBLE_DUPLICADO_ERP], true) => 'ambiguo',
            in_array($status, R::REVISION, true) => 'pendiente',
            in_array($status, [R::FOLIO_SIN_ERP, R::SIN_FOLIO, 'erp'], true) => 'sin_coincidencia',
            $status === R::DUPLICADO_UUID => 'duplicado',
            default => 'no_aplica', // abono, transferencia, fuera_periodo, reverso
        };
    }

    /**
     * Deriva las dimensiones automáticas/efectivas de un item.
     *
     * @param  array{source:string, in_period:bool, treatment:string, reconciliation_status:?string, applied:string, has_override:bool, recognizing_hint:?string, import:string}  $c
     * @return array{import:string, reconciliation:string, category:?string, accounting:string, review:string, inclusion:string, source:?string}
     */
    public static function derive(array $c): array
    {
        $t = $c['treatment'];
        $applied = ! Money::isZeroCents($c['applied'] ?? '0');
        $affects = Treatment::affectsStatement($t);
        // Todo el importe ya está conciliado (lo reconoce el ERP/Radiografía): nada que clasificar.
        $nothingLeft = array_key_exists('classifiable', $c) && Money::isZeroCents($c['classifiable']);

        $inclusion = match (true) {
            ! $c['in_period'] => 'fuera_periodo',
            $nothingLeft => 'excluido_justificado',
            $affects && $applied => 'incluido_parcial',
            $affects => 'incluido',
            $t === Treatment::PENDIENTE => 'pendiente',
            default => 'excluido_justificado',
        };

        $source = match (true) {
            ! $c['in_period'] => null,
            $nothingLeft => $c['recognizing_hint'] ?? 'erp',
            $affects => $c['source'],
            $t === Treatment::CONCILIADO_ERP, $t === Treatment::CUBIERTO_RADIOGRAFIA => $c['recognizing_hint'] ?? 'radiografia',
            $t === Treatment::CUBIERTO_NOI_CORPORATIVO => 'noi_corp',
            $t === Treatment::PENDIENTE && $applied => $c['recognizing_hint'], // la parte aplicada ya la reconoce el ERP
            default => null,
        };

        return [
            'import' => $c['import'],
            'reconciliation' => self::reconciliationState($c['reconciliation_status']),
            'category' => $c['in_period'] && ! $nothingLeft ? Treatment::category($t) : null,
            'accounting' => $c['in_period'] ? Treatment::accounting($t) : 'otro_periodo',
            'review' => $c['has_override'] ? 'revisado' : ($t === Treatment::PENDIENTE ? 'pendiente' : 'no_requiere'),
            'inclusion' => $inclusion,
            'source' => $source,
        ];
    }

    /** Dimensiones con etiqueta (forma pública del dataset). */
    public static function present(array $d, array $recognition): array
    {
        return [
            'import' => ['key' => $d['import'], 'label' => self::IMPORT[$d['import']] ?? $d['import']],
            'reconciliation' => ['key' => $d['reconciliation'], 'label' => self::RECONCILIATION[$d['reconciliation']] ?? $d['reconciliation']],
            'category' => ['key' => $d['category'], 'label' => $d['category'] ? Treatment::CATEGORIES[$d['category']] : 'Sin categoría del ER'],
            'accounting' => ['key' => $d['accounting'], 'label' => Treatment::ACCOUNTING[$d['accounting']] ?? $d['accounting']],
            'recognition' => $recognition,
            'review' => ['key' => $d['review'], 'label' => self::REVIEW[$d['review']]],
            'inclusion' => ['key' => $d['inclusion'], 'label' => self::INCLUSION[$d['inclusion']]],
            'source' => ['key' => $d['source'], 'label' => $d['source'] ? self::SOURCES[$d['source']] : 'Ninguna (no se reconoce como gasto/ingreso)'],
        ];
    }

    /** Catálogo serializable para la UI. */
    public static function catalog(): array
    {
        $list = fn (array $map) => array_map(fn ($k, $v) => ['key' => $k, 'label' => $v], array_keys($map), $map);

        return [
            'version' => self::VERSION,
            'import' => $list(self::IMPORT),
            'reconciliation' => $list(self::RECONCILIATION),
            'category' => $list(Treatment::CATEGORIES),
            'accounting' => $list(Treatment::ACCOUNTING),
            'review' => $list(self::REVIEW),
            'inclusion' => $list(self::INCLUSION),
            'source' => $list(self::SOURCES),
            'out_of_period' => $list(self::OUT_OF_PERIOD),
            'basis' => $list(self::BASIS),
            'link_kinds' => $list(self::LINK_KINDS),
            'match_types' => [
                ['key' => '1:1', 'label' => 'Un pago ↔ una requisición'],
                ['key' => '1:N', 'label' => 'Varios pagos ↔ una requisición'],
                ['key' => 'N:1', 'label' => 'Un pago ↔ varias requisiciones'],
                ['key' => 'N:M', 'label' => 'Varios pagos ↔ varias requisiciones'],
                ['key' => 'parcial', 'label' => 'Aplicación parcial'],
            ],
        ];
    }

    /** Criterio de reconocimiento vigente (versionado). */
    public static function criterion(): array
    {
        return [
            'version' => (string) config('estado_resultados.reconocimiento.version'),
            'criterio' => (string) config('estado_resultados.reconocimiento.criterio'),
            'reglas' => (array) config('estado_resultados.reconocimiento.reglas', []),
            'dimensions_version' => self::VERSION,
        ];
    }
}
