<?php

namespace App\Services\Efectividad\V4;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Motor de Efectividad de Cobranza — versión V4 (MR LANA, octubre 2026).
 *
 * Puerto fiel de `Módulo1.bas` de `MR_LANA_EFECTIVIDAD.xlsm`
 * (`Generar_Efectividad_Cobranza` y sus auxiliares), extraído de forma
 * estática (nunca ejecutado). Código VBA de referencia en
 * `docs/efectividad/nueva-version/vba/Modulo1.bas`; cada método indica el
 * procedimiento VBA que reproduce.
 *
 * Diferencias de contrato con la macro (no de reglas):
 *  - La macro lee hojas por encabezado (`ColumnaEncabezado`). Aquí cada fuente
 *    llega como filas con llaves canónicas + la lista de columnas que el archivo
 *    real traía (`columnas`). Una columna ausente se comporta igual que en la
 *    macro (`cX = 0`), distinto de una columna presente con valor vacío.
 *  - `MR_AutoconfigurarCorte` deduce modo/fechas desde INGRESOS e interroga al
 *    usuario con `MsgBox`. Aquí modo, domingo base y corte llegan explícitos
 *    (los resuelve el adaptador a partir del Period semanal); las confirmaciones
 *    se convierten en diagnósticos del adaptador.
 *  - `_HIST_INGRESOS` es la tabla `fact_recoveries` completa (el adaptador
 *    reproduce el reemplazo por fechas de `ActualizarHistorialIngresos`).
 *
 * La clase no depende de Eloquent ni de la BD.
 */
class EfectividadV4Calculator
{
    public const ENGINE_VERSION = 'mrlana-v4-2026-10';

    public const LEYENDA = 'Anticipos a fecha cuota | Reno solo su semana | Vencido/atraso segun foto diaria | Sin moratorios';

    /** Nombres de hoja de la macro, en orden de fotografía (índice = días desde el domingo base). */
    public const NOMBRES_FOTO = [
        'CARTERA DOMINGO', 'CARTERA LUNES', 'CARTERA MARTES', 'CARTERA MIERCOLES',
        'CARTERA JUEVES', 'CARTERA VIERNES', 'CARTERA SABADO', 'CARTERA DOMINGO CORTE',
    ];

    /** Columnas obligatorias de cada fotografía (`ProcesarFoto`, error 920). */
    public const COLUMNAS_FOTO_OBLIGATORIAS = [
        'oficina', 'nombre_promotor', 'fecha_primer_pago', 'fecha_vencimiento',
        'cuota_total', 'periodicidad', 'pagos', 'substatus',
    ];

    /** Columnas obligatorias de INGRESOS/_HIST_INGRESOS (`ProcesarCobros`, error 940). */
    public const COLUMNAS_INGRESOS_OBLIGATORIAS = [
        'oficina', 'contrato', 'operacion', 'transaccion', 'fecha_transaccion',
        'fecha_cuota', 'capital', 'interes', 'impuesto',
    ];

    private const TOL = 0.01;

    // Estado de una corrida (equivalente a los Scripting.Dictionary de la macro).
    private array $rutaPromo = [];

    private array $grupos = [];

    private array $oficinaDe = [];

    private array $promotorDe = [];

    private array $nombreGrupo = [];

    private array $contratoGrupo = [];

    private array $contratoVenc = [];

    private array $firmaContrato = [];

    private array $cuotaMonto = [];

    private array $cuotaGrupo = [];

    private array $estadoDia = [];

    private array $atrasosDia = [];

    private array $observados = [];

    private array $excluidos = [];

    private array $stockAtraso = [];

    private array $stockVencido = [];

    private array $incidencias = [];

    private array $cobroUnico = [];

    private array $sanoPC = [];

    private array $atrasoPC = [];

    private array $vencidoPC = [];

    private array $cobSano = [];

    private array $cobAtraso = [];

    private array $cobVencido = [];

    private array $estadisticas = [];

    private array $movimientos = [];

    private bool $conTraza = false;

    /**
     * @param  array{modo:string, domingo_base:mixed, fecha_corte:mixed}  $parametros
     * @param  array<int, array{columnas: list<string>, filas: iterable<array<string,mixed>>, nombre?: string}>  $fotos
     *                                                                                                                   índice = días desde el domingo base (0 = domingo base … 7 = domingo corte)
     * @param  array{columnas: list<string>, filas: iterable<array<string,mixed>>}  $ingresos  INGRESOS del corte (solo para el mapa de ruta)
     * @param  array{columnas: list<string>, filas: iterable<array<string,mixed>>}  $historial  _HIST_INGRESOS (histórico + corte, sin duplicar fechas)
     * @return array{engine_version:string, parametros:array<string,string>, filas:list<array<string,mixed>>, incidencias:list<array<string,mixed>>, estadisticas:array<string,int>, sin_movimientos:bool}
     */
    public function calculate(array $parametros, array $fotos, array $ingresos, array $historial, bool $conTraza = false): array
    {
        $this->reset();
        $this->conTraza = $conTraza;

        // --- Generar_Efectividad_Cobranza: validaciones de control (errores 901-910) ---
        $fBase = $this->leerFecha($parametros['domingo_base'] ?? null);
        if ($fBase === null) {
            throw new EfectividadV4Exception('EC-V4-901', 'Falta el DOMINGO BASE del corte.');
        }
        if ($this->diaSemana($fBase) !== 7) {
            throw new EfectividadV4Exception('EC-V4-902', 'El domingo base debe ser domingo.', ['domingo_base' => $this->ymd($fBase)]);
        }
        $fCorte = $this->leerFecha($parametros['fecha_corte'] ?? null);
        if ($fCorte === null) {
            throw new EfectividadV4Exception('EC-V4-903', 'Falta la FECHA CORTE.');
        }
        $modo = $this->normalizar($parametros['modo'] ?? '');
        if ($modo !== 'DIARIO' && $modo !== 'ACUMULADO' && $modo !== 'SEMANAL') {
            throw new EfectividadV4Exception('EC-V4-904', 'Modo invalido: DIARIO, ACUMULADO o SEMANAL.');
        }
        $fIni = $fBase + 1;
        $fFin = $fBase + 7;
        if ($fCorte < $fIni || $fCorte > $fFin) {
            throw new EfectividadV4Exception('EC-V4-905', sprintf('La fecha de corte debe estar entre %s y %s.', $this->dmy($fIni), $this->dmy($fFin)));
        }
        if ($modo === 'SEMANAL' && $fCorte !== $fFin) {
            throw new EfectividadV4Exception('EC-V4-906', 'El corte SEMANAL debe ser el domingo siguiente al domingo base.');
        }
        [$fRepIni, $fRepFin] = match ($modo) {
            'DIARIO' => [$fCorte, $fCorte],
            'ACUMULADO' => [$fIni, $fCorte], // V6: del lunes a la fecha de corte
            default => [$fIni, $fFin],
        };

        $pasos = $fCorte - $fBase;
        for ($i = 0; $i <= $pasos; $i++) {
            $nombre = self::NOMBRES_FOTO[$i];
            if (! isset($fotos[$i])) {
                throw new EfectividadV4Exception('EC-V4-907', "Falta {$nombre}.", ['foto' => $i, 'fecha' => $this->ymd($fBase + $i)]);
            }
            if (! in_array('contrato', $fotos[$i]['columnas'], true)) {
                throw new EfectividadV4Exception('EC-V4-908', "Encabezados invalidos en {$nombre}.", ['foto' => $i]);
            }
            $fotos[$i]['filas'] = $this->aArreglo($fotos[$i]['filas']);
            if (! $this->tieneContratos($fotos[$i]['filas'])) {
                throw new EfectividadV4Exception('EC-V4-909', "Cartera sin datos: {$nombre}", ['foto' => $i, 'fecha' => $this->ymd($fBase + $i)]);
            }
        }
        if (! in_array('contrato', $ingresos['columnas'], true)) {
            throw new EfectividadV4Exception('EC-V4-910', 'Encabezados invalidos en INGRESOS.');
        }
        $faltantes = array_diff(self::COLUMNAS_INGRESOS_OBLIGATORIAS, $historial['columnas']);
        if ($faltantes) {
            throw new EfectividadV4Exception('EC-V4-940', 'Encabezados faltantes en INGRESOS/historial: '.implode(', ', $faltantes).'.', ['columnas' => array_values($faltantes)]);
        }

        // --- Mapa de responsables de ruta ---
        $this->crearMapaResponsableRuta($ingresos, $fRepIni, $fRepFin);
        $this->complementarResponsables($fotos[$pasos]);

        // --- Fotografías: domingo base y evolución diaria sin acumular stocks ---
        for ($i = 0; $i <= $pasos; $i++) {
            $this->stockAtraso = [];
            $this->stockVencido = [];
            $this->procesarFoto($fotos[$i], self::NOMBRES_FOTO[$i], $fBase + $i, $fIni, $fFin);
        }

        // --- Denominadores: Sano programado del rango reportado; stocks SOLO de la foto del corte ---
        foreach ($this->cuotaMonto as $clave => $monto) {
            $f = $this->fechaDeClave($clave);
            if ($f >= $fRepIni && $f <= $fRepFin) {
                $this->sumarDic($this->sanoPC, $this->cuotaGrupo[$clave], $monto);
            }
        }
        foreach ($this->stockAtraso as $ident => $monto) {
            $this->sumarDic($this->atrasoPC, $this->grupoContrato(explode('#', $ident)[0]), $monto);
        }
        foreach ($this->stockVencido as $ident => $monto) {
            $this->sumarDic($this->vencidoPC, $this->grupoContrato(explode('#', $ident)[0]), $monto);
        }

        // --- Cobros (historial completo) ---
        $this->procesarCobros($historial, $fIni, $fFin, $fRepIni, $fRepFin);

        [$filas, $sinMovimientos] = $this->generarReporte();

        return [
            'engine_version' => self::ENGINE_VERSION,
            'parametros' => [
                'modo' => $modo,
                'domingo_base' => $this->ymd($fBase),
                'fecha_corte' => $this->ymd($fCorte),
                'inicio_ingresos' => $this->ymd($fRepIni),
                'fin_ingresos' => $this->ymd($fRepFin),
                'semana_inicio' => $this->ymd($fIni),
                'semana_fin' => $this->ymd($fFin),
            ],
            'filas' => $filas,
            'incidencias' => $this->incidenciasEstructuradas(),
            'estadisticas' => $this->estadisticas,
            'sin_movimientos' => $sinMovimientos,
            'movimientos' => $this->movimientos,
        ];
    }

    // ==================================================================
    // CrearMapaResponsableRuta — promotor con más movimientos por ruta
    // ==================================================================

    private function crearMapaResponsableRuta(array $ingresos, int $fechaIni, int $fechaFin): void
    {
        $cols = $ingresos['columnas'];
        foreach (['oficina', 'promotor', 'operacion', 'transaccion', 'fecha_transaccion'] as $c) {
            if (! in_array($c, $cols, true)) {
                return; // la macro devuelve un mapa vacío
            }
        }

        $conteo = [];
        $rutaClave = [];
        $nombreReal = [];
        foreach ($ingresos['filas'] as $row) {
            $f = $this->leerFecha($row['fecha_transaccion'] ?? null);
            if ($f === null || $f < $fechaIni || $f > $fechaFin) {
                continue;
            }
            if (! $this->pasaWhitelist($this->normalizar($row['operacion'] ?? null), $this->normalizar($row['transaccion'] ?? null))) {
                continue;
            }
            $oficina = $this->trimVba($row['oficina'] ?? null);
            $promotor = $this->trimVba($row['promotor'] ?? null);
            if ($this->normalizar($oficina) === '' || $this->normalizar($promotor) === '') {
                continue;
            }
            $rutaN = $this->normalizar($oficina);
            $clave = $rutaN.'|'.$this->normalizar($promotor);
            if (isset($conteo[$clave])) {
                $conteo[$clave]++;
            } else {
                $conteo[$clave] = 1;
                $rutaClave[$clave] = $rutaN;
                $nombreReal[$clave] = $promotor;
            }
        }

        $max = [];
        foreach ($conteo as $clave => $n) {
            $rutaN = $rutaClave[$clave];
            if (! array_key_exists($rutaN, $max)) {
                $max[$rutaN] = $n;
                $this->rutaPromo[$rutaN] = $nombreReal[$clave];
            } elseif ($n > $max[$rutaN]) {
                $max[$rutaN] = $n;
                $this->rutaPromo[$rutaN] = $nombreReal[$clave];
            }
        }
    }

    /** ComplementarResponsables — rutas sin cobros: promotor predominante en contratos activos de la última foto. */
    private function complementarResponsables(array $foto): void
    {
        $cols = $foto['columnas'];
        if (! in_array('oficina', $cols, true) || ! in_array('nombre_promotor', $cols, true) || ! in_array('contrato', $cols, true)) {
            return;
        }
        $tieneEstatus = in_array('estatus', $cols, true);

        $conteo = [];
        $real = [];
        foreach ($foto['filas'] as $row) {
            $ruta = $this->normalizar($row['oficina'] ?? null);
            $prom = $this->trimVba($row['nombre_promotor'] ?? null);
            $est = $tieneEstatus ? $this->normalizar($row['estatus'] ?? null) : '';
            if ($ruta !== '' && $prom !== '' && ! str_contains($est, 'INACTIVO') && ! str_contains($est, 'CERRADO')) {
                $clave = $ruta.'|'.$this->normalizar($prom);
                if (! isset($conteo[$clave])) {
                    $conteo[$clave] = 0;
                    $real[$clave] = $prom;
                }
                $conteo[$clave]++;
            }
        }

        $mayor = [];
        $elegido = [];
        foreach ($conteo as $clave => $n) {
            $ruta = explode('|', $clave)[0];
            if (! array_key_exists($ruta, $mayor)) {
                $mayor[$ruta] = -1;
            }
            if ($n > $mayor[$ruta]) {
                $mayor[$ruta] = $n;
                $elegido[$ruta] = $real[$clave];
            }
        }
        foreach ($mayor as $ruta => $_) {
            $ruta = (string) $ruta;
            if (! array_key_exists($ruta, $this->rutaPromo)) {
                $this->rutaPromo[$ruta] = $elegido[$ruta];
            }
        }
    }

    private function obtenerPromotorRuta(string $oficina, string $promotorOriginal): string
    {
        $ruta = $this->normalizar($oficina);
        if ($ruta !== '' && array_key_exists($ruta, $this->rutaPromo)) {
            return $this->rutaPromo[$ruta];
        }

        return $promotorOriginal;
    }

    // ==================================================================
    // ProcesarFoto — estado diario, stocks del corte y cuotas Sano
    // ==================================================================

    private function procesarFoto(array $foto, string $nombre, int $fFoto, int $fSemanaIni, int $fSemanaFin): void
    {
        $cols = array_flip($foto['columnas']);
        $faltantes = array_diff(self::COLUMNAS_FOTO_OBLIGATORIAS, $foto['columnas']);
        if ($faltantes) {
            throw new EfectividadV4Exception('EC-V4-920', "Columnas obligatorias faltantes en {$nombre}: ".implode(', ', $faltantes).'.', ['foto' => $nombre, 'columnas' => array_values($faltantes)]);
        }
        $hay = fn (string $c) => isset($cols[$c]);
        $componentesDisponibles = $hay('saldo_capital') || $hay('saldo_interes') || $hay('saldo_impuesto')
            || $hay('saldo_cargos_calendario') || $hay('saldo_impuesto_cargos_calendario');

        $fotoIdent = [];
        $fechaFoto = $this->ymdCompacto($fFoto);
        $this->estadisticas['filas_fotos'] = ($this->estadisticas['filas_fotos'] ?? 0);

        foreach ($foto['filas'] as $row) {
            $contrato = $this->trimVba($row['contrato'] ?? null);
            if ($contrato === '') {
                continue;
            }
            $this->estadisticas['filas_fotos']++;
            $k = $this->normalizar($contrato);
            $acreditado = $hay('acreditado') ? $this->normalizar($row['acreditado'] ?? null) : '';
            $ident = $k.'#'.$acreditado;
            if (isset($fotoIdent[$ident])) {
                $this->incidencia('FOTO-DUP|'.$nombre.'|'.$ident, "Contrato/acreditado repetido en {$nombre}: {$contrato}");

                continue;
            }
            $fotoIdent[$ident] = true;
            $this->observados[$k] = true; // evidencia del contrato en alguna fotografía

            $oficina = $this->trimVba($row['oficina'] ?? null);
            if ($oficina === '') {
                continue;
            }
            $prom = $this->obtenerPromotorRuta($oficina, $this->trimVba($row['nombre_promotor'] ?? null));
            if ($prom === '') {
                $this->incidencia('PROM|'.$k, "Promotor no identificado: {$contrato}, {$nombre}");

                continue;
            }
            $grp = $this->registrarGrupo($oficina, $prom);
            if (! array_key_exists($k, $this->contratoGrupo)) {
                $this->contratoGrupo[$k] = $grp;
            }

            $est = $hay('estatus') ? $this->normalizar($row['estatus'] ?? null) : '';
            $sube = $this->normalizar($row['substatus'] ?? null);
            $cred = $hay('producto') ? $this->normalizar($row['producto'] ?? null) : '';
            $excluido = $this->movimientoExcluido($cred);
            // "Origen credito = REFINANCIAMIENTO" es normal: NO excluir.
            if ($excluido) {
                $this->excluidos[$k] = true;
            }
            $activa = ! str_contains($est, 'INACTIVO') && ! str_contains($est, 'CERRADO') && ! str_contains($sube, 'INACTIVO');

            $fechaClave = $ident.'|'.$fechaFoto;
            $this->estadoDia[$fechaClave] = $sube;
            $atraso = $hay('total_atrasado') ? $this->positivo($row['total_atrasado'] ?? null) : 0.0;
            if ($hay('pagos_atrasados') && $this->numero($row['pagos_atrasados'] ?? null) > 0 && $atraso <= 0) {
                $atraso = 0.01; // bandera: existen cuotas atrasadas, sin importe útil
            }
            $this->atrasosDia[$fechaClave] = $atraso;

            $fMatura = $this->leerFecha($row['fecha_vencimiento'] ?? null);
            $tieneVenc = $fMatura !== null;
            if ($tieneVenc) {
                $this->contratoVenc[$k] = $fMatura;
            }

            if ($excluido || ! $activa) {
                continue;
            }

            if (str_contains($sube, 'VENCIDO')) {
                // LENDUS: en VENCIDO puede mostrar Total pendiente = 0 aunque Total atrasado
                // y los saldos sigan exigibles. Total atrasado manda; componentes = respaldo.
                $vencido = $hay('total_atrasado') ? $this->positivo($row['total_atrasado'] ?? null) : 0.0;
                $suma = 0.0;
                foreach (['saldo_capital', 'saldo_interes', 'saldo_impuesto', 'saldo_cargos_calendario', 'saldo_impuesto_cargos_calendario'] as $c) {
                    if ($hay($c)) {
                        $suma += $this->numero($row[$c] ?? null);
                    }
                }
                if ($vencido <= 0.01 && $componentesDisponibles && $suma > 0) {
                    $vencido = $suma;
                }
                if ($vencido <= 0.01 && $hay('total_pendiente')) {
                    $vencido = $this->positivo($row['total_pendiente'] ?? null);
                }
                if ($vencido > 0.01) {
                    $this->stockVencido[$ident] = $vencido;
                }
            } elseif ($atraso > 0.01) {
                $this->stockAtraso[$ident] = $atraso;
            }

            // Contratos VENCIDOS no crean nuevas cuotas sanas.
            if (str_contains($sube, 'VENCIDO')) {
                continue;
            }
            $cuota = $this->positivo($row['cuota_total'] ?? null);
            if ($cuota <= 0) {
                continue;
            }
            $pagos = $this->clng($this->numero($row['pagos'] ?? null));
            if ($pagos <= 0) {
                continue;
            }
            $periodicidad = $this->trimVba($row['periodicidad'] ?? null);
            $fPrimer = $this->leerFecha($row['fecha_primer_pago'] ?? null);
            if ($fPrimer === null && $hay('fecha_siguiente_pago')) {
                $fPrimer = $this->leerFecha($row['fecha_siguiente_pago'] ?? null);
            }
            if ($fPrimer === null) {
                $this->incidencia('FECHA|'.$k, "Contrato sin fecha primer pago: {$contrato}");

                continue;
            }

            $firma = $this->ymdCompacto($fPrimer).'|'.$pagos.'|'.$this->normalizar($periodicidad).'|'.number_format($cuota, 4, '.', '');
            if ($tieneVenc) {
                $firma .= '|'.$this->ymdCompacto($fMatura);
            }
            if (array_key_exists($ident, $this->firmaContrato)) {
                if ($this->firmaContrato[$ident] !== $firma) {
                    $this->descartarCuotasFuturas($ident, $fFoto);
                }
            }
            $this->firmaContrato[$ident] = $firma;

            $this->programarCuotas($ident, $grp, $fPrimer, $fMatura, $tieneVenc, $periodicidad, $pagos, $cuota, $fFoto, $fSemanaIni, $fSemanaFin);
        }
    }

    /** DescartarCuotasFuturas — cambio contractual: borra cuotas de la identidad con fecha >= foto. */
    private function descartarCuotasFuturas(string $identidad, int $fFoto): void
    {
        $prefijo = $identidad.'|';
        $len = strlen($prefijo);
        foreach (array_keys($this->cuotaMonto) as $clave) {
            if (strncmp($clave, $prefijo, $len) === 0 && $this->fechaDeClave($clave) >= $fFoto) {
                unset($this->cuotaMonto[$clave], $this->cuotaGrupo[$clave]);
            }
        }
    }

    /** ProgramarCuotas — cuotas exigibles de la semana; cambios contractuales solo prospectivos. */
    private function programarCuotas(string $k, string $grp, int $fPrim, ?int $fMatura, bool $tieneVenc, string $periodicidad, int $pagos, float $cuota, int $fFoto, int $fIni, int $fFin): void
    {
        $f = $fPrim;
        for ($n = 1; $n <= $pagos; $n++) {
            if ($tieneVenc && $f > $fMatura) {
                break;
            }
            if ($f > $fFin) {
                break;
            }
            if ($f >= $fIni) {
                $clave = $k.'|'.$this->ymdCompacto($f);
                if (! array_key_exists($clave, $this->cuotaMonto)) {
                    $this->cuotaMonto[$clave] = $cuota;
                    $this->cuotaGrupo[$clave] = $grp;
                } elseif ($f >= $fFoto) {
                    $this->cuotaMonto[$clave] = $cuota;
                }
            }
            $sig = $this->siguienteFechaCuota($f, $periodicidad);
            if ($sig <= $f) {
                break;
            }
            $f = $sig;
        }
    }

    // ==================================================================
    // ProcesarCobros — clasificación de pagos del historial
    // ==================================================================

    private function procesarCobros(array $historial, int $fSemanaIni, int $fSemanaFin, int $fRepIni, int $fRepFin): void
    {
        $cols = array_flip($historial['columnas']);
        $hay = fn (string $c) => isset($cols[$c]);
        $stats = ['ingresos_leidos' => 0, 'ingresos_whitelist' => 0, 'ingresos_en_rango' => 0, 'ingresos_clasificados' => 0];

        foreach ($historial['filas'] as $pos => $row) {
            $ct = $this->trimVba($row['contrato'] ?? null);
            if ($ct === '') {
                continue;
            }
            $stats['ingresos_leidos']++;
            $ref = (string) ($row['ref'] ?? ($pos + 2));
            $k = $this->normalizar($ct);
            $acreditado = $hay('acreditado') ? $this->normalizar($row['acreditado'] ?? null) : '';
            $ident = $k.'#'.$acreditado;
            $op = $this->normalizar($row['operacion'] ?? null);
            $tr = $this->normalizar($row['transaccion'] ?? null);
            $esRef = $op === 'REFINANCIAMIENTO' && $tr === 'DESCUENTO';
            if (! $esRef && ! ($op === 'PAGO A CONTRATO' && $tr === 'PAGO')) {
                continue;
            }
            $stats['ingresos_whitelist']++;
            if (isset($this->excluidos[$k])) {
                continue;
            }
            if ($hay('producto') && $this->movimientoExcluido($row['producto'] ?? null)) {
                continue;
            }
            if ($hay('concepto') && $this->movimientoExcluido($row['concepto'] ?? null)) {
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
            // Sin moratorios: solo capital + interés + impuesto + cargos calendario.
            $importe = $this->sumaVba($this->numero($row['capital'] ?? null), $this->numero($row['interes'] ?? null), $this->numero($row['impuesto'] ?? null));
            if ($hay('cargos_calendario')) {
                $importe += $this->numero($row['cargos_calendario'] ?? null);
            }
            if ($importe <= 0) {
                continue;
            }
            // Anticipos a fecha cuota: se reconocen el día de la cuota (o la transacción, si es posterior).
            $fRec = $fTrans > $fCuota ? $fTrans : $fCuota;
            if ($esRef) {
                // Reno solo su semana: transacción y cuota dentro de la semana evaluada.
                if ($fTrans < $fSemanaIni || $fTrans > $fSemanaFin) {
                    continue;
                }
                if ($fCuota < $fSemanaIni || $fCuota > $fSemanaFin) {
                    continue;
                }
            }
            if ($fRec < $fRepIni || $fRec > $fRepFin) {
                continue;
            }
            $stats['ingresos_en_rango']++;

            // No mezclar pagos de otra sucursal/historial ajeno: el contrato debe aparecer en alguna foto.
            if (! isset($this->observados[$k])) {
                $this->incidencia("NO-CARTERA|{$k}|{$ref}", "Contrato sin respaldo en fotos del periodo: {$ct}; validar antes de contabilizar anticipo/pago.", ['fecha' => $this->ymd($fRec), 'importe' => $importe]);

                continue;
            }

            $ofic = $this->trimVba($row['oficina'] ?? null);
            $prom = $hay('promotor') ? $this->trimVba($row['promotor'] ?? null) : '';
            if (array_key_exists($k, $this->contratoGrupo)) {
                $grp = $this->contratoGrupo[$k];
            } else {
                $prom = $this->obtenerPromotorRuta($ofic, $prom);
                if ($ofic === '' || $prom === '') {
                    $this->incidencia("GRP|{$k}|{$ref}", "Ingreso sin responsable de ruta: {$ct}", ['fecha' => $this->ymd($fRec), 'importe' => $importe]);

                    continue;
                }
                $grp = $this->registrarGrupo($ofic, $prom);
                $this->contratoGrupo[$k] = $grp;
            }

            $keyCuota = $ident.'|'.$this->ymdCompacto($fCuota);
            if (! array_key_exists($keyCuota, $this->cuotaMonto) && array_key_exists($k.'#|'.$this->ymdCompacto($fCuota), $this->cuotaMonto)) {
                $keyCuota = $k.'#|'.$this->ymdCompacto($fCuota);
            }

            $claveDedup = $ident.'|'.$this->ymdCompacto($fTrans).'|'.$this->ymdCompacto($fCuota).'|'.$op.'|'.$tr.'|'.number_format($importe, 4, '.', '');
            if ($hay('numero_cuota')) {
                $claveDedup .= '|'.$this->cstr($row['numero_cuota'] ?? null);
            }
            if (isset($this->cobroUnico[$claveDedup])) {
                $this->incidencia('DUP|'.$claveDedup, "Movimiento identico duplicado, contrato {$ct}", ['fecha' => $this->ymd($fRec), 'importe' => $importe, 'ref' => $ref]);

                continue;
            }
            $this->cobroUnico[$claveDedup] = true;

            // Vencido/atraso según foto del día de RECONOCIMIENTO; si el crédito desapareció
            // (cierre/reno) se busca la última foto previa, nunca una futura. El domingo base
            // aporta el estado para reconocer el lunes.
            $status = '';
            $atrasoDia = 0.0;
            for ($fFoto = $fRec; $fFoto >= $fSemanaIni - 1; $fFoto--) {
                $llave = $ident.'|'.$this->ymdCompacto($fFoto);
                if (! array_key_exists($llave, $this->estadoDia)) {
                    $llave = $k.'#|'.$this->ymdCompacto($fFoto);
                }
                if (array_key_exists($llave, $this->estadoDia)) {
                    $status = $this->estadoDia[$llave];
                    if (array_key_exists($llave, $this->atrasosDia)) {
                        $atrasoDia = $this->atrasosDia[$llave];
                    }
                    break;
                }
            }

            $fVen = $hay('fecha_vencimiento') ? $this->leerFecha($row['fecha_vencimiento'] ?? null) : null;
            if ($fVen === null && array_key_exists($k, $this->contratoVenc)) {
                $fVen = $this->contratoVenc[$k];
            }
            $bVenc = str_contains($status, 'VENCIDO');
            if (! $bVenc && $fVen !== null && $fRec > $fVen) {
                $bVenc = true;
            }
            $bAtraso = $atrasoDia > 0.001;

            // Un crédito ya VENCIDO puede recuperarse sin cuota sana programada; uno vigente
            // exige respaldo de su cuota de la semana.
            if ($fTrans <= $fCuota && $fCuota >= $fSemanaIni && $fCuota <= $fSemanaFin && ! array_key_exists($keyCuota, $this->cuotaMonto) && ! $bVenc) {
                $this->incidencia("CUOTA|{$k}|{$ref}", "Pago sin cuota programada verificable: {$ct}, cuota ".$this->dmy($fCuota), ['fecha' => $this->ymd($fRec), 'importe' => $importe]);

                continue;
            }

            $stats['ingresos_clasificados']++;
            if ($bVenc) {
                $categoria = 'vencido';
                $this->sumarDic($this->cobVencido, $grp, $importe);
            } elseif ($bAtraso || $fTrans > $fCuota) {
                $categoria = 'atraso';
                $this->sumarDic($this->cobAtraso, $grp, $importe);
            } else {
                $categoria = 'sano';
                $this->sumarDic($this->cobSano, $grp, $importe);
            }
            if ($this->conTraza) {
                $this->movimientos[] = [
                    'ref' => $ref, 'contrato' => $ct, 'grupo' => $grp, 'categoria' => $categoria, 'importe' => $importe,
                    'fecha_transaccion' => $this->ymd($fTrans), 'fecha_cuota' => $this->ymd($fCuota), 'fecha_reconocimiento' => $this->ymd($fRec),
                    'estado_foto' => $status, 'atraso_foto' => $atrasoDia, 'refinanciamiento' => $esRef,
                ];
            }
        }

        $this->estadisticas += $stats;
    }

    // ==================================================================
    // GenerarReporte — orden, subtotales por oficina y GRAN TOTAL
    // ==================================================================

    /** @return array{0: list<array<string,mixed>>, 1: bool} */
    private function generarReporte(): array
    {
        if (! $this->grupos) {
            return [[], true];
        }

        $claves = array_keys($this->grupos);
        $orden = [];
        foreach ($claves as $g) {
            $orden[$g] = $this->normalizar($this->oficinaDe[$g]).'|'.$this->normalizar($this->promotorDe[$g]);
        }
        usort($claves, fn ($a, $b) => strcmp($orden[$a], $orden[$b]));

        $filas = [];
        $oficinaAnterior = '';
        $t = $this->totalesVacios();
        $gt = $this->totalesVacios();
        $mostrados = 0;

        foreach ($claves as $grupo) {
            // V6: rutas sin meta sana exigible del periodo (Sano por cobrar <= 0.01) no
            // figuran ni suman al GRAN TOTAL; sus saldos de atraso/vencido se descartan.
            if (($this->sanoPC[$grupo] ?? 0.0) <= self::TOL) {
                continue;
            }
            if (! $this->grupoTieneDatos($grupo)) {
                continue;
            }
            $oficina = $this->oficinaDe[$grupo];
            $valores = [
                'sano_pc' => $this->sanoPC[$grupo] ?? 0.0,
                'sano_cob' => $this->cobSano[$grupo] ?? 0.0,
                'atraso_pc' => $this->atrasoPC[$grupo] ?? 0.0,
                'atraso_cob' => $this->cobAtraso[$grupo] ?? 0.0,
                'vencido_pc' => $this->vencidoPC[$grupo] ?? 0.0,
                'vencido_cob' => $this->cobVencido[$grupo] ?? 0.0,
            ];

            if ($oficinaAnterior === '' || $this->normalizar($oficina) !== $this->normalizar($oficinaAnterior)) {
                if ($oficinaAnterior !== '') {
                    $filas[] = $this->fila('subtotal', $oficinaAnterior, 'TOTAL '.mb_strtoupper($oficinaAnterior), $t);
                    $t = $this->totalesVacios();
                }
            }

            $filas[] = $this->fila('promotor', $oficina, $this->promotorDe[$grupo], $valores);
            foreach ($valores as $c => $v) {
                $t[$c] += $v;
                $gt[$c] += $v;
            }
            $oficinaAnterior = $oficina;
            $mostrados++;
        }

        if ($mostrados === 0) {
            return [[], true];
        }

        $filas[] = $this->fila('subtotal', $oficinaAnterior, 'TOTAL '.mb_strtoupper($oficinaAnterior), $t);
        $filas[] = $this->fila('gran_total', null, 'GRAN TOTAL', $gt);

        return [$filas, false];
    }

    /** Columnas A-M de la hoja EFECTIVIDAD: porcentajes SIEMPRE sobre el Sano por cobrar. */
    private function fila(string $tipo, ?string $oficina, string $etiqueta, array $v): array
    {
        $totalCob = $this->sumaVba($v['sano_cob'], $v['atraso_cob'], $v['vencido_cob']);
        $pc = $v['sano_pc'];

        return [
            'tipo' => $tipo,
            'oficina' => $oficina,
            'oficina_titulo' => $oficina !== null ? mb_strtoupper($oficina) : null,
            'promotor' => $etiqueta,
            'sano_pc' => $pc,
            'sano_cob' => $v['sano_cob'],
            'sano_pct' => $pc > 0 ? $v['sano_cob'] / $pc : 0.0,
            'atraso_pc' => $v['atraso_pc'],
            'atraso_cob' => $v['atraso_cob'],
            'atraso_pct' => $pc > 0 ? $v['atraso_cob'] / $pc : 0.0,
            'vencido_pc' => $v['vencido_pc'],
            'vencido_cob' => $v['vencido_cob'],
            'vencido_pct' => $pc > 0 ? $v['vencido_cob'] / $pc : 0.0,
            'cobranza_total' => $totalCob,
            'pct_total' => $pc > 0 ? $totalCob / $pc : 0.0,
            'mora' => $pc - $totalCob,
            'es_total' => $tipo !== 'promotor',
        ];
    }

    /**
     * Reconstruye filas A-M (promotores, subtotales por oficina y GRAN TOTAL) a partir de
     * importes base por promotor — misma lógica que `generarReporte()`. Lo usa la
     * calculadora para filtrar por oficina/promotor y para la agregación mensual: los
     * porcentajes se recalculan desde importes, nunca se suman.
     *
     * @param  list<array{oficina:string, promotor:string, sucursal?:string, sano_pc:float, sano_cob:float, atraso_pc:float, atraso_cob:float, vencido_pc:float, vencido_cob:float}>  $promotores
     * @return list<array<string,mixed>>
     */
    public function construirFilas(array $promotores, ?string $ultimaSucursal = null): array
    {
        // Sucursal (opcional): agrupa Sucursal → Ruta → Promotor con "TOTAL SUCURSAL …"
        // solo cuando hay más de una; con una sola el orden y las sumas son las de la hoja.
        $sucursales = array_unique(array_map(fn ($p) => $p['sucursal'] ?? '', $promotores));
        $conSucursal = count($sucursales) > 1;
        $orden = fn ($p) => ($conSucursal ? (($p['sucursal'] ?? '') === $ultimaSucursal ? '~' : '').$this->normalizar($p['sucursal'] ?? '').'#' : '')
            .$this->normalizar($p['oficina']).'|'.$this->normalizar($p['promotor']);
        usort($promotores, fn ($a, $b) => strcmp($orden($a), $orden($b)));

        $filas = [];
        $oficinaAnterior = '';
        $sucursalAnterior = null;
        $t = $this->totalesVacios();
        $ts = $this->totalesVacios();
        $gt = $this->totalesVacios();
        $con = fn (array $fila, ?string $suc) => $fila + ['sucursal' => $suc];
        foreach ($promotores as $p) {
            $suc = $p['sucursal'] ?? null;
            $cambiaSucursal = $conSucursal && $sucursalAnterior !== null && $suc !== $sucursalAnterior;
            if ($oficinaAnterior !== '' && ($cambiaSucursal || $this->normalizar($p['oficina']) !== $this->normalizar($oficinaAnterior))) {
                $filas[] = $con($this->fila('subtotal', $oficinaAnterior, 'TOTAL '.mb_strtoupper($oficinaAnterior), $t), $sucursalAnterior);
                $t = $this->totalesVacios();
            }
            if ($cambiaSucursal) {
                $filas[] = $con($this->fila('subtotal_sucursal', null, 'TOTAL SUCURSAL '.mb_strtoupper((string) $sucursalAnterior), $ts), $sucursalAnterior);
                $ts = $this->totalesVacios();
            }
            $valores = array_intersect_key($p, $t);
            $filas[] = $con($this->fila('promotor', $p['oficina'], $p['promotor'], $valores), $suc);
            foreach ($valores as $c => $v) {
                $t[$c] += $v;
                $ts[$c] += $v;
                $gt[$c] += $v;
            }
            $oficinaAnterior = $p['oficina'];
            $sucursalAnterior = $suc;
        }
        if ($oficinaAnterior !== '') {
            $filas[] = $con($this->fila('subtotal', $oficinaAnterior, 'TOTAL '.mb_strtoupper($oficinaAnterior), $t), $sucursalAnterior);
            if ($conSucursal) {
                $filas[] = $con($this->fila('subtotal_sucursal', null, 'TOTAL SUCURSAL '.mb_strtoupper((string) $sucursalAnterior), $ts), $sucursalAnterior);
            }
            $filas[] = $con($this->fila('gran_total', null, 'GRAN TOTAL', $gt), null);
        }

        return $filas;
    }

    private function totalesVacios(): array
    {
        return ['sano_pc' => 0.0, 'sano_cob' => 0.0, 'atraso_pc' => 0.0, 'atraso_cob' => 0.0, 'vencido_pc' => 0.0, 'vencido_cob' => 0.0];
    }

    private function grupoTieneDatos(string $g): bool
    {
        foreach ([$this->sanoPC, $this->cobSano, $this->atrasoPC, $this->cobAtraso, $this->vencidoPC, $this->cobVencido] as $dic) {
            if (abs($dic[$g] ?? 0.0) > self::TOL) {
                return true;
            }
        }

        return false;
    }

    // ==================================================================
    // Incidencias (_AUDITORIA_EFECTIVIDAD)
    // ==================================================================

    private function incidencia(string $clave, string $descripcion, array $extra = []): void
    {
        if (! array_key_exists($clave, $this->incidencias)) {
            $this->incidencias[$clave] = ['descripcion' => $descripcion] + $extra;
        }
    }

    /** @return list<array<string,mixed>> */
    private function incidenciasEstructuradas(): array
    {
        $out = [];
        foreach ($this->incidencias as $clave => $data) {
            $partes = explode('|', $clave);
            $codigo = $partes[0];
            $contrato = match ($codigo) {
                'FOTO-DUP' => explode('#', $partes[2] ?? '')[0],
                'DUP' => explode('#', $partes[1] ?? '')[0],
                default => $partes[1] ?? null,
            };
            $out[] = [
                'id' => $clave,
                'codigo' => $codigo,
                'contrato' => $contrato,
                'referencia' => match ($codigo) {
                    'NO-CARTERA', 'GRP', 'CUOTA' => $partes[2] ?? null,
                    'FOTO-DUP' => $partes[1] ?? null,
                    'DUP' => $data['ref'] ?? null,
                    default => null,
                },
                'fecha' => $data['fecha'] ?? null,
                'importe' => $data['importe'] ?? null,
                'descripcion' => $data['descripcion'],
            ];
        }

        return $out;
    }

    // ==================================================================
    // Helpers con la misma semántica que la macro
    // ==================================================================

    private function reset(): void
    {
        foreach (['rutaPromo', 'grupos', 'oficinaDe', 'promotorDe', 'nombreGrupo', 'contratoGrupo', 'contratoVenc', 'firmaContrato',
            'cuotaMonto', 'cuotaGrupo', 'estadoDia', 'atrasosDia', 'observados', 'excluidos', 'stockAtraso', 'stockVencido',
            'incidencias', 'cobroUnico', 'sanoPC', 'atrasoPC', 'vencidoPC', 'cobSano', 'cobAtraso', 'cobVencido', 'estadisticas', 'movimientos'] as $p) {
            $this->{$p} = [];
        }
    }

    private function pasaWhitelist(string $op, string $tr): bool
    {
        return ($op === 'PAGO A CONTRATO' && $tr === 'PAGO') || ($op === 'REFINANCIAMIENTO' && $tr === 'DESCUENTO');
    }

    /** MovimientoExcluido — reestructuras, unificaciones, condonaciones y coberturas. */
    public function movimientoExcluido(mixed $texto): bool
    {
        $s = $this->normalizar($texto);

        return str_contains($s, 'REESTRUCT') || str_contains($s, 'UNIFICAC') || str_contains($s, 'CONDONAC') || str_contains($s, 'COBERTURA');
    }

    /** RegistrarGrupo — llave única OFICINA + NOMBRE DEL PROMOTOR. */
    private function registrarGrupo(string $oficina, string $promotor): string
    {
        if (trim($oficina) === '') {
            $oficina = 'SIN OFICINA';
        }
        if (trim($promotor) === '') {
            $promotor = 'SIN PROMOTOR';
        }
        $clave = $this->normalizar($oficina).'|NOM|'.$this->normalizar($promotor);
        if (! isset($this->grupos[$clave])) {
            $this->grupos[$clave] = true;
            $this->oficinaDe[$clave] = $oficina;
            $this->promotorDe[$clave] = $promotor;
        }

        return $clave;
    }

    private function grupoContrato(string $claveContrato): string
    {
        return $this->contratoGrupo[$claveContrato] ?? '';
    }

    private function sumarDic(array &$dic, string $clave, float $importe): void
    {
        if ($clave === '') {
            return;
        }
        $dic[$clave] = array_key_exists($clave, $dic) ? $dic[$clave] + $importe : $importe;
    }

    private function fechaDeClave(string $clave): int
    {
        return $this->diaDesdeYmd(substr($clave, -8));
    }

    /** SiguienteFechaCuota — DIARIO salta sábado/domingo; QUINC/CATORC/14 = 14 días; MENS = DateAdd("m"); resto = 7 días. */
    private function siguienteFechaCuota(int $f, string $periodicidad): int
    {
        $p = $this->normalizar($periodicidad);
        if (str_contains($p, 'DIAR')) {
            $f++;
            while ($this->diaSemana($f) > 5) {
                $f++;
            }

            return $f;
        }
        if (str_contains($p, 'QUINC') || str_contains($p, 'CATORC') || str_contains($p, '14')) {
            return $f + 14;
        }
        if (str_contains($p, 'MENS')) {
            return $this->sumarMesVba($f);
        }

        return $f + 7;
    }

    /** DateAdd("m", 1, f): si el día no existe en el mes destino, se ajusta al último día (no desborda). */
    private function sumarMesVba(int $f): int
    {
        [$y, $m, $d] = array_map('intval', explode('-', $this->ymd($f)));
        $m++;
        if ($m > 12) {
            $m = 1;
            $y++;
        }
        $d = min($d, cal_days_in_month(CAL_GREGORIAN, $m, $y));

        return $this->diaDesdeYmd(sprintf('%04d%02d%02d', $y, $m, $d));
    }

    /** Normalizar — UCase(Trim), NBSP/CR/LF a espacio, sin acentos, sin espacios dobles. */
    public function normalizar(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }
        $s = mb_strtoupper($this->trimVba($valor));
        $s = str_replace(["\xC2\xA0", "\r", "\n"], ' ', $s);
        $s = str_replace(['Á', 'É', 'Í', 'Ó', 'Ú', 'Ü', 'Ñ'], ['A', 'E', 'I', 'O', 'U', 'U', 'N'], $s);
        while (str_contains($s, '  ')) {
            $s = str_replace('  ', ' ', $s);
        }

        return $s;
    }

    /** Trim$ de VBA: solo espacios. */
    private function trimVba(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }

        return trim($this->cstr($valor), ' ');
    }

    /** CStr: enteros sin decimales. */
    private function cstr(mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }
        if (is_float($valor) && floor($valor) === $valor && abs($valor) < 1e15) {
            return (string) (int) $valor;
        }
        if (is_bool($valor)) {
            return $valor ? 'Verdadero' : 'Falso';
        }
        if ($valor instanceof DateTimeInterface) {
            return $valor->format('d/m/Y');
        }

        return (string) $valor;
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
            $s = '-'.substr($s, 1, -1);
        }

        return is_numeric($s) ? (float) $s : 0.0;
    }

    /**
     * Suma de una sola expresión VBA (`a + b + c` asignada a un Double). VBA la
     * evalúa en la FPU x87 con precisión extendida y redondea a Double una sola
     * vez al asignar; PHP redondea en cada suma parcial. Con montos de centavos
     * eso difiere en el último bit (350.79 + 58.8 + 9.41 = 419 en VBA,
     * 419.00000000000006 en PHP) y, acumulado, en ~1e-11 por promotor.
     * Se reproduce con suma compensada (TwoSum): resultado = suma exacta
     * redondeada una vez. Solo se usa donde la macro escribe una expresión de
     * varios operandos; las acumulaciones sentencia por sentencia
     * (`dic(k) = dic(k) + importe`) siguen siendo sumas Double normales.
     */
    private function sumaVba(float ...$xs): float
    {
        $s = 0.0;
        $err = 0.0;
        foreach ($xs as $x) {
            $t = $s + $x;
            $bp = $t - $s;
            $err += ($s - ($t - $bp)) + ($x - $bp);
            $s = $t;
        }

        return $s + $err;
    }

    private function positivo(mixed $valor): float
    {
        $n = $this->numero($valor);

        return $n > 0 ? $n : 0.0;
    }

    /** CLng: redondeo bancario. */
    private function clng(float $n): int
    {
        return (int) round($n, 0, PHP_ROUND_HALF_EVEN);
    }

    /** LeerFecha — fecha real o serial de Excel > 1; devuelve días desde 1970-01-01 (solo fecha). */
    public function leerFecha(mixed $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if ($valor instanceof DateTimeInterface) {
            return $this->diaDesdeYmd($valor->format('Ymd'));
        }
        if (is_int($valor) || is_float($valor) || (is_string($valor) && is_numeric(trim($valor)))) {
            $n = (float) $valor;

            return $n > 1 ? (int) floor($n) - 25569 : null;
        }
        $s = trim((string) $valor);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $this->diaDesdeYmd($m[1].$m[2].$m[3]) : null;
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $s, $m)) {
            return checkdate((int) $m[2], (int) $m[1], (int) $m[3]) ? $this->diaDesdeYmd(sprintf('%04d%02d%02d', $m[3], $m[2], $m[1])) : null;
        }

        return null;
    }

    private function diaDesdeYmd(string $ymd): int
    {
        return intdiv((int) (new DateTimeImmutable($ymd, new DateTimeZone('UTC')))->getTimestamp(), 86400);
    }

    private function ymdCompacto(int $dia): string
    {
        return gmdate('Ymd', $dia * 86400);
    }

    public function ymd(int $dia): string
    {
        return gmdate('Y-m-d', $dia * 86400);
    }

    private function dmy(int $dia): string
    {
        return gmdate('d/m/Y', $dia * 86400);
    }

    /** Weekday(f, vbMonday): lunes = 1 … domingo = 7. */
    private function diaSemana(int $dia): int
    {
        return (int) gmdate('N', $dia * 86400);
    }

    private function aArreglo(iterable $filas): array
    {
        return is_array($filas) ? $filas : iterator_to_array($filas, false);
    }

    private function tieneContratos(array $filas): bool
    {
        foreach ($filas as $row) {
            if ($this->trimVba($row['contrato'] ?? null) !== '') {
                return true;
            }
        }

        return false;
    }
}
