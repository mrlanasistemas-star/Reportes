<?php

namespace App\Services\Efectividad\V12;

/**
 * MR_ConstruirReporte + MR_ValidarBuckets (V12.9.4).
 *
 *  - SANO por cobrar = saldo NETO programado/exigible del periodo pendiente al inicio
 *    (cuota ordinaria − pagado antes del periodo). Los cobros del periodo NO lo reducen.
 *  - Una cuota futura cancelada por refinanciamiento solo conserva meta por el abono
 *    ordinario REAL previo al cierre.
 *  - Atraso / Vencido: buckets dinámicos (cobros del periodo + saldo pendiente al corte);
 *    la deuda histórica no cobrada existe una sola vez. No se suman al Sano por cobrar.
 *  - Cuota que vence HOY sigue SANA hasta terminar el día.
 *  - Regla 24: si el contrato cambia de gestor en la semana, solo la META pendiente pasa
 *    al nuevo gestor; los cobros previos conservan su gestor y clasificación.
 */
final class V12Buckets
{
    private const M = V12Vba::MARGEN;

    /** @var array<string,float> */
    public array $meta = [];

    /** @var array<string,float> */
    public array $pcS = [];

    /** @var array<string,float> */
    public array $cobS = [];

    /** @var array<string,float> */
    public array $pcA = [];

    /** @var array<string,float> */
    public array $cobA = [];

    /** @var array<string,float> */
    public array $pcV = [];

    /** @var array<string,float> */
    public array $cobV = [];

    /** @var array<string, array<string,mixed>> clave cuota => detalle de su clasificación (trazabilidad) */
    public array $detalle = [];

    /** @var array<string,int> contrato => número de transferencias de gestor aplicadas */
    public array $transferencias = [];

    public function __construct(private readonly V12Corrida $c) {}

    public function construir(): void
    {
        $c = $this->c;
        $ini = $c->repIni;
        $fin = $c->repFin;
        $fBase = $c->fBase;
        $usadoHistorico = [];
        $vencimiento = [];

        foreach ($c->cuotas as $k => $v) {
            $ct = $v['contrato'];
            $fq = $v['fecha'];
            $mat = $v['matura'];
            $total = $v['monto'];
            $previo = $v['previo'];
            $can = $v['cancela'];
            if (! isset($vencimiento[$ct]) || $mat > $vencimiento[$ct]) {
                $vencimiento[$ct] = $mat;
            }
            if ($fq > $fin) {
                continue;
            }
            // Cuota posterior al cierre contractual: solo si hubo pago ORDINARIO en el periodo antes del cierre.
            if ($can > 0 && $can < $fq && ! $c->pagoOrdinarioAntesDeLiquidar($k, $ini, $can)) {
                continue;
            }
            [$ff, $mm, $tHist] = $this->eventosOrdenados($k, $fq);
            $n = count($ff);

            if ($fq <= $fBase) {
                // Cuota histórica: se aplica contra el atraso REAL del corte inicial (ya neto de pagos previos).
                $pagadoCuota = 0.0;
                for ($i = 0; $i < $n; $i++) {
                    $fRec = $ff[$i];
                    if ($fRec < $ini || $fRec > $fin) {
                        continue;
                    }
                    $disponible = max(0.0, ($c->atrasoBase[$ct] ?? 0.0) - ($usadoHistorico[$ct] ?? 0.0));
                    $amt = min($mm[$i], max(0.0, $total - $pagadoCuota));
                    $pagado = min($amt, $disponible);
                    if ($pagado <= self::M) {
                        continue;
                    }
                    $pagadoCuota += $pagado;
                    $usadoHistorico[$ct] = ($usadoHistorico[$ct] ?? 0.0) + $pagado;
                    $this->cobro($k, $ct, $fRec, $fq, $mat, $pagado);
                }

                continue;
            }

            // Meta sana: saldo NETO pendiente al comienzo del periodo.
            $cuentaMeta = $fq >= $ini && $fq <= $fin;
            // Anticipo previo: máximo entre el siguiente pago de SALDOS y los pagos históricos (nunca la suma).
            $pagadoCuota = 0.0;
            for ($i = 0; $i < $n; $i++) {
                if ($tHist[$i] <= $fBase) {
                    $pagadoCuota += $mm[$i];
                }
            }
            $previo = min($total, max($previo, $pagadoCuota));
            $saldo = max(0.0, $total - $previo);
            if ($can > 0 && $can < $fq) {
                // Cuota futura CANCELADA: no exigir el resto extinguido por refinanciamiento.
                $saldo = min($saldo, $c->importeOrdinarioEnCancelacion($k, $ini, $can));
            }
            $metaCuota = 0.0;
            $gestorMeta = '';
            if ($cuentaMeta && $saldo > self::M) {
                $gestorMeta = $c->responsable($ct, $fq);
                if ($gestorMeta !== '') {
                    $this->meta[$gestorMeta] = ($this->meta[$gestorMeta] ?? 0.0) + $saldo;
                    $metaCuota = $saldo;
                }
            }
            $this->detalle[$k] = ['meta' => $metaCuota, 'gestor_meta' => $gestorMeta, 'previo_final' => $previo];
            for ($i = 0; $i < $n; $i++) {
                $fRec = $ff[$i];
                if ($fRec > $fin || $tHist[$i] <= $fBase) {
                    continue;
                }
                $pagado = min($mm[$i], $saldo);
                $saldo = max(0.0, $saldo - $pagado);
                if ($pagado > self::M && $fRec >= $ini && $fRec <= $fin) {
                    $this->cobro($k, $ct, $fRec, $fq, $mat, $pagado);
                }
            }
            if ($saldo > self::M && $fq <= $fin) {
                $grupo = $c->responsable($ct, $fin);
                if ($grupo !== '') {
                    $buck = self::tipoBucket($fin, $fq, $mat);
                    $this->agregarPC($buck, $grupo, $saldo);
                    $this->detalle[$k]['pendiente'] = ['bucket' => $buck, 'gestor' => $grupo, 'importe' => $saldo];
                }
            }
            // Regla 24: solo la META del saldo pendiente pasa al nuevo gestor.
            if ($cuentaMeta) {
                for ($j = 1; $j <= $fin - $fq; $j++) {
                    $dCambio = $fq + $j;
                    $antes = $c->responsable($ct, $dCambio - 1);
                    $nuevo = $c->responsable($ct, $dCambio);
                    if ($antes === '' || $nuevo === '' || $antes === $nuevo) {
                        continue;
                    }
                    $traspaso = max(0.0, $total - $previo);
                    for ($i = 0; $i < $n; $i++) {
                        if ($tHist[$i] > $fBase && $ff[$i] < $dCambio) {
                            $traspaso = max(0.0, $traspaso - $mm[$i]);
                        }
                    }
                    if ($traspaso > self::M) {
                        $this->meta[$antes] = ($this->meta[$antes] ?? 0.0) - $traspaso;
                        $this->meta[$nuevo] = ($this->meta[$nuevo] ?? 0.0) + $traspaso;
                        $this->transferencias[$ct] = ($this->transferencias[$ct] ?? 0) + 1;
                        $this->detalle[$k]['transferencias'][] = ['fecha' => V12Vba::ymd($dCambio), 'de' => $antes, 'a' => $nuevo, 'meta' => $traspaso];
                    }
                }
            }
        }

        // Deuda histórica no cobrada: permanece UNA sola vez en su bucket al corte.
        foreach ($c->atrasoBase as $ct => $importe) {
            $saldo = max(0.0, $importe - ($usadoHistorico[$ct] ?? 0.0));
            if ($saldo <= self::M) {
                continue;
            }
            $grupo = $c->responsable((string) $ct, $fin);
            if ($grupo === '') {
                continue;
            }
            $buck = isset($vencimiento[$ct]) && $fin > $vencimiento[$ct] ? 'V' : 'A';
            $this->agregarPC($buck, $grupo, $saldo);
        }

        // SANO POR COBRAR = la META neta del periodo (no se mueve por saldos migrados).
        $this->pcS = [];
        foreach ($this->meta as $g => $m) {
            if ($m > self::M) {
                $this->pcS[$g] = $m;
            }
        }
    }

    /** MR_ValidarBuckets — controles de consistencia; un fallo es error de cálculo. */
    public function validar(): void
    {
        foreach (array_keys($this->c->grupos) as $g) {
            $nombre = str_replace('~', ' / ', $g);
            if (($this->cobS[$g] ?? 0.0) > ($this->pcS[$g] ?? 0.0) + V12Vba::CENTAVOS) {
                throw new V12Exception('EC-V12-291', "La cobranza sana supera el Sano por cobrar: {$nombre}.");
            }
            if (($this->cobA[$g] ?? 0.0) > ($this->pcA[$g] ?? 0.0) + V12Vba::CENTAVOS) {
                throw new V12Exception('EC-V12-292', "La cobranza de atraso supera su Por cobrar: {$nombre}.");
            }
            if (($this->cobV[$g] ?? 0.0) > ($this->pcV[$g] ?? 0.0) + V12Vba::CENTAVOS) {
                throw new V12Exception('EC-V12-293', "La cobranza vencida supera su Por cobrar: {$nombre}.");
            }
            if (abs(($this->meta[$g] ?? 0.0) - ($this->pcS[$g] ?? 0.0)) > V12Vba::CENTAVOS) {
                throw new V12Exception('EC-V12-294', "La referencia interna difiere del Sano por cobrar: {$nombre}.");
            }
        }
    }

    /** MR_TipoBucket — V si ya venció el contrato, A si pasó la fecha de cuota, S si no (incluye el mismo día). */
    public static function tipoBucket(int $f, int $cuota, int $maturacion): string
    {
        if ($f > $maturacion) {
            return 'V';
        }

        return $f > $cuota ? 'A' : 'S';
    }

    private function cobro(string $k, string $ct, int $fRec, int $fq, int $mat, float $pagado): void
    {
        $grupo = $this->c->responsable($ct, $fRec);
        if ($grupo === '') {
            return;
        }
        $buck = self::tipoBucket($fRec, $fq, $mat);
        // MR_AplicarCobro: el cobro de atraso/vencido también integra su Por cobrar; el sano no.
        match ($buck) {
            'S' => $this->cobS[$grupo] = ($this->cobS[$grupo] ?? 0.0) + $pagado,
            'A' => [$this->cobA[$grupo] = ($this->cobA[$grupo] ?? 0.0) + $pagado, $this->pcA[$grupo] = ($this->pcA[$grupo] ?? 0.0) + $pagado],
            default => [$this->cobV[$grupo] = ($this->cobV[$grupo] ?? 0.0) + $pagado, $this->pcV[$grupo] = ($this->pcV[$grupo] ?? 0.0) + $pagado],
        };
        $this->detalle[$k]['cobros'][] = ['fecha' => V12Vba::ymd($fRec), 'bucket' => $buck, 'gestor' => $grupo, 'importe' => $pagado];
    }

    /** MR_AgregarPC — el saldo migrado a SANO no mueve la meta neta. */
    private function agregarPC(string $buck, string $grupo, float $m): void
    {
        if ($buck === 'A') {
            $this->pcA[$grupo] = ($this->pcA[$grupo] ?? 0.0) + $m;
        } elseif ($buck === 'V') {
            $this->pcV[$grupo] = ($this->pcV[$grupo] ?? 0.0) + $m;
        }
    }

    /**
     * Eventos de la cuota ordenados por fecha de reconocimiento = máx(fecha transacción,
     * fecha cuota), con el mismo ordenamiento por intercambio de la hoja original.
     *
     * @return array{0: list<int>, 1: list<float>, 2: list<int>}
     */
    private function eventosOrdenados(string $k, int $fq): array
    {
        $ff = [];
        $mm = [];
        $tHist = [];
        foreach ($this->c->eventos[$k] ?? [] as $e) {
            $ff[] = max($e['fe'], $fq);
            $mm[] = $e['monto'];
            $tHist[] = $e['fe'];
        }
        $n = count($ff);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($ff[$j] < $ff[$i]) {
                    [$ff[$i], $ff[$j]] = [$ff[$j], $ff[$i]];
                    [$mm[$i], $mm[$j]] = [$mm[$j], $mm[$i]];
                    [$tHist[$i], $tHist[$j]] = [$tHist[$j], $tHist[$i]];
                }
            }
        }

        return [$ff, $mm, $tHist];
    }
}
