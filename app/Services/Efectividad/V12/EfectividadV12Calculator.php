<?php

namespace App\Services\Efectividad\V12;

/**
 * Motor de Efectividad de Cobranza — metodología V12.9.4
 * ("MR LANA - EFECTIVIDAD V12.9.4 | APORTE POR BUCKET CONTRA SANO").
 *
 * Referencia funcional: `docs/efectividad/ultimaVersion/` (archivo de Córdoba).
 * Documentación: `docs/efectividad/v12/README.md`. El motor V4 se conserva como
 * referencia histórica en `App\Services\Efectividad\V4`.
 *
 * Reglas principales:
 *  - SANO por cobrar = saldo NETO programado/exigible del periodo realmente
 *    pendiente al inicio (cuota ordinaria − anticipos/pagos previos al periodo,
 *    reconstruidos por cuota). Los cobros del periodo no lo reducen.
 *  - % Sano, % Atraso y % Vencido = cobrado de cada bucket / Sano por cobrar;
 *    Efectividad = suma de los tres aportes, SIN tope de 100 %.
 *  - Atraso y Vencido por cobrar son vistas de control: no se suman al Sano.
 *  - Sin base (Sano por cobrar = 0) no hay porcentaje: `null`.
 *  - Gestores con recuperación y meta cero participan en el consolidado (Regla 32).
 *  - Mora = Sano por cobrar − Cobranza total (puede ser negativa).
 *
 * Etapas (mismo orden que la metodología): fotografías → atraso inicial →
 * amortizaciones → aplicaciones de ingresos → conciliación del neto (foto base,
 * atraso individual, anticipos intermedios, diario de refinanciamientos, última
 * cuota) → trazabilidad → buckets y meta sana → validación → reporte →
 * conciliación con la foto de corte → estado del reporte.
 *
 * Sin base de datos: trabaja solo con las filas que recibe.
 */
class EfectividadV12Calculator
{
    public const ENGINE_VERSION = 'mrlana-v12.9.4-2026-11';

    public const METODOLOGIA = 'V12.9.4';

    public const LEYENDA = 'PORCENTAJES: Cobrado de cada bucket / Sano por cobrar | D + G + J = Efectividad Total | Sin doble cobro | TOTAL sin tope | Sin moratorios';

    /** Nombre de cada fotografía (índice = días desde el domingo base). */
    public const NOMBRES_FOTO = [
        'CARTERA DOMINGO', 'CARTERA LUNES', 'CARTERA MARTES', 'CARTERA MIERCOLES',
        'CARTERA JUEVES', 'CARTERA VIERNES', 'CARTERA SABADO', 'CARTERA DOMINGO CORTE',
    ];

    /** Columnas obligatorias de cada fotografía de cartera. */
    public const COLUMNAS_FOTO_OBLIGATORIAS = [
        'contrato', 'oficina', 'nombre_promotor', 'fecha_primer_pago', 'cuota', 'periodicidad', 'pagos',
        'numero_siguiente_pago', 'capital_pendiente', 'interes_pendiente', 'impuesto_pendiente',
    ];

    /** Columnas obligatorias de INGRESOS / historial. */
    public const COLUMNAS_INGRESOS_OBLIGATORIAS = [
        'contrato', 'operacion', 'transaccion', 'fecha_transaccion', 'fecha_cuota', 'capital', 'interes', 'impuesto',
    ];

    public const MODOS = ['DIARIO', 'ACUMULADO', 'SEMANAL'];

    /**
     * @param  array{modo:string, domingo_base:mixed, fecha_corte:mixed}  $parametros
     * @param  array<int, array{columnas: list<string>, filas: iterable<array<string,mixed>>}>  $fotos  índice = días desde el domingo base
     * @param  array{columnas: list<string>, filas: iterable<array<string,mixed>>}  $ingresos  ingresos del corte (validación de encabezados)
     * @param  array{columnas: list<string>, filas: iterable<array<string,mixed>>}  $historial  historial de ingresos (corte + semanas previas, sin duplicar)
     * @return array<string,mixed>
     */
    public function calculate(array $parametros, array $fotos, array $ingresos, array $historial, bool $conTraza = false): array
    {
        $c = $this->prepararCorrida($parametros, $fotos, $ingresos, $historial);

        // Fotografías: gestor efectivo por contrato y día.
        for ($i = $c->fotoInicio; $i <= $c->numeroFotoFinal(); $i++) {
            $this->leerFoto($c, $i);
        }
        // Atraso inicial (en DIARIO la base es el cierre del día anterior).
        $this->cargarAtrasos($c);

        $amort = new V12Amortizaciones($c);
        $amort->generar();
        $amort->leer();
        if (! $c->cuotas) {
            throw new V12Exception('EC-V12-205', 'No se encontraron cuotas. Verifica las fotografías de cartera cargadas.');
        }
        $amort->aplicarIngresos(V12Vba::dia(2000, 1, 1));

        $neto = new V12ConciliadorNeto($c);
        $neto->fotoBase();
        $neto->atrasoIndividual();
        $neto->intermedio();
        $neto->cierresRefinanciamiento();
        $neto->ultimaCuota();

        $audit = new V12Auditoria($c);
        $audit->trazabilidad();

        $b = new V12Buckets($c);
        $b->construir();
        $b->validar();

        [$filas, $sinMovimientos, $gt] = $this->filasReporte($c, $b);

        $audit->conciliarCartera();
        $auditoria = $audit->resumen((float) ($gt['sano_pc'] ?? 0.0), (float) ($gt['cobranza_total'] ?? 0.0));
        $auditoria['transferencias_gestor'] = array_sum($b->transferencias);

        $resultado = [
            'engine_version' => self::ENGINE_VERSION,
            'metodologia' => self::METODOLOGIA,
            'parametros' => [
                'modo' => $c->modo,
                'domingo_base' => V12Vba::ymd($c->domingo),
                'fecha_corte' => V12Vba::ymd($c->corte),
                'foto_base' => V12Vba::ymd($c->fBase),
                'inicio_ingresos' => V12Vba::ymd($c->repIni),
                'fin_ingresos' => V12Vba::ymd($c->repFin),
                'semana_inicio' => V12Vba::ymd($c->domingo + 1),
                'semana_fin' => V12Vba::ymd($c->domingo + 7),
            ],
            'filas' => $filas,
            'incidencias' => array_values($c->incidencias),
            'auditoria' => $auditoria,
            'estadisticas' => [
                'contratos' => count($c->cuotasDe),
                'cuotas' => count($c->cuotas),
                'cuotas_con_cobro' => count($c->eventos),
                'cuotas_inferidas' => count($c->estimadas),
                'contratos_conciliados' => count($c->conciliados),
                'transferencias_gestor' => array_sum($b->transferencias),
            ],
            'sin_movimientos' => $sinMovimientos,
        ];
        if ($conTraza) {
            $resultado['amortizaciones'] = $audit->traza;
            $resultado['conciliacion'] = $audit->conciliacion;
            $resultado['detalle_cuotas'] = $b->detalle;
        }

        return $resultado;
    }

    /**
     * Reconstruye filas (promotores, subtotales por ruta, por sucursal si hay más de
     * una, y GRAN TOTAL) a partir de importes base por promotor. Lo usan filtros y
     * agregación mensual: los porcentajes se recalculan desde importes, nunca se suman.
     *
     * @param  list<array{oficina:string, promotor:string, sucursal?:string, sano_pc:float, sano_cob:float, atraso_pc:float, atraso_cob:float, vencido_pc:float, vencido_cob:float}>  $promotores
     * @return list<array<string,mixed>>
     */
    public function construirFilas(array $promotores, ?string $ultimaSucursal = null): array
    {
        $sucursales = array_unique(array_map(fn ($p) => $p['sucursal'] ?? '', $promotores));
        $conSucursal = count($sucursales) > 1;
        $orden = fn ($p) => ($conSucursal ? (($p['sucursal'] ?? '') === $ultimaSucursal ? '~' : '').V12Vba::norm($p['sucursal'] ?? '').'#' : '')
            .V12Vba::norm($p['oficina']).'~'.V12Vba::norm($p['promotor']);
        usort($promotores, fn ($a, $b) => strcmp($orden($a), $orden($b)));

        $filas = [];
        $oficinaAnterior = null;
        $sucursalAnterior = null;
        $t = $ts = $gt = self::totalesVacios();
        $con = fn (array $fila, ?string $suc) => $fila + ['sucursal' => $suc];
        foreach ($promotores as $p) {
            $suc = $p['sucursal'] ?? null;
            $cambiaSucursal = $conSucursal && $sucursalAnterior !== null && $suc !== $sucursalAnterior;
            if ($oficinaAnterior !== null && ($cambiaSucursal || V12Vba::norm($p['oficina']) !== V12Vba::norm($oficinaAnterior))) {
                $filas[] = $con($this->fila('subtotal', $oficinaAnterior, 'TOTAL '.$oficinaAnterior, $t), $sucursalAnterior);
                $t = self::totalesVacios();
            }
            if ($cambiaSucursal) {
                $filas[] = $con($this->fila('subtotal_sucursal', null, 'TOTAL SUCURSAL '.mb_strtoupper((string) $sucursalAnterior), $ts), $sucursalAnterior);
                $ts = self::totalesVacios();
            }
            $valores = array_intersect_key($p, self::totalesVacios());
            $valores = array_map('floatval', $valores + self::totalesVacios());
            $valores['cobranza_total'] = V12Vba::suma($valores['sano_cob'], $valores['atraso_cob'], $valores['vencido_cob']);
            $filas[] = $con($this->fila('promotor', $p['oficina'], $p['promotor'], $valores), $suc);
            foreach ($valores as $k => $v) {
                $t[$k] += $v;
                $ts[$k] += $v;
                $gt[$k] += $v;
            }
            $oficinaAnterior = $p['oficina'];
            $sucursalAnterior = $suc;
        }
        if ($oficinaAnterior !== null) {
            $filas[] = $con($this->fila('subtotal', $oficinaAnterior, 'TOTAL '.$oficinaAnterior, $t), $sucursalAnterior);
            if ($conSucursal) {
                $filas[] = $con($this->fila('subtotal_sucursal', null, 'TOTAL SUCURSAL '.mb_strtoupper((string) $sucursalAnterior), $ts), $sucursalAnterior);
            }
            $filas[] = $con($this->fila('gran_total', null, 'GRAN TOTAL', $gt), null);
        }

        return $filas;
    }

    /**
     * Columnas del reporte. Porcentajes SIEMPRE sobre el Sano por cobrar; sin base
     * (Sano por cobrar ≤ 0.005) el porcentaje es `null` (celda vacía).
     */
    public function fila(string $tipo, ?string $oficina, string $etiqueta, array $v): array
    {
        $pc = (float) $v['sano_pc'];
        $total = (float) ($v['cobranza_total'] ?? V12Vba::suma($v['sano_cob'], $v['atraso_cob'], $v['vencido_cob']));
        $base = $pc > V12Vba::MARGEN;

        return [
            'tipo' => $tipo,
            'oficina' => $oficina,
            'oficina_titulo' => $oficina !== null ? mb_strtoupper($oficina) : null,
            'promotor' => $etiqueta,
            'sano_pc' => $pc,
            'sano_cob' => (float) $v['sano_cob'],
            'sano_pct' => $base ? $v['sano_cob'] / $pc : null,
            'atraso_pc' => (float) $v['atraso_pc'],
            'atraso_cob' => (float) $v['atraso_cob'],
            'atraso_pct' => $base ? $v['atraso_cob'] / $pc : null,
            'vencido_pc' => (float) $v['vencido_pc'],
            'vencido_cob' => (float) $v['vencido_cob'],
            'vencido_pct' => $base ? $v['vencido_cob'] / $pc : null,
            'cobranza_total' => $total,
            'pct_total' => $base ? $total / $pc : null, // deliberadamente SIN tope de 100 %
            'mora' => $pc - $total,
            'es_total' => $tipo !== 'promotor',
        ];
    }

    /** @return array<string,float> */
    public static function totalesVacios(): array
    {
        return ['sano_pc' => 0.0, 'sano_cob' => 0.0, 'atraso_pc' => 0.0, 'atraso_cob' => 0.0, 'vencido_pc' => 0.0, 'vencido_cob' => 0.0, 'cobranza_total' => 0.0];
    }

    // --- Helpers públicos (lector de Excel / servicio de la calculadora) ---

    public function normalizar(mixed $valor): string
    {
        return V12Vba::norm($valor);
    }

    public function leerFecha(mixed $valor): ?int
    {
        return V12Vba::fecha($valor);
    }

    public function ymd(int $dia): string
    {
        return V12Vba::ymd($dia);
    }

    public function numero(mixed $valor): float
    {
        return V12Vba::num($valor);
    }

    /**
     * Periodo según la modalidad:
     *  - SEMANAL: foto base = domingo; periodo lunes→domingo de corte.
     *  - DIARIO: foto base = cierre del día anterior; periodo = el día de corte.
     *  - ACUMULADO: foto base = domingo; periodo lunes→día de corte (misma regla que
     *    el semanal, con la ventana recortada a la fecha de corte).
     */
    private function prepararCorrida(array $parametros, array $fotos, array $ingresos, array $historial): V12Corrida
    {
        $domingo = V12Vba::fecha($parametros['domingo_base'] ?? null);
        if ($domingo === null) {
            throw new V12Exception('EC-V12-211', 'No se pudo leer la fecha del domingo base.');
        }
        if (V12Vba::diaSemana($domingo) !== 7) {
            throw new V12Exception('EC-V12-212', 'La cartera base no corresponde a domingo.', ['domingo_base' => V12Vba::ymd($domingo)]);
        }
        $modo = V12Vba::norm($parametros['modo'] ?? '');
        if (! in_array($modo, self::MODOS, true)) {
            throw new V12Exception('EC-V12-202', 'Modalidad inválida: DIARIO, ACUMULADO o SEMANAL.');
        }
        $corte = V12Vba::fecha($parametros['fecha_corte'] ?? null);
        if ($modo === 'SEMANAL') {
            $corte ??= $domingo + 7;
            if ($corte !== $domingo + 7) {
                throw new V12Exception('EC-V12-206', 'El corte SEMANAL debe ser el domingo siguiente al domingo base.');
            }
        }
        if ($corte === null || $corte < $domingo + 1 || $corte > $domingo + 7) {
            throw new V12Exception('EC-V12-213', sprintf('La fecha de corte debe estar entre %s y %s.', V12Vba::dmy($domingo + 1), V12Vba::dmy($domingo + 7)));
        }

        $c = new V12Corrida;
        $c->modo = $modo;
        $c->domingo = $domingo;
        $c->corte = $corte;
        $c->fotoInicio = $modo === 'DIARIO' ? $corte - $domingo - 1 : 0;
        $c->fBase = $domingo + $c->fotoInicio;
        [$c->repIni, $c->repFin] = $modo === 'DIARIO' ? [$corte, $corte] : [$domingo + 1, $modo === 'SEMANAL' ? $domingo + 7 : $corte];

        for ($i = $c->fotoInicio; $i <= $corte - $domingo; $i++) {
            $nombre = self::NOMBRES_FOTO[$i];
            if (! isset($fotos[$i]) || ! in_array('contrato', $fotos[$i]['columnas'] ?? [], true)) {
                throw new V12Exception('EC-V12-204', "Falta la fotografía de cartera {$nombre}.", ['foto' => $i, 'fecha' => V12Vba::ymd($domingo + $i)]);
            }
            $faltan = array_diff(self::COLUMNAS_FOTO_OBLIGATORIAS, $fotos[$i]['columnas']);
            if ($faltan) {
                throw new V12Exception('EC-V12-320', "Faltan columnas indispensables en {$nombre}: ".implode(', ', $faltan).'.', ['foto' => $i]);
            }
            $filas = $fotos[$i]['filas'];
            $c->fotos[$i] = ['columnas' => array_values($fotos[$i]['columnas']), 'filas' => is_array($filas) ? array_values($filas) : iterator_to_array($filas, false)];
        }
        foreach (['contrato', 'fecha_transaccion', 'fecha_cuota'] as $col) {
            if (! in_array($col, $ingresos['columnas'] ?? [], true)) {
                throw new V12Exception('EC-V12-203', 'INGRESOS: conserva los encabezados originales (Contrato, Fecha transacción, Fecha cuota).');
            }
        }
        $faltan = array_diff(self::COLUMNAS_INGRESOS_OBLIGATORIAS, $historial['columnas'] ?? []);
        if ($faltan) {
            throw new V12Exception('EC-V12-940', 'Encabezados faltantes en INGRESOS: '.implode(', ', $faltan).'.', ['columnas' => array_values($faltan)]);
        }
        $filas = $historial['filas'];
        $c->historial = ['columnas' => array_values($historial['columnas']), 'filas' => is_array($filas) ? array_values($filas) : iterator_to_array($filas, false)];

        return $c;
    }

    /** MR_LeerFoto — gestor "OFICINA~PROMOTOR" de cada contrato en la fecha de la foto. */
    private function leerFoto(V12Corrida $c, int $i): void
    {
        $f = $c->domingo + $i;
        foreach ($c->fotos[$i]['filas'] as $r) {
            $contrato = V12Vba::norm($r['contrato'] ?? null);
            if ($contrato === '') {
                continue;
            }
            $oficina = V12Vba::norm($r['oficina'] ?? null);
            $promotor = V12Vba::norm($r['nombre_promotor'] ?? null);
            if ($oficina === '' || $promotor === '') {
                $c->incidencia('SIN-GESTOR', $contrato, 'Contrato sin ruta/promotor en la fotografía '.V12Vba::dmy($f).': sus cuotas y cobros de ese día no tienen responsable.', ['fecha' => V12Vba::ymd($f), 'referencia' => V12Vba::compacto($f)]);

                continue;
            }
            $grupo = $oficina.'~'.$promotor;
            $c->responsables[$contrato][$f] = $grupo;
            $c->grupos[$grupo] = true;
        }
    }

    /** MR_CargarAtrasosFoto — atraso ordinario del corte inicial; contrato ya vencido = todo su saldo. */
    private function cargarAtrasos(V12Corrida $c): void
    {
        $i = $c->fotoInicio;
        $conSaldo = $c->tieneFoto($i, 'saldo_capital', 'saldo_interes', 'saldo_impuesto');
        $conAtr = $c->tieneFoto($i, 'capital_atrasado', 'interes_atrasado', 'impuesto_atrasado');
        $conMat = $c->tieneFoto($i, 'fecha_vencimiento');
        foreach ($c->fotos[$i]['filas'] as $r) {
            $ct = V12Vba::norm($r['contrato'] ?? null);
            if ($ct === '') {
                continue;
            }
            $mat = $conMat ? (V12Vba::fecha($r['fecha_vencimiento'] ?? null) ?? 0) : 0;
            if ($mat > 0 && $mat < $c->fBase && $conSaldo) {
                $importe = V12Vba::suma(V12Vba::num($r['saldo_capital']), V12Vba::num($r['saldo_interes']), V12Vba::num($r['saldo_impuesto']));
            } elseif ($conAtr) {
                $importe = V12Vba::suma(V12Vba::num($r['capital_atrasado']), V12Vba::num($r['interes_atrasado']), V12Vba::num($r['impuesto_atrasado']));
            } else {
                $importe = 0.0;
            }
            if ($importe > V12Vba::MARGEN) {
                $c->atrasoBase[$ct] = $importe;
            }
        }
    }

    /**
     * MR_Dibujar — ruta (oficina) → promotor, subtotales y GRAN TOTAL. No se muestran
     * gestores sin meta ni cobranza; los que tienen recuperación con meta cero SÍ
     * participan (Regla 32), con porcentaje vacío.
     *
     * @return array{0: list<array<string,mixed>>, 1: bool, 2: array<string,mixed>}
     */
    private function filasReporte(V12Corrida $c, V12Buckets $b): array
    {
        $claves = array_keys($c->grupos);
        usort($claves, fn ($x, $y) => strcmp((string) $x, (string) $y));
        $promotores = [];
        foreach ($claves as $g) {
            $g = (string) $g;
            $v = [
                'sano_pc' => $b->pcS[$g] ?? 0.0, 'sano_cob' => $b->cobS[$g] ?? 0.0,
                'atraso_pc' => $b->pcA[$g] ?? 0.0, 'atraso_cob' => $b->cobA[$g] ?? 0.0,
                'vencido_pc' => $b->pcV[$g] ?? 0.0, 'vencido_cob' => $b->cobV[$g] ?? 0.0,
            ];
            $total = V12Vba::suma($v['sano_cob'], $v['atraso_cob'], $v['vencido_cob']);
            if ($v['sano_pc'] <= V12Vba::MARGEN && $total <= V12Vba::MARGEN) {
                continue;
            }
            [$oficina, $promotor] = explode('~', $g, 2);
            $promotores[] = ['oficina' => $oficina, 'promotor' => $promotor] + $v;
        }
        $filas = $this->construirFilas($promotores);
        if (! $filas) {
            $gt = $this->fila('gran_total', null, 'GRAN TOTAL', self::totalesVacios());

            return [[], true, $gt];
        }

        return [$filas, false, end($filas)];
    }
}
