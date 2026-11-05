<?php

namespace App\Services\Efectividad\V12;

/**
 * Reconstrucción del NETO exigible de cada cuota al inicio del periodo (V12.9.4).
 *
 * Cada etapa SOLO puede reducir la meta (`previo`) cuando la evidencia de las
 * fotografías y del historial lo respalda, y registra la reducción como
 * INFERENCIA cuando el total está comprobado pero su atribución a la cuota no
 * (`estimadas`). Nunca crea cobros, nunca usa la cobranza del periodo para
 * reducir la meta y nunca reparte una diferencia global entre varias cuotas a
 * ciegas.
 *
 *  - fotoBase(): MR_AjustarNetoFotoBase
 *  - atrasoIndividual(): MR_AjustarNetoPorFotoAtraso
 *  - intermedio(): MR_AjustarNetoIntermedio
 *  - cierresRefinanciamiento(): MR_VerificarCierresRefi
 *  - ultimaCuota(): MR_ValidarUltimaCuota
 */
final class V12ConciliadorNeto
{
    private const C = V12Vba::CENTAVOS;

    private const M = V12Vba::MARGEN;

    public function __construct(private readonly V12Corrida $c) {}

    /**
     * Anticipos por saldo DX:DZ de la foto base cuando TODAS las cuotas pendientes del
     * contrato vencen dentro de la ventana: el total está comprobado; su atribución por
     * cuota se etiqueta como estimada.
     */
    public function fotoBase(): void
    {
        $c = $this->c;
        $i = $c->fotoInicio;
        if (! $c->tieneFoto($i, 'contrato', 'numero_siguiente_pago', 'pagos', 'pagos_pendientes', 'fecha_vencimiento', 'capital_pendiente', 'interes_pendiente', 'impuesto_pendiente')) {
            return;
        }
        $conFProx = $c->tieneFoto($i, 'fecha_siguiente_pago');
        $fBase = $c->fBase;
        $fin = $c->corte;
        foreach ($c->fotos[$i]['filas'] as $r) {
            $contrato = V12Vba::norm($r['contrato'] ?? null);
            if ($contrato === '' || ! V12Vba::esNumerico($r['numero_siguiente_pago'] ?? null)) {
                continue;
            }
            $nSig = V12Vba::clng($r['numero_siguiente_pago'] ?? null);
            $nTotal = V12Vba::clng($r['pagos'] ?? null);
            $nPend = V12Vba::clng($r['pagos_pendientes'] ?? null);
            if ($nSig < 1 || $nTotal < $nSig || $nTotal > 500 || $nPend !== $nTotal - $nSig + 1) {
                continue;
            }
            $venc = V12Vba::fecha($r['fecha_vencimiento'] ?? null);
            if ($venc === null || $venc > $fin) {
                continue;
            }
            if ($conFProx) {
                $fProx = V12Vba::fecha($r['fecha_siguiente_pago'] ?? null);
                if ($fProx !== null && ($fProx <= $fBase || $fProx > $fin)) {
                    continue;
                }
            }
            $saldoNeto = V12Vba::suma(V12Vba::num($r['capital_pendiente'] ?? null), V12Vba::num($r['interes_pendiente'] ?? null), V12Vba::num($r['impuesto_pendiente'] ?? null));
            if ($saldoNeto < -self::M) {
                continue;
            }
            $saldoNeto = max(0.0, $saldoNeto);
            $seq = [];
            $cambios = [];
            $porDistribucion = [];
            $proyeccion = 0.0;
            for ($n = $nSig; $n <= $nTotal; $n++) {
                $k = $contrato.'|'.$n;
                if (! isset($c->cuotas[$k])) {
                    continue 2;
                }
                $v = $c->cuotas[$k];
                if ($v['fecha'] <= $fBase || $v['fecha'] > $fin || ($v['cancela'] > 0 && $v['cancela'] < $v['fecha'])) {
                    continue 2;
                }
                $pagadoAntes = $c->sumaEventos($k, fn ($e) => $e['fe'] <= $fBase);
                $previo = min($v['monto'], max($v['previo'], $pagadoAntes));
                $cambios[$k] = $previo;
                $proyeccion += max(0.0, $v['monto'] - $previo);
                $seq[] = $k;
            }
            // Si la base es mayor que la programación, falta evidencia: no inventar ni aumentar la meta.
            $deficit = $proyeccion - $saldoNeto;
            if ($deficit <= self::C) {
                continue;
            }
            foreach ($seq as $k) {
                $v = $c->cuotas[$k];
                $previo = $cambios[$k];
                $pagoSemana = $c->sumaEventos($k, fn ($e) => $e['fe'] > $fBase && $e['fe'] <= $fin);
                $margenCuota = max(0.0, $v['monto'] - $previo - $pagoSemana);
                $ajuste = min($deficit, $margenCuota);
                if ($ajuste > self::M) {
                    $cambios[$k] = $previo + $ajuste;
                    $porDistribucion[$k] = $ajuste;
                }
                $deficit -= $ajuste;
                if ($deficit <= self::M) {
                    break;
                }
            }
            if ($deficit > self::C) {
                $c->incidencia('NETO-NO-CONCILIA', $contrato, 'Saldos e ingresos no permiten ubicar completamente los anticipos; la meta no se ajusta.', ['importe' => $deficit]);

                continue;
            }
            $c->conciliados[$contrato] = [$saldoNeto, $proyeccion, $proyeccion - $saldoNeto, count($seq)];
            foreach ($seq as $k) {
                $c->cuotas[$k]['previo'] = $cambios[$k];
                if (isset($porDistribucion[$k])) {
                    $c->estimadas[$k] = $porDistribucion[$k];
                }
            }
        }
    }

    /**
     * V12.8: la primera foto posterior al vencimiento de UNA cuota (ED=1, EN=n+1)
     * cuantifica su atraso ordinario; exigible = abonos del periodo hasta esa foto +
     * ese atraso, contrastado contra DX:DZ de la base.
     */
    public function atrasoIndividual(): void
    {
        $c = $this->c;
        $iIni = $c->fotoInicio;
        $iFin = $c->numeroFotoFinal();
        if ($iFin <= $iIni) {
            return;
        }
        $retratos = [];
        for ($i = $iIni; $i <= $iFin; $i++) {
            $retratos[$i - $iIni] = $c->resumen($i);
        }
        $baseD = $retratos[0];
        $fBase = $c->fBase;
        $previoContrato = [];
        foreach ($c->cuotas as $q) {
            $ct = $q['contrato'];
            if (isset($baseD[$ct]) && V12Vba::esNumerico($q['numero']) && (int) $q['numero'] >= $baseD[$ct][0]) {
                $previoContrato[$ct] = ($previoContrato[$ct] ?? 0.0) + $q['previo'];
            }
        }
        foreach ($c->cuotas as $k => $q) {
            $ct = $q['contrato'];
            if (! isset($baseD[$ct]) || isset($c->estimadas[$k]) || ! V12Vba::esNumerico($q['numero'])) {
                continue;
            }
            $n = (int) $q['numero'];
            $fechaQ = $q['fecha'];
            $fechaCancel = $q['cancela'];
            if ($fechaQ < $c->repIni || $fechaQ > $c->repFin || ($fechaCancel > 0 && $fechaCancel < $fechaQ)) {
                continue;
            }
            $b = $baseD[$ct];
            [$nBase, $nTotal] = [$b[0], $b[3]];
            if ($nBase < 1 || $n < $nBase || $n > $nTotal) {
                continue;
            }
            if ($b[9] > 0 || $b[1] > self::C) {
                continue;
            }
            $importe = $q['monto'];
            $previo = $q['previo'];
            if ($importe - $previo <= self::C) {
                continue;
            }
            if ($b[4] <= 0 || abs($b[4] - $importe) > self::C) {
                continue;
            }
            // El saldo futuro global acredita un anticipo sin decidir a ciegas a qué cuota aplicarlo.
            $cupNominal = ($nTotal - $nBase + 1) * $b[4];
            $bolsa = max(0.0, $cupNominal - $b[5] - ($previoContrato[$ct] ?? 0.0));
            if ($bolsa <= self::C) {
                continue;
            }
            $pagadoTodo = 0.0;
            foreach ($c->eventos[$k] ?? [] as $e) {
                if ($e['ref']) {
                    continue 2;
                }
                if ($e['fe'] > $fBase && $e['fe'] <= $c->repFin) {
                    $pagadoTodo += $e['monto'];
                }
            }
            for ($j = 1; $j <= $iFin - $iIni; $j++) {
                $fotoFecha = $fBase + $j;
                if ($fotoFecha <= $fechaQ || $fotoFecha > $c->repFin) {
                    continue;
                }
                $f = $retratos[$j][$ct] ?? null;
                if ($f === null || $f[9] !== 1 || $f[0] !== $n + 1 || $f[3] !== $nTotal || abs($f[4] - $b[4]) > self::C || $fotoFecha > $q['matura']) {
                    continue;
                }
                $saldoFoto = (float) $f[1];
                if ($saldoFoto <= self::C || $saldoFoto > $importe + self::C) {
                    continue;
                }
                $pagadoHasta = $c->sumaEventos($k, fn ($e) => $e['fe'] > $fBase && $e['fe'] <= $fotoFecha);
                $exigible = V12Vba::round2($pagadoHasta + $saldoFoto);
                $descuento = V12Vba::round2($importe - $previo - $exigible);
                if ($descuento <= self::C || $descuento > $bolsa + self::C) {
                    continue;
                }
                if ($pagadoTodo > $exigible + self::C || $exigible < 0 || $exigible > $importe + self::C) {
                    continue;
                }
                // Otra cuota ya vencida entre la base y el vencimiento: el atraso dejaría de ser único.
                for ($i = 0; $i <= $j - 1; $i++) {
                    $g = $retratos[$i][$ct] ?? null;
                    if ($g !== null && V12Corrida::atrasoResumen($g, $fBase + $i) > self::C) {
                        continue 2;
                    }
                }
                $c->cuotas[$k]['previo'] = min($importe, $previo + $descuento);
                $previoContrato[$ct] = ($previoContrato[$ct] ?? 0.0) + $descuento;
                $c->estimadas[$k] = $descuento;
                $c->conciliados[$ct] = [$b[5], $cupNominal, $descuento, $nTotal - $nBase + 1];
                $c->notas['FOTO-ATRASO|'.$k] = true;
                break;
            }
        }
    }

    /**
     * V12.8: anticipo previo en UNA única cuota parcial candidata (el contrato mantiene
     * plazo/cuota, ninguna foto presenta atraso y EN avanza). Conciliación aritmética,
     * no un folio histórico. Nunca reparte un saldo global entre varias cuotas.
     */
    public function intermedio(): void
    {
        $c = $this->c;
        $iIni = $c->fotoInicio;
        $iFin = $c->numeroFotoFinal();
        if ($iFin <= $iIni) {
            return;
        }
        $fotosDia = [];
        for ($i = $iIni; $i <= $iFin; $i++) {
            $fotosDia[$i - $iIni] = $c->resumen($i);
        }
        $ult = $iFin - $iIni;
        $fBase = $c->fBase;
        foreach ($fotosDia[0] as $nombre => $vb) {
            $ultimoIndice = $ult;
            // Si el contrato desaparece se usa su ÚLTIMA foto observable (nunca como prueba de liquidación).
            if (! isset($fotosDia[$ult][$nombre])) {
                $ultimoIndice = 0;
                for ($d = 1; $d <= $ult; $d++) {
                    if (isset($fotosDia[$d][$nombre])) {
                        $ultimoIndice = $d;
                    }
                }
                if ($ultimoIndice === 0) {
                    continue;
                }
            }
            $vf = $fotosDia[$ultimoIndice][$nombre];
            [$nIni, $nFin, $total] = [$vb[0], $vf[0], $vb[3]];
            if ($nIni < 1 || $total < $nIni || $total > 500 || $nFin <= $nIni || $total !== $vf[3]) {
                continue;
            }
            if (abs($vb[4] - $vf[4]) > self::C || $vb[4] <= self::M) {
                continue;
            }
            for ($d = 0; $d <= $ultimoIndice; $d++) {
                $x = $fotosDia[$d][$nombre] ?? null;
                if ($x !== null && (V12Corrida::atrasoResumen($x, $fBase + $d) > self::C || $x[3] !== $total || abs($x[4] - $vb[4]) > self::C)) {
                    continue 2;
                }
            }
            $maxNominal = ($total - $nIni + 1) * $vb[4];
            $saldoBase = (float) $vb[5];
            if ($saldoBase < -self::C) {
                continue;
            }
            $bolsa = max(0.0, $maxNominal - $saldoBase);
            if ($bolsa <= self::C) {
                continue;
            }
            $yaAsignado = 0.0;
            for ($n = $nIni; $n <= $total; $n++) {
                $yaAsignado += $c->cuotas[$nombre.'|'.$n]['previo'] ?? 0.0;
            }
            $bolsa = max(0.0, $bolsa - $yaAsignado);
            if ($bolsa <= self::C) {
                continue;
            }
            $candidato = '';
            $candidatos = 0;
            $corto = 0.0;
            for ($n = $nIni; $n <= min($total, $nFin - 1); $n++) {
                $k = $nombre.'|'.$n;
                if (! isset($c->cuotas[$k])) {
                    continue;
                }
                $v = $c->cuotas[$k];
                if ($v['fecha'] < $c->repIni || $v['fecha'] > $c->repFin || ($v['cancela'] > 0 && $v['cancela'] < $v['fecha'])) {
                    continue;
                }
                $neto = max(0.0, $v['monto'] - $v['previo']);
                $pagosSemana = $c->sumaEventos($k, fn ($e) => $e['fe'] > $fBase && $e['fe'] <= $c->repFin && $e['fq'] === $v['fecha']);
                if ($pagosSemana > self::M && $pagosSemana + self::C < $neto) {
                    $candidatos++;
                    $candidato = $k;
                    $corto = $neto - $pagosSemana;
                }
            }
            if ($candidatos !== 1) {
                if ($candidatos > 1) {
                    $c->incidencia('MULTI-ANTICIPO', $nombre, 'Dos o más cuotas parciales: no se reparte el saldo global sin folio; la meta no se ajusta.');
                }

                continue;
            }
            // DX:DZ puede incluir anticipos en cuotas FUTURAS: solo se descuenta el faltante de la única cuota que avanzó.
            if ($bolsa + self::C < $corto) {
                $c->incidencia('NETO-INSUFICIENTE', $nombre, 'El saldo base no respalda todo el faltante de la cuota; la meta no se ajusta.', ['importe' => $corto]);

                continue;
            }
            $descuento = min($corto, $bolsa);
            $c->cuotas[$candidato]['previo'] = min($c->cuotas[$candidato]['monto'], $c->cuotas[$candidato]['previo'] + $descuento);
            $c->estimadas[$candidato] = ($c->estimadas[$candidato] ?? 0.0) + $descuento;
            $c->conciliados[$nombre] = [$saldoBase, $maxNominal, $descuento, $nFin - $nIni];
            if ($ultimoIndice < $ult) {
                $c->notas['FOTO-ULTIMA|'.$candidato] = true;
            }
        }
    }

    /**
     * V12.9: un refinanciamiento está DOCUMENTADO cuando todas las cuotas EN..BX tienen
     * movimientos (ordinarios + descuento de refi) identificados por número y fecha y su
     * suma coincide con DX:DZ de la foto base. Las cuotas futuras solo sirven para
     * validar ese total: nunca se vuelven meta ni cobranza. En la cuota ya exigible se
     * reconoce el NETO REAL liquidado.
     */
    public function cierresRefinanciamiento(): void
    {
        $c = $this->c;
        if (! $c->tieneHist('contrato', 'fecha_transaccion', 'fecha_cuota', 'numero_cuota', 'operacion', 'transaccion', 'capital', 'interes', 'impuesto')) {
            return;
        }
        $baseD = $c->resumen($c->fotoInicio);
        $fBase = $c->fBase;
        $refFecha = [];
        $filas = $c->historial['filas'];
        // Paso 1: fecha de liquidación por refinanciamiento.
        foreach ($filas as $r) {
            $ct = V12Vba::norm($r['contrato'] ?? null);
            if (! isset($baseD[$ct])) {
                continue;
            }
            $f = V12Vba::fecha($r['fecha_transaccion'] ?? null);
            if ($f === null || $f <= $fBase || $f > $c->repFin) {
                continue;
            }
            if (! str_contains(V12Vba::norm($r['operacion'] ?? null), 'REFINANC') || V12Vba::norm($r['transaccion'] ?? null) !== 'DESCUENTO') {
                continue;
            }
            if (! isset($refFecha[$ct]) || $f < $refFecha[$ct]) {
                $refFecha[$ct] = $f;
            }
        }
        if (! $refFecha) {
            return;
        }
        // Paso 2: solo componentes ordinarios (capital + interés + IVA).
        $amort = new V12Amortizaciones($c);
        $mov = [];
        $atrasado = [];
        $fechaCuotaMov = [];
        $ambiguo = [];
        foreach ($filas as $r) {
            $ct = V12Vba::norm($r['contrato'] ?? null);
            if (! isset($refFecha[$ct])) {
                continue;
            }
            $f = V12Vba::fecha($r['fecha_transaccion'] ?? null);
            if ($f === null || $f <= $fBase || $f > $refFecha[$ct]) {
                continue;
            }
            $op = V12Vba::norm($r['operacion'] ?? null);
            $tr = V12Vba::norm($r['transaccion'] ?? null);
            if (! (($op === 'PAGO A CONTRATO' && $tr === 'PAGO') || (str_contains($op, 'REFINANC') && $tr === 'DESCUENTO'))) {
                continue;
            }
            if ($amort->esExcluido($amort->tipoMovimiento($op, $tr, $r)) || ! V12Vba::esNumerico($r['numero_cuota'] ?? null)) {
                continue;
            }
            $fq = V12Vba::fecha($r['fecha_cuota'] ?? null);
            if ($fq === null) {
                continue;
            }
            $n = V12Vba::clng($r['numero_cuota'] ?? null);
            $importe = V12Vba::suma(V12Vba::num($r['capital'] ?? null), V12Vba::num($r['interes'] ?? null), V12Vba::num($r['impuesto'] ?? null));
            if ($importe <= self::M) {
                continue;
            }
            $b = $baseD[$ct];
            if ($n < $b[0]) {
                $atrasado[$ct] = ($atrasado[$ct] ?? 0.0) + $importe;
            } elseif ($n <= $b[3]) {
                $k = $ct.'|'.$n;
                $mov[$k] = ($mov[$k] ?? 0.0) + $importe;
                if (! isset($fechaCuotaMov[$k])) {
                    $fechaCuotaMov[$k] = $fq;
                } elseif ($fechaCuotaMov[$k] !== $fq) {
                    $ambiguo[$k] = true;
                }
            }
        }
        // Paso 3: demostrar la liquidación completa SIN usar la ausencia de foto.
        foreach ($refFecha as $ct => $fr) {
            $b = $baseD[$ct];
            [$n0, $nTotal] = [$b[0], $b[3]];
            if ($n0 < 1 || $nTotal < $n0 || $nTotal > 500) {
                continue;
            }
            $saldoBase = (float) $b[5];
            if ($saldoBase <= self::M) {
                continue;
            }
            if ($b[1] > self::C && ($atrasado[$ct] ?? 0.0) + self::C < $b[1]) {
                continue;
            }
            $s = 0.0;
            for ($n = $n0; $n <= $nTotal; $n++) {
                $k = $ct.'|'.$n;
                if (! isset($fechaCuotaMov[$k]) || isset($ambiguo[$k])) {
                    continue 2;
                }
                $s += $mov[$k] ?? 0.0;
            }
            if (abs($s - $saldoBase) > self::C) {
                continue;
            }
            $c->cierresRefi[$ct] = ['fecha' => $fr, 'saldo_base' => $saldoBase, 'diario' => $s];
            $bolsa = max(0.0, ($nTotal - $n0 + 1) * $b[4] - $saldoBase);
            for ($n = $n0; $n <= $nTotal; $n++) {
                $k = $ct.'|'.$n;
                if (! isset($c->cuotas[$k])) {
                    continue;
                }
                $v = $c->cuotas[$k];
                $fq = $v['fecha'];
                if ($fq < $c->repIni || $fq > $c->repFin || $fq > $fr || $fechaCuotaMov[$k] !== $fq) {
                    continue;
                }
                if (! ($v['cancela'] <= 0 || $v['cancela'] >= $fq)) {
                    continue;
                }
                $cup = $v['monto'] - $v['previo'];
                $liquidado = $mov[$k] ?? 0.0;
                $delta = V12Vba::round2($cup - $liquidado);
                if ($liquidado > self::M && $delta > self::C && $delta <= $bolsa + self::C) {
                    $c->cuotas[$k]['previo'] = min($v['monto'], $v['previo'] + $delta);
                    $c->estimadas[$k] = ($c->estimadas[$k] ?? 0.0) + $delta;
                    $c->notas['REFI-CONCILIADO|'.$k] = true;
                    $c->conciliados[$ct] = [$saldoBase, $s, $delta, $nTotal - $n0 + 1];
                    $bolsa = max(0.0, $bolsa - $delta);
                }
            }
        }
    }

    /**
     * Última cuota cuya deuda neta inicial es MAYOR que la nominal: se corrige solo si
     * los atrasos de la fotografía final cuadran con la base menos lo cobrado.
     */
    public function ultimaCuota(): void
    {
        $c = $this->c;
        $bf = $c->resumen($c->fotoInicio);
        $cf = $c->resumen($c->numeroFotoFinal());
        foreach ($bf as $ct => $b) {
            if (! isset($cf[$ct])) {
                continue;
            }
            $n = $b[0];
            if ($n < 1 || $n !== $b[3]) {
                continue;
            }
            $k = $ct.'|'.$n;
            if (! isset($c->cuotas[$k])) {
                continue;
            }
            $v = $c->cuotas[$k];
            if ($v['fecha'] < $c->repIni || $v['fecha'] > $c->repFin) {
                continue;
            }
            $montoFinal = $b[5] + $v['previo'];
            $nominal = $v['monto'];
            $dif = V12Vba::round2($montoFinal - $nominal);
            if ($dif <= self::C || $dif > $nominal * 0.02) {
                continue;
            }
            if ($v['cancela'] > 0 && $v['cancela'] < $v['fecha']) {
                continue;
            }
            $cobrado = 0.0;
            foreach ($c->cuotasDe[$ct] ?? [] as $kk) {
                $cobrado += $c->sumaEventos($kk, fn ($e) => $e['fe'] > $c->fBase && $e['fe'] <= $c->repFin);
            }
            $esperado = max(0.0, V12Corrida::atrasoResumen($b, $c->fBase) + $b[5] - $cobrado);
            if (abs($esperado - V12Corrida::atrasoResumen($cf[$ct], $c->repFin)) > self::C) {
                continue;
            }
            $c->cuotas[$k]['monto'] = $montoFinal;
            $c->notas['ULTIMA-SALDO|'.$k] = true;
        }
    }
}
