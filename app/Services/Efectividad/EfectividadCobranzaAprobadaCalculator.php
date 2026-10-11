<?php

namespace App\Services\Efectividad;

use DateTimeImmutable;

/**
 * Fase 2 — Efectividad de Cobranza APROBADA. Puerto fiel a PHP del motor VBA
 * real de `CALCULADORA DE EFECTIVIDAD DE COBRANZA APROBADO.xlsm`
 * (`Módulo1.bas`, función `Generar_Efectividad_Cobranza` y sus auxiliares),
 * extraído con `oletools.olevba` (lectura de macro, nunca ejecutada) y
 * verificado línea por línea contra el dump real — no contra los antecedentes
 * históricos previamente documentados, que resultaron ser una aproximación
 * incompleta (ver docs/efectividad-cobranza-aprobada.md).
 *
 * Esta clase es deliberadamente independiente de Eloquent/DB: recibe arrays
 * asociativos con los nombres de campo documentados (coinciden con los
 * encabezados que el VBA busca por nombre, `ColumnaEncabezado`), para poder
 * probarse con datos sintéticos y conectarse a la fuente real de datos más
 * adelante sin tocar la lógica ya verificada.
 *
 * IMPORTANTE — distinto del VBA original en un solo punto, deliberado y sin
 * efecto en el resultado: el VBA auto-detecta fechaIni/fechaFin a partir del
 * rango de fechas en INGRESOS (conveniencia para uso manual en Excel). Aquí
 * fechaIni/fechaFin se reciben explícitas, porque Reportería siempre conoce
 * el Period exacto que se está evaluando — nunca se adivina. La clasificación
 * Sano/Atraso/Vencido y las fórmulas de negocio son IDÉNTICAS al VBA.
 */
class EfectividadCobranzaAprobadaCalculator
{
    private const TOL = 0.01;

    /**
     * @param  iterable<array<string,mixed>>  $cartera  filas CARTERA (saldos por cliente)
     * @param  iterable<array<string,mixed>>  $ingresos  filas INGRESOS/_HIST_INGRESOS (histórico completo, no solo el periodo actual)
     */
    public function calculate(iterable $cartera, iterable $ingresos, DateTimeImmutable $fechaIni, DateTimeImmutable $fechaFin): array
    {
        $cartera  = is_array($cartera) ? $cartera : iterator_to_array($cartera);
        $ingresos = is_array($ingresos) ? $ingresos : iterator_to_array($ingresos);

        $rutaPromotor = $this->crearMapaResponsableRuta($ingresos, $fechaIni, $fechaFin);

        $grupos        = []; // grupoKey => true (grupo conocido/registrado)
        $oficinaDeGrupo = [];
        $promotorDeGrupo = [];
        $nombreGrupo    = [];
        $contratoGrupo  = []; // contrato normalizado => grupoKey (primer grupo que lo tocó)
        $contratoVenc   = []; // contrato normalizado => DateTimeImmutable (vencimiento, desde CARTERA, incluso de cerrados)

        $sanoPC = $atrasoPC = $vencidoPC = [];
        $cobSano = $cobAtraso = $cobVencido = [];
        $programado = []; // contrato|fecha => monto (cuotas ya proyectadas por CargarCartera)

        $this->cargarCartera(
            $cartera, $fechaIni, $fechaFin, $rutaPromotor,
            $grupos, $oficinaDeGrupo, $promotorDeGrupo, $nombreGrupo,
            $contratoGrupo, $contratoVenc,
            $sanoPC, $atrasoPC, $vencidoPC, $programado
        );

        $this->procesarIngresos(
            $ingresos, $fechaIni, $fechaFin, $rutaPromotor,
            $grupos, $oficinaDeGrupo, $promotorDeGrupo, $nombreGrupo,
            $contratoGrupo, $contratoVenc,
            $sanoPC, $programado, $cobSano, $cobAtraso, $cobVencido
        );

        return $this->generarReporte($grupos, $oficinaDeGrupo, $promotorDeGrupo, $sanoPC, $atrasoPC, $vencidoPC, $cobSano, $cobAtraso, $cobVencido);
    }

    // ==================================================================
    // 1. RESOLUCIÓN DE RUTA (CrearMapaResponsableRuta)
    // ==================================================================

    /** @return array<string,string> oficinaNormalizada => nombre de promotor responsable actual */
    private function crearMapaResponsableRuta(array $ingresos, DateTimeImmutable $fechaIni, DateTimeImmutable $fechaFin): array
    {
        $conteo = []; // "oficina|promotor" => count
        $nombreReal = [];
        $rutaDeClave = [];

        foreach ($ingresos as $row) {
            $fTrans = $this->leerFecha($row['fecha_transaccion'] ?? null);
            if ($fTrans === null || $fTrans < $fechaIni || $fTrans > $fechaFin) {
                continue; // CrearMapaResponsableRuta solo mira movimientos del periodo ACTUAL
            }

            if (!$this->pasaWhitelist($row)) {
                continue;
            }

            $oficina = trim((string) ($row['oficina'] ?? ''));
            $promotor = trim((string) ($row['promotor'] ?? ''));
            if ($this->normalizar($oficina) === '' || $this->normalizar($promotor) === '') {
                continue;
            }

            $rutaN = $this->normalizar($oficina);
            $promoN = $this->normalizar($promotor);
            $clave = $rutaN . '|' . $promoN;

            $conteo[$clave] = ($conteo[$clave] ?? 0) + 1;
            $nombreReal[$clave] = $promotor;
            $rutaDeClave[$clave] = $rutaN;
        }

        $max = [];
        $resultado = [];
        foreach ($conteo as $clave => $n) {
            $rutaN = $rutaDeClave[$clave];
            if (!array_key_exists($rutaN, $max) || $n > $max[$rutaN]) {
                $max[$rutaN] = $n;
                $resultado[$rutaN] = $nombreReal[$clave];
            }
        }

        return $resultado;
    }

    private function obtenerPromotorRuta(string $oficina, string $promotorOriginal, array $rutaPromotor): string
    {
        $rutaN = $this->normalizar($oficina);

        return $rutaPromotor[$rutaN] ?? $promotorOriginal;
    }

    // ==================================================================
    // 2. CARTERA → por-cobrar (CargarCartera)
    // ==================================================================

    private function cargarCartera(
        array $cartera, DateTimeImmutable $fechaIni, DateTimeImmutable $fechaFin, array $rutaPromotor,
        array &$grupos, array &$oficinaDeGrupo, array &$promotorDeGrupo, array &$nombreGrupo,
        array &$contratoGrupo, array &$contratoVenc,
        array &$sanoPC, array &$atrasoPC, array &$vencidoPC, array &$programado
    ): void {
        foreach ($cartera as $row) {
            $contrato = trim((string) ($row['contrato'] ?? ''));
            if ($contrato === '') {
                continue;
            }
            $claveContrato = $this->normalizar($contrato);

            $fVenc = $this->leerFecha($row['fecha_vencimiento'] ?? null);
            $tieneVenc = $fVenc !== null;
            if ($tieneVenc && !array_key_exists($claveContrato, $contratoVenc)) {
                // Se guarda el vencimiento INCLUSO de contratos cerrados/inactivos que se excluyen
                // abajo — ProcesarIngresos lo necesita como fallback para clasificar pagos históricos.
                $contratoVenc[$claveContrato] = $fVenc;
            }

            $estado = $this->normalizar($row['estatus'] ?? '');
            $subestado = $this->normalizar($row['substatus'] ?? '');

            if (str_contains($estado, 'CERRADO') || str_contains($estado, 'INACTIVO') || str_contains($subestado, 'INACTIVO')) {
                continue; // cerrados/inactivos no generan por-cobrar ni fila de vendedor
            }

            $oficina = trim((string) ($row['oficina'] ?? ''));
            $promoOriginal = trim((string) ($row['promotor'] ?? ''));
            $promo = $this->obtenerPromotorRuta($oficina, $promoOriginal, $rutaPromotor);

            $grupo = $this->registrarGrupo($oficina, $promo, $grupos, $oficinaDeGrupo, $promotorDeGrupo, $nombreGrupo);
            if (!array_key_exists($claveContrato, $contratoGrupo)) {
                $contratoGrupo[$claveContrato] = $grupo;
            }

            // --- VIGENTE CON ATRASO ---
            if (str_contains($subestado, 'VIGENTE')) {
                $totalAtrasado = array_key_exists('total_atrasado', $row) ? $this->numero($row['total_atrasado']) : 0.0;

                if ($totalAtrasado > self::TOL) {
                    $cuentaAtraso = true;
                    if (array_key_exists('pagos_atrasados', $row)) {
                        $cuentaAtraso = $this->numero($row['pagos_atrasados']) > 0;
                    }
                    if ($cuentaAtraso) {
                        $this->sumarDic($atrasoPC, $grupo, $totalAtrasado);
                    }
                }
            }

            // --- VENCIDO POR COBRAR ---
            if (str_contains($subestado, 'VENCIDO')) {
                $tienePendienteCol = array_key_exists('total_pendiente', $row);
                $totalPendiente = $tienePendienteCol ? $this->numero($row['total_pendiente']) : 0.0;

                $saltarVencido = $tienePendienteCol && $totalPendiente <= self::TOL;

                if (!$saltarVencido) {
                    $vencidoPCFila = 0.0;
                    $componentesDisponibles = false;
                    foreach (['saldo_capital', 'saldo_interes', 'saldo_impuesto', 'saldo_cargo_calendario', 'saldo_impuesto_cargo_calendario'] as $campo) {
                        if (array_key_exists($campo, $row)) {
                            $componentesDisponibles = true;
                            $vencidoPCFila += $this->positivo($row[$campo]);
                        }
                    }

                    if (!$componentesDisponibles && $totalPendiente > self::TOL) {
                        $vencidoPCFila = $totalPendiente;
                    }

                    if ($vencidoPCFila > self::TOL) {
                        $this->sumarDic($vencidoPC, $grupo, $vencidoPCFila);
                    }
                }
            }

            // --- SANO (proyección de cuotas futuras) — nunca si el substatus es VENCIDO ---
            if (str_contains($subestado, 'VENCIDO')) {
                continue;
            }

            $cuota = $this->numero($row['cuota'] ?? null);
            if ($cuota <= 0) {
                continue;
            }

            $periodicidad = (string) ($row['periodicidad'] ?? 'SEMANAL');
            $pagos = array_key_exists('pagos', $row) ? (int) $this->numero($row['pagos']) : 500;
            if ($pagos <= 0) {
                $pagos = 500;
            }

            $fPrimer = $this->leerFecha($row['fecha_primer_pago'] ?? null);
            $fSig = $this->leerFecha($row['fecha_siguiente_pago'] ?? null);

            $fCuota = $fPrimer ?? $fSig;
            if ($fCuota === null) {
                continue; // sin fecha de referencia, no se puede proyectar Sano
            }

            for ($n = 1; $n <= $pagos; $n++) {
                if ($tieneVenc && $fCuota > $fVenc) {
                    break;
                }
                if ($fCuota > $fechaFin) {
                    break;
                }

                if ($fCuota >= $fechaIni && $fCuota <= $fechaFin) {
                    $this->sumarDic($sanoPC, $grupo, $cuota);

                    $claveProgramada = $claveContrato . '|' . $fCuota->format('Ymd');
                    $programado[$claveProgramada] = ($programado[$claveProgramada] ?? 0.0) + $cuota;
                }

                $fNueva = $this->siguienteFechaCuota($fCuota, $periodicidad);
                if ($fNueva <= $fCuota) {
                    break;
                }
                $fCuota = $fNueva;
            }
        }
    }

    // ==================================================================
    // 3. INGRESOS/_HIST_INGRESOS → cobrado (ProcesarIngresos)
    // ==================================================================

    private function procesarIngresos(
        array $ingresos, DateTimeImmutable $fechaIni, DateTimeImmutable $fechaFin, array $rutaPromotor,
        array &$grupos, array &$oficinaDeGrupo, array &$promotorDeGrupo, array &$nombreGrupo,
        array &$contratoGrupo, array $contratoVenc,
        array &$sanoPC, array $programado, array &$cobSano, array &$cobAtraso, array &$cobVencido
    ): void {
        $fallbackMonto = [];
        $fallbackGrupo = [];

        foreach ($ingresos as $row) {
            $contrato = trim((string) ($row['contrato'] ?? ''));
            if ($contrato === '') {
                continue;
            }

            if (!$this->pasaWhitelist($row)) {
                continue;
            }

            $fTrans = $this->leerFecha($row['fecha_transaccion'] ?? null);
            if ($fTrans === null) {
                continue;
            }
            $fCuota = $this->leerFecha($row['fecha_cuota'] ?? null);
            if ($fCuota === null) {
                continue;
            }

            $importe = $this->numero($row['capital'] ?? null) + $this->numero($row['interes'] ?? null) + $this->numero($row['impuesto'] ?? null);
            if (array_key_exists('cargos_calendario', $row)) {
                $importe += $this->numero($row['cargos_calendario']);
            }
            if ($importe <= 0) {
                continue;
            }

            $oficina = trim((string) ($row['oficina'] ?? ''));
            $promoOriginal = trim((string) ($row['promotor'] ?? ''));

            $esMovimientoActual = $fTrans >= $fechaIni && $fTrans <= $fechaFin;

            $grupo = $this->grupoDesdeIngreso($contrato, $oficina, $promoOriginal, $esMovimientoActual, $rutaPromotor, $grupos, $oficinaDeGrupo, $promotorDeGrupo, $contratoGrupo, $nombreGrupo);
            if ($grupo === null) {
                continue;
            }

            $claveContrato = $this->normalizar($contrato);

            // --- reconstruir Sano si el contrato ya no está en CARTERA ---
            if ($fCuota >= $fechaIni && $fCuota <= $fechaFin) {
                $claveProgramada = $claveContrato . '|' . $fCuota->format('Ymd');

                if (!array_key_exists($claveProgramada, $programado)) {
                    $acreditado = $this->normalizar($row['acreditado'] ?? '');
                    $numCuota = $this->normalizar($row['numero_cuota'] ?? '');
                    $claveFallback = $claveContrato . '|' . $acreditado . '|' . $numCuota . '|' . $fCuota->format('Ymd');

                    $fallbackMonto[$claveFallback] = ($fallbackMonto[$claveFallback] ?? 0.0) + $importe;
                    $fallbackGrupo[$claveFallback] = $grupo;
                }
            }

            // --- fecha de reconocimiento ---
            $fRecon = $fTrans > $fCuota ? $fTrans : $fCuota;
            if ($fRecon < $fechaIni || $fRecon > $fechaFin) {
                continue;
            }

            // --- Sano / Atraso / Vencido ---
            if ($fTrans <= $fCuota) {
                $this->sumarDic($cobSano, $grupo, $importe);
                continue;
            }

            $fVenc = $this->leerFecha($row['fecha_vencimiento'] ?? null);
            $tieneVenc = $fVenc !== null;
            if (!$tieneVenc && array_key_exists($claveContrato, $contratoVenc)) {
                $fVenc = $contratoVenc[$claveContrato];
                $tieneVenc = true;
            }

            if ($tieneVenc && $fTrans > $fVenc) {
                $this->sumarDic($cobVencido, $grupo, $importe);
            } else {
                $this->sumarDic($cobAtraso, $grupo, $importe);
            }
        }

        foreach ($fallbackMonto as $clave => $monto) {
            $grupo = $fallbackGrupo[$clave];
            if (isset($grupos[$grupo])) {
                $sanoPC[$grupo] = ($sanoPC[$grupo] ?? 0.0) + $monto;
            }
        }
    }

    private function grupoDesdeIngreso(string $contrato, string $oficina, string $promoOriginal, bool $esMovimientoActual, array $rutaPromotor, array &$grupos, array &$oficinaDeGrupo, array &$promotorDeGrupo, array &$contratoGrupo, array &$nombreGrupo): ?string
    {
        $claveContrato = $this->normalizar($contrato);

        if (array_key_exists($claveContrato, $contratoGrupo)) {
            return $contratoGrupo[$claveContrato];
        }

        $promo = $this->obtenerPromotorRuta($oficina, $promoOriginal, $rutaPromotor);
        $grupo = $this->registrarGrupo($oficina, $promo, $grupos, $oficinaDeGrupo, $promotorDeGrupo, $nombreGrupo);
        $contratoGrupo[$claveContrato] = $grupo;

        return $grupo;
    }

    // ==================================================================
    // 4. REPORTE (GenerarReporte) — agrupación, totales, porcentajes
    // ==================================================================

    private function generarReporte(array $grupos, array $oficinaDeGrupo, array $promotorDeGrupo, array $sanoPC, array $atrasoPC, array $vencidoPC, array $cobSano, array $cobAtraso, array $cobVencido): array
    {
        $claves = array_keys($grupos);
        usort($claves, fn ($a, $b) => strcmp($this->claveOrden($a, $oficinaDeGrupo, $promotorDeGrupo), $this->claveOrden($b, $oficinaDeGrupo, $promotorDeGrupo)));

        $filas = [];
        $oficinaActual = null;
        $totalesOficina = $this->totalesVacios();
        $totalesGenerales = $this->totalesVacios();
        $oficinasEmitidas = [];

        foreach ($claves as $grupo) {
            if (!$this->grupoTieneDatos($grupo, $sanoPC, $atrasoPC, $vencidoPC, $cobSano, $cobAtraso, $cobVencido)) {
                continue;
            }

            $oficina = $oficinaDeGrupo[$grupo];
            $promotor = $promotorDeGrupo[$grupo];

            if ($oficinaActual !== null && $this->normalizar($oficina) !== $this->normalizar($oficinaActual)) {
                $filas[] = $this->filaTotal('TOTAL OFICINA — ' . $oficinaActual, $totalesOficina);
                $totalesOficina = $this->totalesVacios();
            }
            $oficinaActual = $oficina;
            $oficinasEmitidas[$this->normalizar($oficina)] = $oficina;

            $fila = $this->calcularFila($promotor, $sanoPC[$grupo] ?? 0.0, $atrasoPC[$grupo] ?? 0.0, $vencidoPC[$grupo] ?? 0.0, $cobSano[$grupo] ?? 0.0, $cobAtraso[$grupo] ?? 0.0, $cobVencido[$grupo] ?? 0.0);
            $fila['oficina'] = $oficina;
            $filas[] = $fila;

            $this->acumularTotales($totalesOficina, $fila);
            $this->acumularTotales($totalesGenerales, $fila);
        }

        if ($oficinaActual !== null) {
            $filas[] = $this->filaTotal('TOTAL OFICINA — ' . $oficinaActual, $totalesOficina);
        }

        $filas[] = $this->filaTotal('GRAN TOTAL', $totalesGenerales);

        return $filas;
    }

    private function claveOrden(string $grupo, array $oficinaDeGrupo, array $promotorDeGrupo): string
    {
        return $this->normalizar($oficinaDeGrupo[$grupo]) . '|' . $this->normalizar($promotorDeGrupo[$grupo]);
    }

    private function totalesVacios(): array
    {
        return ['sano_pc' => 0.0, 'sano_cob' => 0.0, 'atraso_pc' => 0.0, 'atraso_cob' => 0.0, 'vencido_pc' => 0.0, 'vencido_cob' => 0.0];
    }

    private function acumularTotales(array &$totales, array $fila): void
    {
        $totales['sano_pc'] += $fila['sano_pc'];
        $totales['sano_cob'] += $fila['sano_cob'];
        $totales['atraso_pc'] += $fila['atraso_pc'];
        $totales['atraso_cob'] += $fila['atraso_cob'];
        $totales['vencido_pc'] += $fila['vencido_pc'];
        $totales['vencido_cob'] += $fila['vencido_cob'];
    }

    private function filaTotal(string $etiqueta, array $t): array
    {
        $fila = $this->calcularFila($etiqueta, $t['sano_pc'], $t['atraso_pc'], $t['vencido_pc'], $t['sano_cob'], $t['atraso_cob'], $t['vencido_cob']);
        $fila['es_total'] = true;

        return $fila;
    }

    /** Columnas A-M exactas de la hoja EFECTIVIDAD real (ver docs/efectividad-cobranza-aprobada.md). */
    private function calcularFila(string $promotor, float $sanoPC, float $atrasoPC, float $vencidoPC, float $sanoCob, float $atrasoCob, float $vencidoCob): array
    {
        $totalCob = $sanoCob + $atrasoCob + $vencidoCob;
        $mora = $sanoPC - $totalCob;

        if ($sanoPC > 0) {
            $pctSano = $sanoCob / $sanoPC;
            $pctAtraso = $atrasoCob / $sanoPC;
            $pctVencido = $vencidoCob / $sanoPC;
            $pctTotal = $totalCob / $sanoPC;
        } else {
            $pctSano = $pctAtraso = $pctVencido = $pctTotal = 0.0;
        }

        return [
            'promotor'      => $promotor,
            'sano_pc'       => $sanoPC,
            'sano_cob'      => $sanoCob,
            'sano_pct'      => $pctSano,
            'atraso_pc'     => $atrasoPC,
            'atraso_cob'    => $atrasoCob,
            'atraso_pct'    => $pctAtraso,
            'vencido_pc'    => $vencidoPC,
            'vencido_cob'   => $vencidoCob,
            'vencido_pct'   => $pctVencido,
            'cobranza_total' => $totalCob,
            'pct_total'     => $pctTotal,
            'mora'          => $mora,
            'es_total'      => false,
        ];
    }

    private function grupoTieneDatos(string $grupo, array $sanoPC, array $atrasoPC, array $vencidoPC, array $cobSano, array $cobAtraso, array $cobVencido): bool
    {
        foreach ([$sanoPC, $atrasoPC, $vencidoPC, $cobSano, $cobAtraso, $cobVencido] as $dic) {
            if (abs($dic[$grupo] ?? 0.0) > self::TOL) {
                return true;
            }
        }

        return false;
    }

    // ==================================================================
    // Helpers compartidos
    // ==================================================================

    private function pasaWhitelist(array $row): bool
    {
        $operacion = $this->normalizar($row['operacion'] ?? '');
        $transaccion = $this->normalizar($row['transaccion'] ?? '');

        return ($operacion === 'PAGO A CONTRATO' && $transaccion === 'PAGO')
            || ($operacion === 'REFINANCIAMIENTO' && $transaccion === 'DESCUENTO');
    }

    private function registrarGrupo(string $oficina, string $promotor, array &$grupos, array &$oficinaDeGrupo, array &$promotorDeGrupo, array &$nombreGrupo): string
    {
        $clave = $this->normalizar($oficina) . '|NOM|' . $this->normalizar($promotor);

        if (!isset($grupos[$clave])) {
            $grupos[$clave] = true;
            $oficinaDeGrupo[$clave] = $oficina;
            $promotorDeGrupo[$clave] = $promotor;
            $nombreGrupo[$clave] = $promotor;
        }

        return $clave;
    }

    private function sumarDic(array &$dic, string $clave, float $monto): void
    {
        $dic[$clave] = ($dic[$clave] ?? 0.0) + $monto;
    }

    public function normalizar(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }
        $s = mb_strtoupper(trim((string) $valor));
        $s = str_replace(["\xC2\xA0", "\r", "\n"], ' ', $s);
        $s = str_replace(['Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ'], ['A', 'E', 'I', 'O', 'U', 'U', 'N'], $s);
        while (str_contains($s, '  ')) {
            $s = str_replace('  ', ' ', $s);
        }

        return $s;
    }

    public function numero(mixed $valor): float
    {
        if ($valor === null || $valor === '') {
            return 0.0;
        }
        if (is_int($valor) || is_float($valor)) {
            return (float) $valor;
        }

        $s = trim((string) $valor);
        if ($s === '') {
            return 0.0;
        }
        if (is_numeric($s)) {
            return (float) $s;
        }

        $s = str_replace(['$', ',', ' '], '', $s);
        if (str_starts_with($s, '(') && str_ends_with($s, ')')) {
            $s = '-' . substr($s, 1, -1);
        }

        return is_numeric($s) ? (float) $s : 0.0;
    }

    public function positivo(mixed $valor): float
    {
        $n = $this->numero($valor);

        return $n > 0 ? $n : 0.0;
    }

    private function leerFecha(mixed $valor): ?DateTimeImmutable
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if ($valor instanceof \DateTimeInterface) {
            return (new DateTimeImmutable($valor->format('Y-m-d')));
        }
        if (is_numeric($valor)) {
            // Serial de fecha Excel.
            $days = (float) $valor;
            if ($days <= 1) {
                return null;
            }

            return (new DateTimeImmutable('1899-12-30'))->modify('+' . (int) $days . ' days');
        }

        try {
            return new DateTimeImmutable((string) $valor);
        } catch (\Exception) {
            return null;
        }
    }

    private function siguienteFechaCuota(DateTimeImmutable $fecha, string $periodicidad): DateTimeImmutable
    {
        $p = $this->normalizar($periodicidad);

        if (str_contains($p, 'DIAR')) {
            $f = $fecha->modify('+1 day');
            while ((int) $f->format('N') > 5) {
                $f = $f->modify('+1 day');
            }

            return $f;
        }

        if (str_contains($p, 'QUINC') || str_contains($p, 'CATORC') || str_contains($p, '14')) {
            return $fecha->modify('+14 days');
        }

        if (str_contains($p, 'MENS')) {
            return $fecha->modify('+1 month');
        }

        return $fecha->modify('+7 days');
    }
}
