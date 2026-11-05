<?php

namespace App\Services\Efectividad\V12;

/**
 * Encabezados que usa la metodología V12.9.4 (comparación con `MR_Norm`).
 *
 * Cada campo tiene una lista de GRUPOS de alias probados en orden de prioridad.
 * Dentro de un grupo gana la primera columna (de izquierda a derecha) cuyo
 * encabezado coincide con cualquiera de sus alias; si ninguna coincide se prueba
 * el siguiente grupo. Así se reproduce tanto `MR_Col(sh, "A", "B")` (un grupo)
 * como las búsquedas encadenadas (`cp = MR_Col("Nombre promotor")`, si no
 * `MR_Col("Nombre del promotor")`, si no `MR_Col("Promotor")`).
 */
final class V12ColumnMap
{
    /** Fotografías de cartera (Saldos por Cliente). Letras = columna en Lendus. */
    public const FOTO = [
        'contrato' => [['Contrato']],
        'oficina' => [['Oficina']],
        // MR_LeerFoto: responsable de la cuota/cobro.
        'nombre_promotor' => [['Nombre promotor'], ['Nombre del promotor'], ['Promotor']],
        // MR_ResumenFotos: gestor visible en la conciliación.
        'gestor' => [['Nombre promotor']],
        'estatus' => [['Estatus']],
        'substatus' => [['Substatus', 'Subestatus']],
        'fecha_primer_pago' => [['Fecha primer pago']],               // AX
        'fecha_vencimiento' => [['Fecha vencimiento']],               // AY
        'cuota' => [['Cuota']],                                       // AZ: SOLO cuota ordinaria
        'periodicidad' => [['Periodicidad']],                         // BW
        'pagos' => [['Pagos']],                                       // BX
        'saldo_capital' => [['Saldo capital']],                       // CC
        'saldo_interes' => [['Saldo interes', 'Saldo interés']],      // CG
        'saldo_impuesto' => [['Saldo impuesto']],                     // CK
        'pagos_pendientes' => [['Pagos pendientes']],
        'capital_pendiente' => [['Capital pendiente']],               // DX
        'interes_pendiente' => [['Interes pendiente', 'Interés pendiente']], // DY
        'impuesto_pendiente' => [['Impuesto pendiente']],             // DZ
        'pagos_atrasados' => [['Pagos atrasados']],                   // ED
        'capital_atrasado' => [['Capital atrasado']],                 // EE
        'interes_atrasado' => [['Interes atrasado', 'Interés atrasado']], // EF
        'impuesto_atrasado' => [['Impuesto atrasado']],               // EG
        'numero_siguiente_pago' => [['Numero siguiente pago', 'Número siguiente pago']], // EN
        'fecha_siguiente_pago' => [['Fecha siguiente pago']],
        // Informativos (diagnóstico / UI); no intervienen en el cálculo.
        'cuota_total' => [['Cuota total']],
        'total_atrasado' => [['Total atrasado']],
        'producto' => [['Producto de credito', 'Producto de crédito']],
    ];

    /** INGRESOS / historial de ingresos (Ingresos Cobranza). */
    public const INGRESOS = [
        'oficina' => [['Oficina']],
        'promotor' => [['Promotor', 'Nombre promotor']],
        'contrato' => [['Contrato']],
        'operacion' => [['Operacion', 'Operación']],                  // P
        'concepto' => [['Concepto']],                                 // Q
        'transaccion' => [['Transaccion', 'Transacción']],            // R
        'fecha_transaccion' => [['Fecha transaccion', 'Fecha transacción']], // S
        'fecha_cuota' => [['Fecha cuota']],                           // T
        'numero_cuota' => [['Numero cuota', 'Número cuota']],         // U
        'id_cuota' => [['ID cuota', 'ID amortizacion', 'ID amortización']],
        'id_movimiento' => [['ID movimiento', 'ID transaccion', 'ID transacción', 'Folio movimiento', 'Folio transaccion']],
        'capital' => [['Capital']],                                   // V
        'interes' => [['Interes', 'Interés']],                        // W
        'impuesto' => [['Impuesto', 'IVA']],                          // X
        'producto' => [['Producto de credito', 'Producto de crédito']],
        'fecha_vencimiento' => [['Fecha vencimiento']],
    ];

    /**
     * @param  list<mixed>  $encabezados  fila de encabezados del archivo
     * @param  array<string, list<list<string>>>  $mapa
     * @return array<string,int> campo => índice de columna (0-based)
     */
    public static function resolver(array $encabezados, array $mapa): array
    {
        $normalizados = array_map(fn ($h) => V12Vba::norm($h), $encabezados);
        $out = [];
        foreach ($mapa as $campo => $grupos) {
            foreach ($grupos as $alias) {
                $buscados = array_map(fn ($a) => V12Vba::norm($a), $alias);
                foreach ($normalizados as $i => $h) {
                    if ($h !== '' && in_array($h, $buscados, true)) {
                        $out[$campo] = $i;

                        continue 3;
                    }
                }
            }
        }

        return $out;
    }
}
