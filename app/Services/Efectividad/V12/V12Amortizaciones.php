<?php

namespace App\Services\Efectividad\V12;

/**
 * Capa de AMORTIZACIONES (V12.9.4): tabla interna de cuotas reconstruida desde
 * las fotografías reales de Saldos por Cliente + aplicación de ingresos por cuota.
 *
 *  - generar(): MR_GenerarAmortizacionesDesdeSaldos
 *  - leer(): MR_LeerAmortizaciones
 *  - aplicarIngresos(): MR_LeerIngresos
 *
 * Nunca inventa cuotas anteriores al alta visible de un crédito nuevo, ni cuotas
 * futuras de un contrato cancelado/refinanciado (salvo que exista un pago
 * ordinario identificado a esa cuota antes del cierre).
 */
final class V12Amortizaciones
{
    public function __construct(private readonly V12Corrida $c) {}

    /** MR_GenerarAmortizacionesDesdeSaldos. */
    public function generar(): void
    {
        $c = $this->c;
        $datos = [];
        $visto = [];
        $cancel = [];
        $enBase = [];
        $reales = [];
        $ultimo = $c->numeroFotoFinal();

        for ($indice = $c->fotoInicio; $indice <= $ultimo; $indice++) {
            if (! isset($c->fotos[$indice]) || ! $c->tieneFoto($indice, 'contrato', 'fecha_primer_pago', 'cuota', 'periodicidad')) {
                continue;
            }
            $fechaFoto = $c->domingo + $indice;
            $conMat = $c->tieneFoto($indice, 'fecha_vencimiento');
            $conEstado = $c->tieneFoto($indice, 'estatus');
            $conSig = $c->tieneFoto($indice, 'numero_siguiente_pago');
            $conSaldo = $c->tieneFoto($indice, 'saldo_capital', 'saldo_interes', 'saldo_impuesto');
            $conAtr = $c->tieneFoto($indice, 'capital_atrasado', 'interes_atrasado', 'impuesto_atrasado');
            foreach ($c->fotos[$indice]['filas'] as $r) {
                $contrato = V12Vba::norm($r['contrato'] ?? null);
                if ($contrato === '') {
                    continue;
                }
                $fechaPrimera = V12Vba::fecha($r['fecha_primer_pago'] ?? null);
                if ($fechaPrimera === null) {
                    continue;
                }
                $fechaMadurez = $fechaPrimera;
                if ($conMat) {
                    $fechaMadurez = V12Vba::fecha($r['fecha_vencimiento'] ?? null) ?? $fechaMadurez;
                }
                $cuota = V12Vba::num($r['cuota'] ?? null);
                $num = V12Vba::clng($r['pagos'] ?? null);
                $tipo = V12Vba::norm($r['periodicidad'] ?? null);
                $estado = $conEstado ? V12Vba::norm($r['estatus'] ?? null) : '';
                $siguiente = $conSig ? V12Vba::clng($r['numero_siguiente_pago'] ?? null) : 0;
                if ($num < 1 || $num > 500 || $cuota <= 0) {
                    continue;
                }
                // Foto inicial del contrato: saldo ordinario SIN atrasos; limita la última cuota.
                $saldoElegible = -1.0;
                $atrasoElegible = 0.0;
                if ($conSaldo) {
                    $saldoElegible = max(0.0, V12Vba::suma(V12Vba::num($r['saldo_capital']), V12Vba::num($r['saldo_interes']), V12Vba::num($r['saldo_impuesto'])));
                    if ($conAtr) {
                        $atrasoElegible = max(0.0, V12Vba::suma(V12Vba::num($r['capital_atrasado']), V12Vba::num($r['interes_atrasado']), V12Vba::num($r['impuesto_atrasado'])));
                    }
                }
                if (isset($datos[$contrato])) {
                    // Los cambios de cuota posteriores solo afectarían cuotas futuras.
                    if ($siguiente > 0 && $indice === $c->fotoInicio) {
                        $datos[$contrato]['siguiente'] = $siguiente;
                    }
                } else {
                    $datos[$contrato] = [
                        'primera' => $fechaPrimera, 'madurez' => $fechaMadurez, 'cuota' => $cuota, 'pagos' => $num,
                        'tipo' => $tipo, 'siguiente' => $siguiente, 'alta' => $fechaFoto,
                        'saldo' => $saldoElegible, 'atraso' => $atrasoElegible,
                    ];
                }
                $visto[$contrato.'|'.$indice] = true;
                if ($indice === $c->fotoInicio) {
                    $enBase[$contrato] = true;
                }
                if (($estado === 'CERRADO' || $estado === 'LIQUIDADO') && ! isset($cancel[$contrato])) {
                    $cancel[$contrato] = $fechaFoto;
                }
            }
        }

        // Un contrato que deja de aparecer ya no programa cuotas posteriores
        // (esto NO lo convierte en liquidado ni en cobrado).
        foreach (array_keys($datos) as $contrato) {
            for ($indice = $c->fotoInicio + 1; $indice <= $ultimo; $indice++) {
                if (! isset($visto[$contrato.'|'.$indice]) && isset($visto[$contrato.'|'.($indice - 1)])) {
                    $cancel[$contrato] ??= $c->domingo + $indice;
                    break;
                }
            }
        }

        // Refinanciamiento, unificación o reestructura: las cuotas futuras cesan.
        $h = $c->historial;
        if ($c->tieneHist('contrato', 'fecha_transaccion', 'operacion')) {
            $conTr = $c->tieneHist('transaccion');
            $conNum = $c->tieneHist('fecha_cuota', 'numero_cuota');
            foreach ($h['filas'] as $r) {
                $contrato = V12Vba::norm($r['contrato'] ?? null);
                if (! isset($datos[$contrato])) {
                    continue;
                }
                $fh = V12Vba::fecha($r['fecha_transaccion'] ?? null);
                if ($fh === null || $fh < $c->fBase + 1 || $fh > $c->corte) {
                    continue;
                }
                $oper = V12Vba::norm($r['operacion'] ?? null);
                $trans = $conTr ? V12Vba::norm($r['transaccion'] ?? null) : '';
                $tipoMov = $this->tipoMovimiento($oper, $trans, $r);
                // Solo un PAGO ORDINARIO aplicado a cuota confirma una fecha de amortización.
                if ($oper === 'PAGO A CONTRATO' && $trans === 'PAGO' && ! $this->esExcluido($tipoMov) && $conNum) {
                    $cuotaReal = V12Vba::fecha($r['fecha_cuota'] ?? null);
                    if ($cuotaReal !== null) {
                        $numReal = V12Vba::clng($r['numero_cuota'] ?? null);
                        if ($numReal > 0 && $cuotaReal <= $c->corte) {
                            $reales[$contrato.'|'.$numReal] = $cuotaReal;
                        }
                    }
                }
                if ((str_contains($oper, 'REFINANC') || str_contains($oper, 'UNIFICAC') || str_contains($oper, 'REESTRUCT'))
                    && (str_contains($trans, 'DESCUENTO') || $trans === 'PAGO')) {
                    if (! isset($cancel[$contrato]) || $fh < $cancel[$contrato]) {
                        $cancel[$contrato] = $fh;
                    }
                }
            }
        }

        $c->amortizaciones = [];
        foreach ($datos as $contrato => $reg) {
            $cancelacion = $cancel[$contrato] ?? 0;
            for ($i = 1; $i <= $reg['pagos']; $i++) {
                $real = isset($reales[$contrato.'|'.$i]);
                $fechaCuota = $real ? $reales[$contrato.'|'.$i] : $this->siguienteCuota($reg['primera'], $reg['tipo'], $i);
                // No inventar cuotas anteriores al alta visible de un crédito nuevo.
                if (! isset($enBase[$contrato]) && $fechaCuota < $reg['alta']) {
                    continue;
                }
                if ($fechaCuota > $c->corte || $fechaCuota > $reg['madurez']) {
                    break;
                }
                // Contrato cancelado: no programar cuotas posteriores, salvo pago ordinario identificado.
                if ($cancelacion > 0 && $fechaCuota > $cancelacion && ! $real) {
                    continue;
                }
                $cuota = $reg['cuota'];
                // El número de siguiente pago (EN) del cierre base acredita cuotas futuras ya cubiertas.
                $previo = 0.0;
                $origen = 'CUOTA CONTRACTUAL';
                if ($reg['siguiente'] > $i && $fechaCuota > $c->fBase) {
                    $previo = $cuota;
                    $origen = 'NUMERO SIGUIENTE PAGO';
                }
                // Última amortización: nunca exigir más que el saldo ordinario pendiente de la foto base.
                if ($i === $reg['pagos'] && $fechaCuota > $c->fBase && $reg['saldo'] >= 0) {
                    $saldoFuturo = max(0.0, $reg['saldo'] - $reg['atraso']);
                    $tope = max(0.0, $cuota - min($cuota, $saldoFuturo));
                    if ($tope > $previo) {
                        $origen = 'SALDO ULTIMA CUOTA';
                    }
                    $previo = max($previo, $tope);
                }
                $c->amortizaciones[] = [
                    'contrato' => $contrato, 'numero' => (string) $i, 'fecha' => $fechaCuota, 'matura' => $reg['madurez'],
                    'monto' => $cuota, 'previo' => min($cuota, max(0.0, $previo)),
                    'cancela' => $cancelacion, 'tipo' => $cancelacion > 0 ? 'CAMBIO CONTRACTUAL' : '',
                    'fecha_real' => $real, 'origen' => $origen,
                ];
            }
        }
    }

    /** MR_LeerAmortizaciones — carga la tabla interna en el diccionario de cuotas. */
    public function leer(): void
    {
        $c = $this->c;
        foreach ($c->amortizaciones as $a) {
            $f = $a['fecha'];
            if ($f > $c->corte && $f > $c->fBase + 7) {
                continue;
            }
            $clave = $a['contrato'].'|'.$a['numero'];
            if (isset($c->cuotas[$clave])) {
                continue;
            }
            $claveFecha = $a['contrato'].'|'.V12Vba::compacto($f);
            if (array_key_exists($claveFecha, $c->porFecha)) {
                // Dos amortizaciones vencen el mismo día: no asociar por fecha ambigua.
                $c->porFecha[$claveFecha] = '';
                $c->incidencia('AMORT-FECHA-DUPLICADA', $a['contrato'], 'Dos cuotas del contrato vencen el mismo día; un cobro sin número de cuota no puede asociarse.', ['fecha' => V12Vba::ymd($f), 'referencia' => V12Vba::compacto($f)]);
            } else {
                $c->porFecha[$claveFecha] = $clave;
            }
            $c->cuotas[$clave] = [
                'contrato' => $a['contrato'], 'numero' => $a['numero'], 'fecha' => $f, 'matura' => $a['matura'],
                'monto' => $a['monto'], 'previo' => $a['previo'], 'previo_original' => $a['previo'],
                'cancela' => $a['cancela'], 'tipo' => $a['tipo'], 'origen' => $a['origen'],
            ];
            $c->cuotasDe[$a['contrato']][] = $clave;
        }
    }

    /** MR_LeerIngresos — asocia cada ingreso ordinario a su cuota (contrato + número o fecha). */
    public function aplicarIngresos(int $desde): void
    {
        $c = $this->c;
        if (! $c->tieneHist('contrato', 'fecha_transaccion', 'fecha_cuota', 'capital', 'interes', 'impuesto', 'operacion', 'transaccion')) {
            throw new V12Exception('EC-V12-940', 'El archivo de ingresos no trae las columnas para aplicar pagos por cuota (Contrato, Operación, Transacción, Fecha transacción, Fecha cuota, Capital, Interés, Impuesto).');
        }
        $conId = $c->tieneHist('id_movimiento');
        $dedup = [];
        $aparentes = [];
        foreach ($c->historial['filas'] as $r) {
            $ct = V12Vba::norm($r['contrato'] ?? null);
            if ($ct === '') {
                continue;
            }
            $fe = V12Vba::fecha($r['fecha_transaccion'] ?? null);
            $fq = V12Vba::fecha($r['fecha_cuota'] ?? null);
            if ($fe === null || $fq === null) {
                continue;
            }
            if ($fe < $desde || $fe > $c->corte) {
                continue;
            }
            $op = V12Vba::norm($r['operacion'] ?? null);
            $tr = V12Vba::norm($r['transaccion'] ?? null);
            $esRef = str_contains($op, 'REFINANC') && str_contains($tr, 'DESCUENTO');
            $esPago = $tr === 'PAGO' && (str_contains($op, 'PAGO A CONTRATO') || str_contains($op, 'REESTRUCT')
                || str_contains($op, 'UNIFICAC') || str_contains($op, 'REFINANC'));
            if (! $esRef && ! $esPago) {
                continue;
            }
            $tipo = $this->tipoMovimiento($op, $tr, $r);
            if ($this->esExcluido($tipo)) {
                continue;
            }
            if (str_contains($tipo, 'REFINANC')) {
                if ($fq > $fe) {
                    continue; // Cuotas futuras de refinanciamiento no cuentan.
                }
            } elseif ((str_contains($tipo, 'REESTRUCT') || str_contains($tipo, 'UNIFICAC')) && str_contains($tr, 'DESCUENTO')) {
                continue;
            }
            $amt = V12Vba::suma(V12Vba::num($r['capital'] ?? null), V12Vba::num($r['interes'] ?? null), V12Vba::num($r['impuesto'] ?? null));
            if ($amt <= V12Vba::MARGEN) {
                continue;
            }
            $n = V12Vba::norm($r['id_cuota'] ?? null);
            if ($n === '') {
                $n = V12Vba::norm($r['numero_cuota'] ?? null);
            }
            $key = $ct.'|'.$n;
            if ($n === '' || ! isset($c->cuotas[$key])) {
                $key = $ct.'|F'.V12Vba::compacto($fq);
                if (! isset($c->cuotas[$key])) {
                    $raw = $ct.'|'.V12Vba::compacto($fq);
                    if (! array_key_exists($raw, $c->porFecha)) {
                        if (! isset($c->cuotasDe[$ct]) && $fe >= $c->repIni && $fe <= $c->repFin) {
                            $c->incidencia('COBRO-SIN-CARTERA', $ct, 'Cobro del periodo de un contrato que no aparece en las carteras evaluadas: no se reconoce.', ['fecha' => V12Vba::ymd($fe), 'importe' => $amt, 'referencia' => $r['ref'] ?? null]);
                        } elseif (isset($c->cuotasDe[$ct]) && $fe > $c->fBase) {
                            $c->incidencia('COBRO-SIN-CUOTA', $ct, 'Cobro del periodo sin amortización comprobable (cuota '.($n !== '' ? $n : 's/n').' del '.V12Vba::dmy($fq).'): no se reconoce.', ['fecha' => V12Vba::ymd($fe), 'importe' => $amt, 'referencia' => $r['ref'] ?? null]);
                        }

                        continue;
                    }
                    $key = $c->porFecha[$raw];
                    if ($key === '') {
                        $c->incidencia('CUOTA-AMBIGUA', $ct, 'Dos cuotas comparten fecha y el cobro no trae número de cuota: no se reconoce.', ['fecha' => V12Vba::ymd($fe), 'importe' => $amt, 'referencia' => $r['ref'] ?? null]);

                        continue;
                    }
                }
            }
            if ($c->cuotas[$key]['fecha'] !== $fq) {
                if ($fe > $c->fBase) {
                    $c->incidencia('FECHA-APLICACION', $ct, 'La fecha de cuota del cobro difiere del calendario contractual (cuota '.$c->cuotas[$key]['numero'].'): no se reconoce.', ['fecha' => V12Vba::ymd($fe), 'importe' => $amt, 'referencia' => $r['ref'] ?? null]);
                }

                continue;
            }
            $id = $conId ? V12Vba::norm($r['id_movimiento'] ?? null) : '';
            if ($id !== '') {
                $firma = 'ID|'.$id.'|'.$key.'|'.number_format($amt, 4, '.', '');
                if (isset($dedup[$firma])) {
                    $c->incidencia('FOLIO-DUPLICADO', $ct, 'Misma aplicación con folio repetido: se excluye la repetición.', ['referencia' => $id, 'importe' => $amt]);

                    continue;
                }
                $dedup[$firma] = true;
            } else {
                $firma = $ct.'|'.V12Vba::compacto($fe).'|'.V12Vba::compacto($fq).'|'.number_format($amt, 4, '.', '').'|'.$op.'|'.$tr.'|'.$n;
                $aparentes[$firma] = true;
            }
            $c->eventos[$key][] = ['fe' => $fe, 'fq' => $fq, 'monto' => $amt, 'ref' => $esRef, 'id' => $id, 'fila' => $r['ref'] ?? null];
        }
    }

    /** Operación + transacción + producto + concepto (para exclusiones por texto). */
    public function tipoMovimiento(string $op, string $tr, array $r): string
    {
        $t = $op.' '.$tr;
        if (in_array('producto', $this->c->historial['columnas'], true)) {
            $t .= ' '.V12Vba::norm($r['producto'] ?? null);
        }
        if (in_array('concepto', $this->c->historial['columnas'], true)) {
            $t .= ' '.V12Vba::norm($r['concepto'] ?? null);
        }

        return $t;
    }

    /** Seguros, coberturas y condonaciones nunca son cobranza ordinaria. */
    public function esExcluido(string $tipo): bool
    {
        return str_contains($tipo, 'SEGURO') || str_contains($tipo, 'COBERTURA') || str_contains($tipo, 'CONDONAC');
    }

    /** MR_SiguienteCuota — fecha de la cuota n según la periodicidad. */
    public function siguienteCuota(int $primera, string $tipo, int $n): int
    {
        if (str_contains($tipo, 'DIAR')) {
            $f = $primera;
            for ($i = 2; $i <= $n; $i++) {
                $f++;
                while (V12Vba::diaSemana($f) > 5) {
                    $f++;
                }
            }

            return $f;
        }
        if (str_contains($tipo, 'QUINC')) {
            return $primera + ($n - 1) * 14;
        }
        if (str_contains($tipo, 'MENS')) {
            return V12Vba::sumarMeses($primera, $n - 1);
        }

        return $primera + ($n - 1) * 7;
    }
}
