<?php

namespace App\Services\Efectividad\V12;

/**
 * Trazabilidad por cuota, conciliación por contrato y estado del reporte (V12.9.4).
 *
 *  - trazabilidad(): MR_RegistrarTrazabilidad — evidencia de cada cuota (comprobada
 *    o inferida). Se ejecuta ANTES de construir los buckets (fija `previo` final).
 *  - conciliarCartera(): MR_ConciliarCartera — control independiente contra la
 *    fotografía de cierre. La diferencia NO es cobro, anticipo, descuento ni
 *    condonación y JAMÁS modifica el reporte; una desaparición no es liquidación.
 *  - resumen(): MR_FinalizarAuditoria — PRELIMINAR / CONCILIADO.
 */
final class V12Auditoria
{
    private const C = V12Vba::CENTAVOS;

    private const M = V12Vba::MARGEN;

    /** @var list<array<string,mixed>> */
    public array $traza = [];

    /** @var list<array<string,mixed>> */
    public array $conciliacion = [];

    public function __construct(private readonly V12Corrida $c) {}

    public function trazabilidad(): void
    {
        $c = $this->c;
        $this->traza = [];
        foreach ($c->amortizaciones as $a) {
            $clave = $a['contrato'].'|'.$a['numero'];
            $original = $a['previo'];
            $cuota = $a['monto'];
            $historico = 0.0;
            $movs = 0.0;
            $estimado = 0.0;
            $evidencia = 'RECONSTRUCCION / NUMERO SIGUIENTE';
            $obs = 'Sin ajuste adicional por saldo global';
            $inferido = false;
            if (isset($c->cuotas[$clave])) {
                $v = $c->cuotas[$clave];
                $cuota = $v['monto'];
                foreach ($c->eventos[$clave] ?? [] as $e) {
                    if ($e['fe'] <= $c->fBase) {
                        $historico += $e['monto'];
                    } elseif ($e['fe'] >= $c->repIni && $e['fe'] <= $c->repFin) {
                        $movs += $e['monto'];
                    }
                }
                $historico = min($cuota, max(0.0, $historico));
                $previo = min($cuota, max($v['previo'], $historico));
                $estimado = $c->estimadas[$clave] ?? 0.0;
                if ($estimado > self::M) {
                    $inferido = true;
                    if (isset($c->notas['REFI-CONCILIADO|'.$clave])) {
                        $evidencia = 'REFINANCIAMIENTO / DIARIO CUADRADO';
                        $obs = 'Importe liquidado de cuota exigible; TOTAL refi + pagos = saldo ordinario pendiente de la base.';
                    } elseif (isset($c->notas['FOTO-ATRASO|'.$clave])) {
                        $evidencia = 'ATRASO INDIVIDUAL + SALDO BASE';
                        $obs = 'Saldo neto inferido del atraso individual de la fotografía y los pagos por cuota; sin folio histórico previo.';
                    } elseif (isset($c->notas['FOTO-ULTIMA|'.$clave])) {
                        $evidencia = 'ULTIMA FOTO ANTES DE DESAPARECER';
                        $obs = 'Cuota avanzada con abono; la ausencia posterior NO acredita liquidación.';
                    } else {
                        $evidencia = 'SALDO GLOBAL / CUOTA ESTIMADA';
                        $obs = 'Conciliado en total con el saldo ordinario pendiente. Asignación a la cuota no comprobada.';
                    }
                } elseif (isset($c->notas['ULTIMA-SALDO|'.$clave])) {
                    $evidencia = 'ULTIMA CUOTA / SALDO Y CIERRE';
                    $obs = 'Cuota real acreditada por el saldo pendiente y la conciliación de atrasos al corte.';
                } elseif ($historico > $original + self::M) {
                    $evidencia = 'INGRESO HISTORICO POR CUOTA';
                    $obs = 'Contrato, número de cuota y fecha del movimiento.';
                } elseif ($original > self::M) {
                    $evidencia = 'SIGUIENTE PAGO / SALDO BASE';
                } else {
                    $evidencia = 'CUOTA CONTRACTUAL SIN ANTICIPO';
                }
                $c->cuotas[$clave]['previo'] = $previo;
            } else {
                $previo = min($cuota, max(0.0, $original));
                $evidencia = 'SIN DETALLE EN MEMORIA';
                $obs = 'Verificar contrato/cuota reconstruidos.';
            }
            $neto = max(0.0, $cuota - $previo);
            if (isset($c->cuotas[$clave])) {
                $v = $c->cuotas[$clave];
                if ($v['fecha'] <= $c->fBase) {
                    // Cuota histórica: su saldo real se controla a nivel contrato (atraso de la foto base).
                    $neto = 0.0;
                    $evidencia = 'HISTORICA / SALDO BASE GLOBAL';
                    $obs = 'No atribuir saldo exigible a una cuota histórica sin desglose.';
                } elseif ($v['cancela'] > 0 && $v['cancela'] < $v['fecha'] && $v['fecha'] >= $c->repIni && $v['fecha'] <= $c->repFin) {
                    $neto = min($neto, $c->importeOrdinarioEnCancelacion($clave, $c->repIni, $v['cancela']));
                    $evidencia = 'CUOTA FUTURA CANCELADA / ABONO REAL';
                    $obs = 'Meta acotada a abonos reales previos al refinanciamiento; el descuento de la cuota futura NO se reconoce.';
                }
            }
            $this->traza[] = [
                'contrato' => $a['contrato'], 'numero' => $a['numero'], 'fecha' => V12Vba::ymd($a['fecha']),
                'vencimiento_contrato' => V12Vba::ymd($a['matura']), 'cuota' => $cuota, 'previo_reconstruido' => $original,
                'historico' => $historico, 'estimado' => $estimado, 'previo' => $previo, 'neto' => $neto, 'movimientos' => $movs,
                'saldo' => max(0.0, $neto - $movs), 'cancelacion' => $a['cancela'] > 0 ? V12Vba::ymd($a['cancela']) : null,
                'fecha_real' => $a['fecha_real'], 'evidencia' => $evidencia, 'observacion' => $obs,
                'calidad' => $inferido ? 'INFERIDO' : 'COMPROBADO',
            ];
            if ($inferido && $a['fecha'] >= $c->repIni && $a['fecha'] <= $c->repFin) {
                $c->incidencia('META-INFERIDA', $a['contrato'], "Cuota {$a['numero']} ({$this->dmy($a['fecha'])}): {$obs}", ['referencia' => $a['numero'], 'fecha' => V12Vba::ymd($a['fecha']), 'importe' => $estimado]);
            }
        }
    }

    public function conciliarCartera(): void
    {
        $c = $this->c;
        $bf = $c->resumen($c->fotoInicio);
        $cf = $c->resumen($c->numeroFotoFinal());
        $ini = $c->repIni;
        $fin = $c->repFin;
        $due = [];
        $paid = [];
        $dueHoy = [];
        $cobHoy = [];
        $histUsado = [];
        $keys = [];
        $fotosDia = [];
        for ($d = 0; $d <= 7; $d++) {
            $fecha = $c->domingo + $d;
            if (isset($c->fotos[$d]) && $fecha >= $c->fBase && $fecha <= $fin) {
                $fotosDia[$d] = $c->resumen($d);
            }
        }
        foreach ($c->cuotas as $k => $v) {
            $ct = $v['contrato'];
            $keys[$ct] = true;
            $fq = $v['fecha'];
            $can = $v['cancela'];
            $nom = $v['monto'];
            if ($fq >= $ini && $fq <= $fin) {
                if ($can <= 0 || $can >= $fq) {
                    $deuda = max(0.0, $nom - $v['previo']);
                    $due[$ct] = ($due[$ct] ?? 0.0) + $deuda;
                    if ($fq === $fin) {
                        $dueHoy[$ct] = ($dueHoy[$ct] ?? 0.0) + $deuda;
                    }
                } elseif ($c->pagoOrdinarioAntesDeLiquidar($k, $ini, $can)) {
                    // El abono ordinario es exigible; el remanente cancelado por refi ya NO lo es.
                    $deuda = min(max(0.0, $nom - $v['previo']), $c->importeOrdinarioEnCancelacion($k, $ini, $can));
                    $due[$ct] = ($due[$ct] ?? 0.0) + $deuda;
                    if ($fq === $fin) {
                        $dueHoy[$ct] = ($dueHoy[$ct] ?? 0.0) + $deuda;
                    }
                }
            }
            if (isset($c->eventos[$k])) {
                $monto = 0.0;
                foreach ($c->eventos[$k] as $e) {
                    $fRec = max($e['fe'], $fq);
                    if ($e['fe'] <= $c->fBase || $fRec < $ini || $fRec > $fin) {
                        continue;
                    }
                    $monto += $e['monto'];
                }
                if ($fq <= $c->fBase) {
                    if (isset($bf[$ct])) {
                        $espera = max(0.0, V12Corrida::atrasoResumen($bf[$ct], $c->fBase) - ($histUsado[$ct] ?? 0.0));
                        $ingreso = min($espera, $monto);
                        $histUsado[$ct] = ($histUsado[$ct] ?? 0.0) + $ingreso;
                    } else {
                        $ingreso = 0.0;
                    }
                } else {
                    $ingreso = min($monto, max(0.0, $nom - $v['previo']));
                }
                if ($ingreso > self::M) {
                    $paid[$ct] = ($paid[$ct] ?? 0.0) + $ingreso;
                    if ($fq === $fin) {
                        $cobHoy[$ct] = ($cobHoy[$ct] ?? 0.0) + $ingreso;
                    }
                }
            }
        }
        foreach (array_keys($bf) as $k) {
            $keys[$k] = true;
        }
        foreach (array_keys($cf) as $k) {
            $keys[$k] = true;
        }

        $this->conciliacion = [];
        foreach (array_keys($keys) as $ct) {
            $ct = (string) $ct;
            $baseAtr = 0.0;
            $finAtr = 0.0;
            $gestor = '';
            if (isset($bf[$ct])) {
                $baseAtr = V12Corrida::atrasoResumen($bf[$ct], $c->fBase);
                $gestor = $bf[$ct][6];
            }
            if (isset($cf[$ct])) {
                $finAtr = V12Corrida::atrasoResumen($cf[$ct], $fin);
                if ($gestor === '') {
                    $gestor = $cf[$ct][6];
                }
            }
            $deuda = $due[$ct] ?? 0.0;
            $ingreso = $paid[$ct] ?? 0.0;
            // Cuota que vence HOY sigue SANA hasta finalizar el día: no se exige como atraso en la foto de ese cierre.
            $espera = max(0.0, $baseAtr + $deuda - $ingreso - max(0.0, ($dueHoy[$ct] ?? 0.0) - ($cobHoy[$ct] ?? 0.0)));
            $dif = $espera - $finAtr;
            $sinFotoFinal = isset($bf[$ct]) && ! isset($cf[$ct]);
            $texto = 'Contrastar cuota, pagos aplicados y fotografías; no ajustar automáticamente.';
            $ultFoto = null;
            if ($sinFotoFinal) {
                $ultSaldo = 0.0;
                $ultEstado = '';
                foreach ($fotosDia as $d => $fo) {
                    if (isset($fo[$ct])) {
                        $ultFoto = $c->domingo + $d;
                        $ultSaldo = (float) $fo[$ct][8];
                        $ultEstado = (string) $fo[$ct][2];
                    }
                }
                if (isset($c->cierresRefi[$ct])) {
                    if (abs($dif) <= self::C) {
                        $estatus = 'CIERRE REFI CONTABILIZADO';
                        $texto = 'Diario completo conciliado con el saldo ordinario pendiente de la base; sin diferencia residual de cartera.';
                    } else {
                        $estatus = 'CIERRE REFI CON DIFERENCIA';
                        $texto = 'El diario de liquidación cuadra con el saldo ordinario pendiente, pero aún existe diferencia de cartera '.V12Vba::dinero($dif).'. Revisar.';
                    }
                } elseif (abs($dif) <= self::C) {
                    $estatus = 'SIN FOTO FINAL - CUADRADO';
                } elseif (str_contains($ultEstado, 'CERRAD') && $ultSaldo <= self::C) {
                    $estatus = 'CIERRE EN FOTO INTERMEDIA';
                } else {
                    $estatus = 'DESAPARICION SIN SOPORTE';
                }
                if (! str_starts_with($estatus, 'CIERRE REFI')) {
                    $texto = $ultFoto !== null
                        ? 'Última foto '.V12Vba::dmy($ultFoto).'; saldo en ella '.V12Vba::dinero($ultSaldo).'; diferencia pendiente '.V12Vba::dinero($dif).'. No se supone pago ni liquidación.'
                        : 'Falta constancia del cierre: no suponer pago ni cancelación.';
                }
                $gestor = 'SIN FOTO FINAL';
            } elseif (! isset($bf[$ct]) && ! isset($cf[$ct])) {
                $gestor = 'NO EN FOTOS';
                $estatus = abs($dif) <= self::C ? 'COINCIDE' : 'POR CONCILIAR';
            } else {
                $estatus = abs($dif) <= self::C ? 'COINCIDE' : 'POR CONCILIAR';
            }
            $this->conciliacion[] = [
                'contrato' => $ct, 'gestor' => $gestor, 'atraso_base' => $baseAtr, 'exigible_neto' => $deuda,
                'cobranza_elegible' => $ingreso, 'saldo_teorico' => $espera, 'atraso_cierre' => $finAtr,
                'diferencia' => $dif, 'estatus' => $estatus, 'observacion' => $texto,
            ];
            if (in_array($estatus, ['POR CONCILIAR', 'CIERRE REFI CON DIFERENCIA', 'DESAPARICION SIN SOPORTE'], true)) {
                $c->incidencia($estatus === 'DESAPARICION SIN SOPORTE' ? 'DESAPARICION-SIN-SOPORTE' : 'DIFERENCIA-CIERRE', $ct, $texto, ['importe' => $dif]);
            }
        }
    }

    /**
     * MR_FinalizarAuditoria.
     *
     * @return array<string,mixed>
     */
    public function resumen(float $sanoPC, float $cobranza): array
    {
        $c = $this->c;
        $nDif = 0;
        $totalDif = 0.0;
        $nFalt = 0;
        $totalFalt = 0.0;
        $cierres = 0;
        foreach ($this->conciliacion as $r) {
            if ($r['estatus'] === 'POR CONCILIAR' || $r['estatus'] === 'CIERRE REFI CON DIFERENCIA') {
                $nDif++;
                $totalDif += $r['diferencia'];
            } elseif ($r['estatus'] === 'CIERRE REFI CONTABILIZADO') {
                $cierres++;
            } elseif ($r['estatus'] === 'DESAPARICION SIN SOPORTE') {
                $nFalt++;
                $totalFalt += $r['diferencia'];
            }
        }
        $preliminar = $nDif > 0 || $nFalt > 0 || count($c->estimadas) > 0;

        return [
            'estado' => $preliminar ? 'PRELIMINAR' : 'CONCILIADO',
            'estado_texto' => $preliminar ? 'Preliminar: revisar evidencias antes de comisionar.' : 'Conciliado en la muestra.',
            'sano_pc' => $sanoPC,
            'cobranza_total' => $cobranza,
            'efectividad' => $sanoPC > self::M ? $cobranza / $sanoPC : null,
            'mora' => $sanoPC - $cobranza,
            'contratos_ajustados' => count($c->conciliados),
            'cuotas_inferidas' => count($c->estimadas),
            'importe_inferido' => array_sum($c->estimadas),
            'contratos_con_diferencia' => $nDif,
            'diferencia_sin_asignar' => $totalDif,
            'desapariciones_sin_soporte' => $nFalt,
            'saldo_sin_soporte' => $totalFalt,
            'cierres_refi_comprobados' => $cierres,
        ];
    }

    private function dmy(int $f): string
    {
        return V12Vba::dmy($f);
    }
}
