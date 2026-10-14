<?php

namespace App\Services\Efectividad\Calculadora;

use App\Services\Efectividad\V4\EfectividadV4Calculator;
use App\Services\Efectividad\V4\EfectividadV4Exception;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Calculadora de Efectividad de Cobranza — ÚNICO flujo activo.
 *
 * SUBIR → VALIDAR → CALCULAR → MOSTRAR → EXPORTAR → ELIMINAR. Trabaja solo con
 * los Excel de la solicitud (espacio efímero `EfectividadWorkspace`); jamás lee
 * ni escribe tablas financieras (fact_recoveries, portfolio_snapshots,
 * efectividad_cobranza_runs…). El cálculo diario/semanal es el motor V4
 * (`EfectividadV4Calculator`, puerto de la macro) sin modificaciones; el mensual
 * es una agregación documentada de semanas completas (ver `calcularMensual`).
 */
class EfectividadCalculadoraService
{
    public const MAX_ARCHIVOS = 45;

    private const DIAS = ['Domingo base', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo corte'];

    public function __construct(
        private readonly EfectividadWorkspace $workspace,
        private readonly EfectividadExcelReader $reader,
        private readonly EfectividadV4Calculator $calc,
    ) {}

    /**
     * @param  list<UploadedFile>  $archivos
     * @return array{token:string, rechazados:list<array{nombre:string, error:string}>, resumen:array<string,mixed>}
     */
    public function agregarArchivos(?string $token, int $userId, array $archivos): array
    {
        $this->workspace->purgarVencidos();
        $token ??= $this->workspace->crear($userId);
        $meta = $this->workspace->meta($token, $userId);
        $rechazados = [];

        foreach ($archivos as $archivo) {
            $nombre = mb_substr($archivo->getClientOriginalName(), 0, 150);
            $ruta = $archivo->getRealPath();
            try {
                if (count($meta['archivos']) >= self::MAX_ARCHIVOS) {
                    throw new EfectividadArchivoException('Se alcanzó el máximo de '.self::MAX_ARCHIVOS.' archivos por cálculo.');
                }
                $sha = sha1_file($ruta);
                foreach ($meta['archivos'] as $a) {
                    if ($a['sha1'] === $sha) {
                        throw new EfectividadArchivoException("Es el mismo archivo que «{$a['nombre']}», ya cargado.");
                    }
                }
                $leido = $this->reader->leer($ruta);
                if ($leido['tipo'] === EfectividadExcelReader::TIPO_CARTERA) {
                    foreach ($meta['archivos'] as $a) {
                        if ($a['tipo'] === EfectividadExcelReader::TIPO_CARTERA && $a['fecha'] === $leido['fecha']) {
                            throw new EfectividadArchivoException('Ya hay una cartera con fecha '.$this->dmy($leido['fecha'])." («{$a['nombre']}»). Quita una de las dos.");
                        }
                    }
                }
                $id = bin2hex(random_bytes(6));
                $this->workspace->escribir($token, 'f_'.$id, ['columnas' => $leido['columnas'], 'filas' => $leido['filas']]);
                $meta['archivos'][] = [
                    'id' => $id, 'nombre' => $nombre, 'sha1' => $sha, 'tipo' => $leido['tipo'],
                    'fecha' => $leido['fecha'], 'desde' => $leido['desde'], 'hasta' => $leido['hasta'],
                    'resumen' => $leido['resumen'], 'orden' => count($meta['archivos']),
                ];
            } catch (EfectividadArchivoException $e) {
                $rechazados[] = ['nombre' => $nombre, 'error' => $e->getMessage()];
            } catch (Throwable) {
                $rechazados[] = ['nombre' => $nombre, 'error' => 'No se pudo leer el archivo. Verifica que sea el Excel original de Lendus.'];
            } finally {
                // El Excel original nunca se conserva: solo su contenido leído y cifrado.
                if ($ruta && is_file($ruta)) {
                    @unlink($ruta);
                }
            }
        }

        $this->workspace->borrarParte($token, 'resultado');
        $this->workspace->guardarMeta($token, $meta);

        return ['token' => $token, 'rechazados' => $rechazados, 'resumen' => $this->resumen($meta)];
    }

    public function quitarArchivo(string $token, int $userId, string $id): array
    {
        $meta = $this->workspace->meta($token, $userId);
        $meta['archivos'] = array_values(array_filter($meta['archivos'], fn ($a) => $a['id'] !== $id));
        $this->workspace->borrarParte($token, 'f_'.preg_replace('/[^a-f0-9]/', '', $id));
        $this->workspace->borrarParte($token, 'resultado');
        $this->workspace->guardarMeta($token, $meta);

        return $this->resumen($meta);
    }

    /** Resumen visual de fuentes + cortes posibles por modalidad. */
    public function resumen(array $meta): array
    {
        $carteras = array_values(array_filter($meta['archivos'], fn ($a) => $a['tipo'] === EfectividadExcelReader::TIPO_CARTERA));
        usort($carteras, fn ($a, $b) => strcmp($a['fecha'], $b['fecha']));
        $ingresos = array_values(array_filter($meta['archivos'], fn ($a) => $a['tipo'] === EfectividadExcelReader::TIPO_INGRESOS));
        usort($ingresos, fn ($a, $b) => strcmp($a['desde'], $b['desde']));

        $fechas = array_flip(array_column($carteras, 'fecha'));
        $cobertura = $this->coberturaIngresos($ingresos);

        $semanal = [];
        $diario = [];
        foreach ($carteras as $c) {
            $d = CarbonImmutable::parse($c['fecha']);
            // Diario: fotos desde el domingo base de esa semana hasta el día.
            $base = $d->dayOfWeek === 0 ? $d->subDays(7) : $d->subDays($d->dayOfWeek);
            if ($this->fotosCompletas($fechas, $base, $d)) {
                $diario[] = $this->opcionCorte($base, $d, $d, $d, $cobertura);
            }
            if ($d->dayOfWeek === 0 && $this->fotosCompletas($fechas, $d->subDays(7), $d)) {
                $semanal[] = $this->opcionCorte($d->subDays(7), $d, $d->subDays(6), $d, $cobertura);
            }
        }
        $mensual = [];
        foreach ($semanal as $s) {
            $mes = substr($s['corte'], 0, 7);
            $mensual[$mes] ??= ['valor' => $mes, 'etiqueta' => mb_convert_case(CarbonImmutable::parse($mes.'-01')->locale('es')->translatedFormat('F Y'), MB_CASE_TITLE), 'semanas' => 0, 'ingresos_ok' => true];
            $mensual[$mes]['semanas']++;
            $mensual[$mes]['ingresos_ok'] = $mensual[$mes]['ingresos_ok'] && $s['ingresos_ok'];
        }

        // Semana sugerida para el panel: la última semana con domingo de corte cargado.
        $semanaVista = null;
        $ultimoDomingo = null;
        foreach (array_reverse($carteras) as $c) {
            if (CarbonImmutable::parse($c['fecha'])->dayOfWeek === 0 && isset($fechas[CarbonImmutable::parse($c['fecha'])->subDays(7)->toDateString()])) {
                $ultimoDomingo = CarbonImmutable::parse($c['fecha']);
                break;
            }
        }
        $ultimoDomingo ??= $carteras ? CarbonImmutable::parse(end($carteras)['fecha'])->next(CarbonImmutable::SUNDAY) : null;
        if ($ultimoDomingo) {
            $base = $ultimoDomingo->subDays(7);
            $dias = [];
            for ($i = 0; $i <= 7; $i++) {
                $f = $base->addDays($i)->toDateString();
                $archivo = null;
                foreach ($carteras as $c) {
                    if ($c['fecha'] === $f) {
                        $archivo = $c;
                    }
                }
                $dias[] = ['dia' => self::DIAS[$i], 'fecha' => $f, 'disponible' => $archivo !== null, 'archivo' => $archivo['nombre'] ?? null, 'contratos' => $archivo['resumen']['contratos'] ?? null];
            }
            $semanaVista = [
                'titulo' => 'Semana del '.$this->fechaLarga($base->addDay()).' al '.$this->fechaLarga($ultimoDomingo),
                'dias' => $dias,
                'ingresos_ok' => $this->cubre($cobertura, $base->addDay()->toDateString(), $ultimoDomingo->toDateString()),
            ];
        }

        $publico = fn ($a) => array_intersect_key($a, array_flip(['id', 'nombre', 'tipo', 'fecha', 'desde', 'hasta', 'resumen']));

        return [
            'carteras' => array_map($publico, $carteras),
            'ingresos' => array_map($publico, $ingresos),
            'semana' => $semanaVista,
            'cortes' => ['DIARIO' => array_reverse($diario), 'SEMANAL' => array_reverse($semanal), 'MENSUAL' => array_reverse(array_values($mensual))],
            'expira_minutos' => EfectividadWorkspace::TTL_MINUTOS,
        ];
    }

    /**
     * @param  string  $modo  DIARIO | SEMANAL | MENSUAL
     * @param  string  $valor  fecha de corte (AAAA-MM-DD) o mes (AAAA-MM)
     */
    public function calcular(string $token, int $userId, string $modo, string $valor): array
    {
        $meta = $this->workspace->meta($token, $userId);
        $resumen = $this->resumen($meta);
        $opciones = $resumen['cortes'][$modo] ?? null;
        if ($opciones === null) {
            throw new EfectividadArchivoException('Modalidad no válida.');
        }
        $opcion = collect($opciones)->firstWhere('valor', $valor);
        if (! $opcion) {
            throw new EfectividadArchivoException('Con los archivos cargados no es posible calcular ese corte. Revisa las carteras faltantes.');
        }
        if (! $resumen['ingresos']) {
            throw new EfectividadArchivoException('Falta el archivo de Ingresos Cobranza.');
        }

        $historial = $this->historial($token, $meta);
        $cache = [];

        try {
            $resultado = $modo === 'MENSUAL'
                ? $this->calcularMensual($token, $meta, $valor, $historial, $cache)
                : $this->calcularCorte($token, $meta, $modo, $opcion['base'], $opcion['corte'], $historial, $cache);
        } catch (EfectividadV4Exception $e) {
            throw new EfectividadArchivoException($e->getMessage());
        }

        $avisos = [];
        if (! $opcion['ingresos_ok']) {
            $avisos[] = 'Los archivos de ingresos no cubren todos los días del periodo; los cobros de los días sin ingresos no se reflejan.';
        }
        if (! $opcion['historial_previo']) {
            $avisos[] = 'No se cargaron ingresos anteriores al periodo. Los anticipos (pagos hechos antes con fecha de cuota dentro del periodo) y los pagos de contratos sin cartera de semanas previas solo se reflejan si agregas también el Excel de Ingresos Cobranza de esas semanas.';
        }

        $resultado += [
            'modo' => $modo,
            'leyenda' => EfectividadV4Calculator::LEYENDA,
            'avisos' => $avisos,
            'generado' => now('America/Mexico_City')->format('d/m/Y H:i'),
            'fuentes' => [
                'carteras' => count($resumen['carteras']),
                'ingresos' => array_map(fn ($i) => ['nombre' => $i['nombre'], 'desde' => $i['desde'], 'hasta' => $i['hasta'], 'registros' => $i['resumen']['registros']], $resumen['ingresos']),
            ],
        ];
        $resultado['catalogo'] = $this->catalogo($resultado['filas']);
        $this->workspace->escribir($token, 'resultado', $resultado);

        return $resultado;
    }

    public function resultado(string $token, int $userId): array
    {
        $this->workspace->meta($token, $userId);
        $r = $this->workspace->leer($token, 'resultado');
        if (! $r) {
            throw new EfectividadArchivoException('Primero calcula la efectividad.');
        }

        return $r;
    }

    /** Aplica filtros de oficina/promotor recalculando subtotales y gran total desde importes. */
    public function filtrar(array $resultado, ?string $oficina, ?string $promotor): array
    {
        if (! $oficina && ! $promotor) {
            return $resultado;
        }
        $n = fn ($v) => $this->calc->normalizar($v);
        $promotores = [];
        foreach ($resultado['filas'] as $f) {
            if ($f['tipo'] !== 'promotor') {
                continue;
            }
            if ($oficina && $n($f['oficina']) !== $n($oficina)) {
                continue;
            }
            if ($promotor && $n($f['promotor']) !== $n($promotor)) {
                continue;
            }
            $promotores[] = $this->base($f);
        }
        $resultado['filas'] = $this->calc->construirFilas($promotores);
        $resultado['filtro'] = array_filter(['oficina' => $oficina, 'promotor' => $promotor]);

        return $resultado;
    }

    public function limpiar(string $token, int $userId): void
    {
        try {
            $this->workspace->meta($token, $userId);
        } catch (EfectividadArchivoException) {
            return;
        }
        $this->workspace->eliminar($token);
    }

    // ------------------------------------------------------------------

    private function calcularCorte(string $token, array $meta, string $modo, string $base, string $corte, array $historial, array &$cache): array
    {
        $fBase = CarbonImmutable::parse($base);
        $fCorte = CarbonImmutable::parse($corte);
        $fotos = [];
        for ($i = 0, $pasos = (int) $fBase->diffInDays($fCorte); $i <= $pasos; $i++) {
            $fotos[$i] = $this->foto($token, $meta, $fBase->addDays($i)->toDateString(), $cache) + ['nombre' => EfectividadV4Calculator::NOMBRES_FOTO[$i]];
        }
        $r = $this->calc->calculate(['modo' => $modo, 'domingo_base' => $base, 'fecha_corte' => $corte], $fotos, $historial, $historial);

        $p = $r['parametros'];

        return [
            'titulo' => $modo === 'DIARIO'
                ? 'CORTE DIARIO: '.$this->dmy($p['fecha_corte'])
                : 'CORTE SEMANAL: '.$this->dmy($p['semana_inicio']).' AL '.$this->dmy($p['semana_fin']),
            'parametros' => $p,
            'filas' => $r['filas'],
            'incidencias' => $r['incidencias'],
            'estadisticas' => $r['estadisticas'],
            'sin_movimientos' => $r['sin_movimientos'],
            'semanas' => [],
            'notas' => $this->notas($modo),
        ];
    }

    /**
     * MENSUAL — agregación documentada (la macro no define un corte mensual; no hay
     * referencia de paridad). Se calculan todas las semanas COMPLETAS cuyo domingo de
     * corte cae en el mes, cada una exactamente como la macro (SEMANAL), y por promotor:
     *  - flujos (Sano por cobrar y los tres cobrados) se SUMAN entre semanas: son
     *    cuotas/pagos de semanas distintas, sin duplicarse;
     *  - stocks (Vigente con atraso y Vencido por cobrar) se toman SOLO del último
     *    corte del mes: son saldos a una fecha y no se suman;
     *  - porcentajes y mora se recalculan desde esos importes.
     */
    private function calcularMensual(string $token, array $meta, string $mes, array $historial, array &$cache): array
    {
        $semanas = array_values(array_filter(
            array_reverse($this->resumen($meta)['cortes']['SEMANAL']),
            fn ($s) => str_starts_with($s['corte'], $mes),
        ));
        $acum = [];
        $incidencias = [];
        $detalle = [];
        $ultima = count($semanas) - 1;
        foreach ($semanas as $k => $s) {
            $r = $this->calcularCorte($token, $meta, 'SEMANAL', $s['base'], $s['corte'], $historial, $cache);
            $detalle[] = ['titulo' => $r['titulo'], 'corte' => $s['corte'], 'gran_total' => collect($r['filas'])->firstWhere('tipo', 'gran_total')];
            foreach ($r['filas'] as $f) {
                if ($f['tipo'] !== 'promotor') {
                    continue;
                }
                $clave = $this->calc->normalizar($f['oficina']).'|'.$this->calc->normalizar($f['promotor']);
                $acum[$clave] ??= ['oficina' => $f['oficina'], 'promotor' => $f['promotor'], 'sano_pc' => 0.0, 'sano_cob' => 0.0, 'atraso_pc' => 0.0, 'atraso_cob' => 0.0, 'vencido_pc' => 0.0, 'vencido_cob' => 0.0];
                foreach (['sano_pc', 'sano_cob', 'atraso_cob', 'vencido_cob'] as $c) {
                    $acum[$clave][$c] += $f[$c];
                }
                if ($k === $ultima) {
                    $acum[$clave]['atraso_pc'] = $f['atraso_pc'];
                    $acum[$clave]['vencido_pc'] = $f['vencido_pc'];
                }
            }
            foreach ($r['incidencias'] as $i) {
                $incidencias[$i['id']] ??= $i;
            }
        }

        return [
            'titulo' => 'CORTE MENSUAL: '.mb_strtoupper(CarbonImmutable::parse($mes.'-01')->locale('es')->translatedFormat('F Y')).' ('.count($semanas).' semana'.(count($semanas) === 1 ? '' : 's').')',
            'parametros' => [
                'modo' => 'MENSUAL',
                'domingo_base' => $semanas[0]['base'],
                'fecha_corte' => $semanas[$ultima]['corte'],
                'inicio_ingresos' => $semanas[0]['desde'],
                'fin_ingresos' => $semanas[$ultima]['corte'],
            ],
            'filas' => $this->calc->construirFilas(array_values($acum)),
            'incidencias' => array_values($incidencias),
            'estadisticas' => [],
            'sin_movimientos' => ! $acum,
            'semanas' => $detalle,
            'notas' => $this->notas('MENSUAL'),
        ];
    }

    private function notas(string $modo): array
    {
        $notas = [
            'Anticipos a fecha de cuota: el pago se reconoce el día mayor entre la fecha de transacción y la fecha de cuota.',
            'Refinanciamientos (REFINANCIAMIENTO/DESCUENTO) solo cuentan si la transacción y la cuota caen en la semana evaluada.',
            'Vencido/atraso según la fotografía diaria de cartera del día en que se reconoce el pago.',
            'Sin moratorios: importe = capital + interés + impuesto + cargos calendario.',
            'Porcentajes calculados sobre el Sano por cobrar; Mora = Sano por cobrar − Cobranza total.',
        ];

        return match ($modo) {
            'DIARIO' => [...$notas, 'Corte diario: Sano por cobrar = cuotas programadas del día; Vigente con atraso y Vencido = saldos de la cartera de ese día.'],
            'MENSUAL' => [...$notas, 'Mensual: suma de las semanas completas del mes, cada una calculada con las reglas semanales. Sano por cobrar y cobrados se suman; Vigente con atraso y Vencido por cobrar son saldos del último corte del mes (no se suman). Porcentajes y mora se recalculan desde los importes.'],
            default => $notas,
        };
    }

    /**
     * _HIST_INGRESOS de la solicitud: todos los Excel de ingresos cargados. Igual que
     * `ActualizarHistorialIngresos`, cada fecha de transacción sale de UNA sola carga:
     * la que termina más tarde (a igualdad, la cargada después).
     */
    private function historial(string $token, array $meta): array
    {
        $ingresos = array_values(array_filter($meta['archivos'], fn ($a) => $a['tipo'] === EfectividadExcelReader::TIPO_INGRESOS));
        usort($ingresos, fn ($a, $b) => [$b['hasta'], $b['orden']] <=> [$a['hasta'], $a['orden']]);
        $dueno = [];
        $columnas = null;
        $filas = [];
        foreach ($ingresos as $n => $a) {
            $datos = $this->workspace->leer($token, 'f_'.$a['id']) ?? ['columnas' => [], 'filas' => []];
            $columnas = $columnas === null ? $datos['columnas'] : array_values(array_intersect($columnas, $datos['columnas']));
            foreach ($datos['filas'] as $f) {
                $d = $this->calc->leerFecha($f['fecha_transaccion'] ?? null);
                if ($d !== null) {
                    $dueno[$d] ??= $n;
                    if ($dueno[$d] !== $n) {
                        continue;
                    }
                }
                if ($n > 0) {
                    $f['ref'] = ($n + 1).':'.$f['ref'];
                }
                $filas[] = $f;
            }
        }

        return ['columnas' => $columnas ?? [], 'filas' => $filas];
    }

    private function foto(string $token, array $meta, string $fecha, array &$cache): array
    {
        foreach ($meta['archivos'] as $a) {
            if ($a['tipo'] === EfectividadExcelReader::TIPO_CARTERA && $a['fecha'] === $fecha) {
                return $cache[$a['id']] ??= $this->workspace->leer($token, 'f_'.$a['id']);
            }
        }
        throw new EfectividadArchivoException('Falta la cartera del '.$this->dmy($fecha).'.');
    }

    private function catalogo(array $filas): array
    {
        $out = [];
        foreach ($filas as $f) {
            if ($f['tipo'] === 'promotor') {
                $out[$f['oficina']][] = $f['promotor'];
            }
        }

        return array_map(fn ($o, $p) => ['oficina' => $o, 'promotores' => $p], array_keys($out), $out);
    }

    private function base(array $f): array
    {
        return array_intersect_key($f, array_flip(['oficina', 'promotor', 'sano_pc', 'sano_cob', 'atraso_pc', 'atraso_cob', 'vencido_pc', 'vencido_cob']));
    }

    private function fotosCompletas(array $fechas, CarbonImmutable $desde, CarbonImmutable $hasta): bool
    {
        for ($d = $desde; $d->lte($hasta); $d = $d->addDay()) {
            if (! isset($fechas[$d->toDateString()])) {
                return false;
            }
        }

        return true;
    }

    private function opcionCorte(CarbonImmutable $base, CarbonImmutable $corte, CarbonImmutable $desde, CarbonImmutable $hasta, array $cobertura): array
    {
        $esDiario = $desde->equalTo($hasta);

        return [
            'valor' => $corte->toDateString(),
            'etiqueta' => $esDiario
                ? mb_convert_case($corte->locale('es')->translatedFormat('l'), MB_CASE_TITLE).' '.$corte->format('d/m/Y')
                : 'Del '.$desde->format('d/m/Y').' al '.$hasta->format('d/m/Y'),
            'base' => $base->toDateString(),
            'corte' => $corte->toDateString(),
            'desde' => $desde->toDateString(),
            'ingresos_ok' => $this->cubre($cobertura, $desde->toDateString(), $hasta->toDateString()),
            'historial_previo' => $cobertura !== [] && min(array_keys($cobertura)) < $base->addDay()->toDateString(),
        ];
    }

    /** @return array<string,true> días (AAAA-MM-DD) cubiertos por algún archivo de ingresos */
    private function coberturaIngresos(array $ingresos): array
    {
        $dias = [];
        foreach ($ingresos as $i) {
            for ($d = CarbonImmutable::parse($i['desde']); $d->lte(CarbonImmutable::parse($i['hasta'])); $d = $d->addDay()) {
                $dias[$d->toDateString()] = true;
            }
        }

        return $dias;
    }

    private function cubre(array $cobertura, string $desde, string $hasta): bool
    {
        for ($d = CarbonImmutable::parse($desde); $d->lte(CarbonImmutable::parse($hasta)); $d = $d->addDay()) {
            if (! isset($cobertura[$d->toDateString()])) {
                return false;
            }
        }

        return true;
    }

    private function dmy(string $ymd): string
    {
        return CarbonImmutable::parse($ymd)->format('d/m/Y');
    }

    private function fechaLarga(CarbonImmutable $d): string
    {
        return $d->locale('es')->translatedFormat('j \d\e F \d\e Y');
    }
}
