<?php

namespace App\Services\Efectividad\V4;

/**
 * Encabezados que la macro V4 busca con `ColumnaEncabezado` (comparación con
 * `Normalizar`: mayúsculas, sin acentos, espacios simples). Se toma la PRIMERA
 * columna, de izquierda a derecha, cuyo encabezado coincida con cualquiera de
 * los alias — igual que la macro.
 */
final class EfectividadV4ColumnMap
{
    /** Fotografías de cartera (Saldos por Cliente). */
    public const FOTO = [
        'contrato' => ['Contrato'],
        'acreditado' => ['Acreditado'],
        'oficina' => ['Oficina'],
        'nombre_promotor' => ['Nombre promotor', 'Nombre del promotor'],
        'promotor_codigo' => ['Promotor'],
        'cliente' => ['Cliente'],
        'substatus' => ['Substatus', 'Subestatus'],
        'estatus' => ['Estatus'],
        'producto' => ['Producto de credito', 'Producto de crédito'],
        'origen' => ['Origen credito', 'Origen crédito'],
        'fecha_primer_pago' => ['Fecha primer pago'],
        'fecha_vencimiento' => ['Fecha vencimiento'],
        'fecha_siguiente_pago' => ['Fecha siguiente pago'],
        'cuota_total' => ['Cuota total'],
        'periodicidad' => ['Periodicidad'],
        'pagos' => ['Pagos'],
        'total_atrasado' => ['Total atrasado'],
        'pagos_atrasados' => ['Pagos atrasados'],
        'total_pendiente' => ['Total pendiente'],
        'saldo_capital' => ['Saldo capital'],
        'saldo_interes' => ['Saldo interes', 'Saldo interés'],
        'saldo_impuesto' => ['Saldo impuesto'],
        'saldo_cargos_calendario' => ['Saldo cargos calendario'],
        'saldo_impuesto_cargos_calendario' => ['Saldo impuesto cargos calendario'],
        // Informativos (no intervienen en la macro; se conservan para diagnóstico).
        'dias_atraso' => ['Días Atraso', 'Dias Atraso'],
        'dias_vencido' => ['Días Vencido', 'Dias Vencido'],
        'saldo_actual' => ['Saldo actual'],
    ];

    /** INGRESOS / _HIST_INGRESOS (Ingresos Cobranza). */
    public const INGRESOS = [
        'oficina' => ['Oficina'],
        'promotor' => ['Promotor', 'Nombre promotor'],
        'contrato' => ['Contrato'],
        'acreditado' => ['Acreditado'],
        'operacion' => ['Operacion', 'Operación'],
        'transaccion' => ['Transaccion', 'Transacción'],
        'fecha_transaccion' => ['Fecha transaccion', 'Fecha transacción'],
        'fecha_cuota' => ['Fecha cuota'],
        'fecha_vencimiento' => ['Fecha vencimiento'],
        'capital' => ['Capital'],
        'interes' => ['Interes', 'Interés'],
        'impuesto' => ['Impuesto'],
        'cargos_calendario' => ['Cargos calendario'],
        'producto' => ['Producto de credito', 'Producto de crédito'],
        'concepto' => ['Concepto'],
        'numero_cuota' => ['Numero cuota', 'Número cuota'],
    ];

    /**
     * @param  list<mixed>  $encabezados  fila de encabezados del archivo
     * @param  array<string, list<string>>  $mapa
     * @return array<string,int> campo canónico => índice de columna (0-based)
     */
    public static function resolver(array $encabezados, array $mapa): array
    {
        $calc = new EfectividadV4Calculator;
        $normalizados = array_map(fn ($h) => $calc->normalizar($h), $encabezados);
        $out = [];
        foreach ($mapa as $campo => $alias) {
            $buscados = array_map(fn ($a) => $calc->normalizar($a), $alias);
            foreach ($normalizados as $i => $h) {
                if ($h !== '' && in_array($h, $buscados, true)) {
                    $out[$campo] = $i;
                    break;
                }
            }
        }

        return $out;
    }
}
