<?php

namespace App\Services\Efectividad\Calculadora;

use App\Services\Efectividad\V12\EfectividadV12Calculator;
use App\Services\Efectividad\V12\V12Exception;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Calculadora de Efectividad de Cobranza — ÚNICO flujo activo.
 *
 * SUBIR → VALIDAR → CALCULAR → MOSTRAR → FILTRAR → EXPORTAR → ELIMINAR. Trabaja
 * solo con los Excel de la solicitud (espacio efímero `EfectividadWorkspace`);
 * jamás lee ni escribe tablas financieras (fact_recoveries, portfolio_snapshots,
 * efectividad_cobranza_runs…). El cálculo diario/acumulado/semanal es el motor
 * V12.9.4 (`EfectividadV12Calculator`); el mensual es una agregación documentada
 * de semanas completas (ver `calcularMensual`). Web, PDF, PNG y Excel usan el
 * MISMO resultado guardado por `calcular()`.
 */
class EfectividadCalculadoraService
{
    public const MAX_ARCHIVOS = 45;

    /** Memoria mínima para leer/calcular en la solicitud (una semana de una sucursal grande ronda 120 MB). */
    public const MEMORIA_MINIMA = 512 * 1024 * 1024;

    public function __construct(
        private readonly EfectividadWorkspace $workspace,
        private readonly EfectividadExcelReader $reader,
        private readonly EfectividadV12Calculator $calc,
    ) {}

    /**
     * @param  list<UploadedFile>  $archivos
     * @return array{token:string, rechazados:list<array{nombre:string, error:string}>, resumen:array<string,mixed>}
     */
    public function agregarArchivos(?string $token, int $userId, array $archivos): array
    {
        self::asegurarMemoria();
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

        // Cortes candidatos por modalidad, cada uno con SUS requisitos (carteras e ingresos)
        // y su estado; nunca se exige una fotografía o un día de ingresos que el corte no usa.
        $dias = [];     // DIARIO / ACUMULADO: cualquier día con cartera (de lunes a domingo de corte)
        $domingos = []; // SEMANAL: domingo de corte de cada semana tocada por las carteras
        foreach ($carteras as $c) {
            $d = CarbonImmutable::parse($c['fecha']);
            if ($d->dayOfWeek !== 0) {
                $dias[$d->toDateString()] = $d;
                $domingos[$d->next(CarbonImmutable::SUNDAY)->toDateString()] = true;
            } else {
                // Un domingo es base de la semana siguiente; es corte de la suya si hay días previos cargados.
                $previos = false;
                for ($i = 1; $i <= 6; $i++) {
                    $previos = $previos || isset($fechas[$d->subDays($i)->toDateString()]);
                }
                if ($previos) {
                    $dias[$d->toDateString()] = $d;
                    $domingos[$d->toDateString()] = true;
                }
                $domingos[$d->addDays(7)->toDateString()] ??= ! $previos || isset($fechas[$d->addDay()->toDateString()]);
            }
        }
        ksort($dias);
        ksort($domingos);

        $diario = [];
        $acumulado = [];
        foreach ($dias as $d) {
            $base = $d->dayOfWeek === 0 ? $d->subDays(7) : $d->subDays($d->dayOfWeek);
            $diario[] = $this->opcionCorte('DIARIO', $base, $d, $d, $d, $fechas, $cobertura);
            $acumulado[] = $this->opcionCorte('ACUMULADO', $base, $d, $base->addDay(), $d, $fechas, $cobertura);
        }
        $semanal = [];
        foreach (array_keys(array_filter($domingos)) as $domingo) {
            $d = CarbonImmutable::parse($domingo);
            $semanal[] = $this->opcionCorte('SEMANAL', $d->subDays(7), $d, $d->subDays(6), $d, $fechas, $cobertura);
        }

        // Mensual: consolida SOLO semanas completas (8 carteras); listo si sus ingresos también lo están.
        $mensual = [];
        foreach ($semanal as $sem) {
            if (! $sem['carteras_ok']) {
                continue;
            }
            $mes = substr($sem['corte'], 0, 7);
            $mensual[$mes] ??= [
                'valor' => $mes,
                'etiqueta' => mb_convert_case(CarbonImmutable::parse($mes.'-01')->locale('es')->translatedFormat('F Y'), MB_CASE_TITLE),
                'titulo' => 'Corte mensual: '.mb_convert_case(CarbonImmutable::parse($mes.'-01')->locale('es')->translatedFormat('F Y'), MB_CASE_LOWER),
                'semanas' => 0, 'carteras_ok' => true, 'ingresos_ok' => true, 'historial_previo' => $sem['historial_previo'], 'requisitos' => [],
            ];
            $mensual[$mes]['semanas']++;
            $mensual[$mes]['ingresos_ok'] = $mensual[$mes]['ingresos_ok'] && $sem['ingresos_ok'];
            $mensual[$mes]['requisitos'][] = [
                'tipo' => 'semana', 'etiqueta' => 'Semana '.CarbonImmutable::parse($sem['desde'])->format('d/m').' al '.CarbonImmutable::parse($sem['corte'])->format('d/m'),
                'detalle' => $sem['ingresos_ok'] ? '8 carteras e ingresos completos' : 'Faltan ingresos: '.$sem['ingresos_faltantes_texto'],
                'disponible' => $sem['ingresos_ok'],
            ];
        }
        foreach ($mensual as &$m) {
            $m['listo'] = $m['ingresos_ok'];
            $m['estado'] = $m['listo'] ? 'listo' : 'faltan_ingresos';
            $m['falta'] = $m['listo'] ? null : 'Faltan ingresos en alguna semana del mes.';
        }
        unset($m);

        $publico = fn ($a) => array_intersect_key($a, array_flip(['id', 'nombre', 'tipo', 'fecha', 'desde', 'hasta', 'resumen']));

        return [
            'carteras' => array_map($publico, $carteras),
            'ingresos' => array_map($publico, $ingresos),
            'cortes' => ['DIARIO' => array_reverse($diario), 'ACUMULADO' => array_reverse($acumulado), 'SEMANAL' => array_reverse($semanal), 'MENSUAL' => array_reverse(array_values($mensual))],
            'sucursales' => $this->sucursalesDetectadas($carteras),
            'expira_minutos' => EfectividadWorkspace::TTL_MINUTOS,
        ];
    }

    /** Sucursales y rutas presentes en las carteras cargadas (según el catálogo). */
    private function sucursalesDetectadas(array $carteras): array
    {
        $out = [];
        foreach ($carteras as $c) {
            foreach ($c['resumen']['rutas'] ?? [] as $ruta) {
                $out[$this->sucursalDe($ruta)][$ruta] = true;
            }
        }
        ksort($out);

        return array_map(fn ($s, $r) => ['sucursal' => $s, 'rutas' => array_keys($r), 'en_catalogo' => $s !== $this->sinSucursal()], array_keys($out), $out);
    }

    /**
     * @param  string  $modo  DIARIO | ACUMULADO | SEMANAL | MENSUAL
     * @param  string  $valor  fecha de corte (AAAA-MM-DD) o mes (AAAA-MM)
     */
    public function calcular(string $token, int $userId, string $modo, string $valor): array
    {
        self::asegurarMemoria();
        $meta = $this->workspace->meta($token, $userId);
        $resumen = $this->resumen($meta);
        $opciones = $resumen['cortes'][$modo] ?? null;
        if ($opciones === null) {
            throw new EfectividadArchivoException('Modalidad no válida.');
        }
        $opcion = collect($opciones)->firstWhere('valor', $valor);
        if (! $opcion) {
            throw new EfectividadArchivoException('Con los archivos cargados no es posible calcular ese corte.');
        }
        // Nunca se calcula con fotografías inexistentes ni con días sin ingresos.
        if (! $opcion['listo']) {
            throw new EfectividadArchivoException($opcion['falta'] ?? 'Faltan archivos para calcular este corte.');
        }

        $historial = $this->historial($token, $meta);
        $cache = [];

        try {
            $resultado = $modo === 'MENSUAL'
                ? $this->calcularMensual($token, $meta, $valor, $historial, $cache)
                : $this->calcularCorte($token, $meta, $modo, $opcion['base'], $opcion['corte'], $historial, $cache);
        } catch (V12Exception $e) {
            throw new EfectividadArchivoException($e->getMessage());
        }

        $avisos = [];
        if (! $opcion['historial_previo']) {
            $avisos[] = 'No se cargaron ingresos anteriores al periodo. Los anticipos se reconstruyen con las fotografías de cartera (número de siguiente pago y saldo pendiente); los pagos hechos en semanas previas a una cuota del periodo solo se descuentan de la meta si agregas también el Excel de Ingresos Cobranza de esas semanas.';
        }

        $resultado += [
            'modo' => $modo,
            'leyenda' => EfectividadV12Calculator::LEYENDA,
            'metodologia' => EfectividadV12Calculator::METODOLOGIA,
            'engine_version' => EfectividadV12Calculator::ENGINE_VERSION,
            'avisos' => $avisos,
            'generado' => now('America/Mexico_City')->format('d/m/Y H:i'),
            'fuentes' => [
                'carteras' => count($resumen['carteras']),
                'ingresos' => array_map(fn ($i) => ['nombre' => $i['nombre'], 'desde' => $i['desde'], 'hasta' => $i['hasta'], 'registros' => $i['resumen']['registros']], $resumen['ingresos']),
            ],
        ];
        $resultado['catalogo'] = $this->catalogo($resultado['filas']);
        $sinSucursal = collect($resultado['filas'])->where('tipo', 'promotor')->where('sucursal', $this->sinSucursal())->pluck('oficina')->unique()->values()->all();
        if ($sinSucursal) {
            $resultado['avisos'][] = 'Estas rutas no están en el catálogo de sucursales y se muestran en "'.$this->sinSucursal().'": '.implode(', ', $sinSucursal).'. Sus importes sí se calculan.';
        }
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

    /** Filtros Sucursal → Ruta → Promotor; subtotales y gran total se recalculan desde importes. */
    public function filtrar(array $resultado, ?string $sucursal, ?string $oficina, ?string $promotor): array
    {
        if (! $sucursal && ! $oficina && ! $promotor) {
            return $resultado;
        }
        $n = fn ($v) => $this->calc->normalizar($v);
        $promotores = [];
        foreach ($resultado['filas'] as $f) {
            if ($f['tipo'] !== 'promotor') {
                continue;
            }
            if ($sucursal && $n($f['sucursal'] ?? '') !== $n($sucursal)) {
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
        $resultado['filas'] = $this->calc->construirFilas($promotores, $this->sinSucursal());
        $resultado['filtro'] = array_filter(['sucursal' => $sucursal, 'oficina' => $oficina, 'promotor' => $promotor]);

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

    /** Eleva memory_limit a MEMORIA_MINIMA solo si el actual es menor (nunca lo reduce; -1 = sin límite). */
    public static function asegurarMemoria(): void
    {
        $actual = trim((string) ini_get('memory_limit'));
        if ($actual === '-1' || $actual === '') {
            return;
        }
        $bytes = (int) $actual * match (strtoupper(substr($actual, -1))) {
            'G' => 1024 ** 3, 'M' => 1024 ** 2, 'K' => 1024, default => 1,
        };
        if ($bytes < self::MEMORIA_MINIMA) {
            ini_set('memory_limit', (string) self::MEMORIA_MINIMA);
        }
    }

    // ------------------------------------------------------------------

    private function calcularCorte(string $token, array $meta, string $modo, string $base, string $corte, array $historial, array &$cache): array
    {
        $fBase = CarbonImmutable::parse($base);
        $fCorte = CarbonImmutable::parse($corte);
        $fotos = [];
        $pasos = (int) $fBase->diffInDays($fCorte);
        // DIARIO V12.9.4: la foto base es el cierre del día anterior al corte.
        for ($i = $modo === 'DIARIO' ? $pasos - 1 : 0; $i <= $pasos; $i++) {
            $fotos[$i] = $this->foto($token, $meta, $fBase->addDays($i)->toDateString(), $cache);
        }
        $r = $this->calc->calculate(['modo' => $modo, 'domingo_base' => $base, 'fecha_corte' => $corte], $fotos, $historial, $historial);

        $p = $r['parametros'];

        return [
            'titulo' => match ($modo) {
                'DIARIO' => 'CORTE DIARIO: '.$this->dmy($p['fecha_corte']),
                'ACUMULADO' => 'ACUMULADO: '.$this->dmy($p['semana_inicio']).' AL '.$this->dmy($p['fecha_corte']),
                default => 'CORTE SEMANAL: '.$this->dmy($p['semana_inicio']).' AL '.$this->dmy($p['semana_fin']),
            },
            'parametros' => $p,
            'filas' => $this->conSucursal($r['filas']),
            'incidencias' => $r['incidencias'],
            'auditoria' => $r['auditoria'],
            'estadisticas' => $r['estadisticas'],
            'sin_movimientos' => $r['sin_movimientos'],
            'semanas' => [],
            'notas' => $this->notas($modo),
        ];
    }

    /**
     * MENSUAL — agregación documentada (la macro no define un corte mensual; no hay
     * referencia de paridad). Se calculan todas las semanas COMPLETAS cuyo domingo de
     * corte cae en el mes, cada una con la regla SEMANAL V12.9.4, y por promotor se
     * suman IMPORTES BASE (nunca porcentajes):
     *  - Sano por cobrar y los tres cobrados se SUMAN: son metas/pagos de semanas
     *    distintas, sin duplicarse;
     *  - Atraso / Vencido por cobrar (vista de control): en cada semana equivalen a
     *    lo cobrado del bucket + el saldo que sigue pendiente al corte. En el mes =
     *    cobrado del bucket en TODAS las semanas + pendiente al corte de la ÚLTIMA.
     *    Así la misma deuda no se cuenta una vez por semana;
     *  - porcentajes y mora se recalculan desde esos importes (sin base → vacío).
     * Un promotor con recuperación y meta cero se conserva.
     */
    private function calcularMensual(string $token, array $meta, string $mes, array $historial, array &$cache): array
    {
        $semanas = array_values(array_filter(
            array_reverse($this->resumen($meta)['cortes']['SEMANAL']),
            fn ($s) => $s['carteras_ok'] && str_starts_with($s['corte'], $mes),
        ));
        $acum = [];
        $incidencias = [];
        $detalle = [];
        $auditorias = [];
        $ultima = count($semanas) - 1;
        foreach ($semanas as $k => $s) {
            $r = $this->calcularCorte($token, $meta, 'SEMANAL', $s['base'], $s['corte'], $historial, $cache);
            $detalle[] = ['titulo' => $r['titulo'], 'corte' => $s['corte'], 'gran_total' => collect($r['filas'])->firstWhere('tipo', 'gran_total'), 'estado' => $r['auditoria']['estado'] ?? null];
            $auditorias[] = $r['auditoria'];
            foreach ($r['filas'] as $f) {
                if ($f['tipo'] !== 'promotor') {
                    continue;
                }
                $clave = $this->calc->normalizar($f['oficina']).'~'.$this->calc->normalizar($f['promotor']);
                $acum[$clave] ??= ['oficina' => $f['oficina'], 'promotor' => $f['promotor'], 'sucursal' => $f['sucursal'] ?? $this->sucursalDe($f['oficina']), 'sano_pc' => 0.0, 'sano_cob' => 0.0, 'atraso_pc' => 0.0, 'atraso_cob' => 0.0, 'vencido_pc' => 0.0, 'vencido_cob' => 0.0];
                foreach (['sano_pc', 'sano_cob', 'atraso_cob', 'vencido_cob'] as $c) {
                    $acum[$clave][$c] += $f[$c];
                }
                // Por cobrar de control: lo cobrado en el bucket (cada semana) + pendiente al último corte.
                $acum[$clave]['atraso_pc'] += $k === $ultima ? $f['atraso_pc'] : $f['atraso_cob'];
                $acum[$clave]['vencido_pc'] += $k === $ultima ? $f['vencido_pc'] : $f['vencido_cob'];
            }
            foreach ($r['incidencias'] as $i) {
                $incidencias[$i['id']] ??= $i;
            }
            // Memoria acotada a una semana: de las carteras ya leídas solo se reutiliza la del
            // domingo de corte (base de la semana siguiente); el resto se libera.
            $siguienteBase = array_column(array_filter($meta['archivos'], fn ($a) => $a['tipo'] === EfectividadExcelReader::TIPO_CARTERA && $a['fecha'] === $s['corte']), 'id');
            $cache = array_intersect_key($cache, array_flip($siguienteBase));
            unset($r);
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
            'filas' => $filas = $this->calc->construirFilas(array_values($acum), $this->sinSucursal()),
            'incidencias' => array_values($incidencias),
            'auditoria' => $this->auditoriaMensual($auditorias, collect($filas)->firstWhere('tipo', 'gran_total') ?? []),
            'estadisticas' => [],
            'sin_movimientos' => ! $acum,
            'semanas' => $detalle,
            'notas' => $this->notas('MENSUAL'),
        ];
    }

    /** Estado mensual: PRELIMINAR si alguna semana lo es; contadores sumados. */
    private function auditoriaMensual(array $auditorias, array $gt): array
    {
        $a = ['estado' => 'CONCILIADO'];
        foreach ($auditorias as $s) {
            if (($s['estado'] ?? null) === 'PRELIMINAR') {
                $a['estado'] = 'PRELIMINAR';
            }
            foreach (['contratos_ajustados', 'cuotas_inferidas', 'importe_inferido', 'contratos_con_diferencia', 'diferencia_sin_asignar', 'desapariciones_sin_soporte', 'saldo_sin_soporte', 'cierres_refi_comprobados', 'transferencias_gestor'] as $c) {
                $a[$c] = ($a[$c] ?? 0) + ($s[$c] ?? 0);
            }
        }
        $a['estado_texto'] = $a['estado'] === 'PRELIMINAR' ? 'Preliminar: alguna semana requiere revisar evidencias antes de comisionar.' : 'Conciliado en todas las semanas del mes.';
        $a['sano_pc'] = (float) ($gt['sano_pc'] ?? 0);
        $a['cobranza_total'] = (float) ($gt['cobranza_total'] ?? 0);
        $a['efectividad'] = $gt['pct_total'] ?? null;
        $a['mora'] = (float) ($gt['mora'] ?? 0);

        return $a;
    }

    private function notas(string $modo): array
    {
        $notas = [
            'Sano por cobrar = saldo neto exigible del periodo que seguía pendiente al inicio: cuota ordinaria menos anticipos y pagos aplicados antes del periodo. Los cobros del propio periodo no reducen la meta.',
            '% Sano, % Atraso y % Vencido = cobrado de cada bucket / Sano por cobrar. Efectividad = suma de los tres aportes, sin tope de 100 %.',
            'Vigente con atraso y Vencida por cobrar son vistas de control (cobrado en el bucket + saldo pendiente al corte); no se suman a la meta.',
            'Una cuota que vence hoy sigue sana hasta terminar el día. Una deuda que migra de sano a atraso o vencido no genera una meta nueva.',
            'Refinanciamientos: una cuota futura cancelada no es meta ni cobranza (solo se conserva el abono ordinario real previo al cierre). En una cuota del día o vencida se reconoce únicamente el neto real liquidado; los descuentos de cuotas futuras, reestructuras y unificaciones nunca son cobranza.',
            'Cambio de gestor en la semana: solo la meta pendiente pasa al nuevo gestor; los cobros previos conservan su gestor.',
            'Sin moratorios ni seguros: importe = capital + interés + impuesto. Mora = Sano por cobrar − Cobranza total (negativa si se supera la meta).',
            'Gestores con recuperación y meta cero sí suman al consolidado; su porcentaje queda vacío (sin base).',
            'Las asignaciones por saldo global se marcan como inferencias y una desaparición de cartera no se toma como liquidación: el reporte queda PRELIMINAR hasta revisar esas evidencias.',
        ];

        return match ($modo) {
            'ACUMULADO' => [...$notas, 'Acumulado: foto base del domingo; meta = cuotas netas del lunes a la fecha de corte; atraso y vencido al día de corte.'],
            'DIARIO' => [...$notas, 'Corte diario: la foto base es el cierre del día anterior; meta = cuotas netas que vencen ese día.'],
            'MENSUAL' => [...$notas, 'Mensual: suma de los importes base de las semanas completas del mes (cada una con la regla semanal). Atraso y vencido por cobrar = lo cobrado en el mes + el pendiente al último corte, para no contar la misma deuda cada semana. Porcentajes y mora se recalculan desde los importes.'],
            default => $notas,
        };
    }

    /**
     * Historial de ingresos de la solicitud, armado como `MR_ActualizarHistorial`
     * (V12.9.4): los archivos se aplican del más antiguo al más reciente; cada uno quita
     * del historial SOLO las combinaciones contrato + fecha de transacción que trae y
     * agrega sus movimientos al final. Así un movimiento nunca se duplica y gana la
     * carga más reciente. La referencia de cada movimiento es su fila en ese historial.
     */
    private function historial(string $token, array $meta): array
    {
        $ingresos = array_values(array_filter($meta['archivos'], fn ($a) => $a['tipo'] === EfectividadExcelReader::TIPO_INGRESOS));
        usort($ingresos, fn ($a, $b) => [$a['hasta'], $a['orden']] <=> [$b['hasta'], $b['orden']]);
        $columnas = null;
        $filas = [];
        foreach ($ingresos as $a) {
            $datos = $this->workspace->leer($token, 'f_'.$a['id']) ?? ['columnas' => [], 'filas' => []];
            $columnas = $columnas === null ? $datos['columnas'] : array_values(array_intersect($columnas, $datos['columnas']));
            $llave = function ($f) {
                $d = $this->calc->leerFecha($f['fecha_transaccion'] ?? null);

                return $d === null ? null : $this->calc->normalizar($f['contrato'] ?? null).'|'.$d;
            };
            $fechas = [];
            foreach ($datos['filas'] as $f) {
                if (trim((string) ($f['contrato'] ?? '')) !== '' && ($k = $llave($f)) !== null) {
                    $fechas[$k] = true;
                }
            }
            if ($fechas && $filas) {
                $filas = array_values(array_filter($filas, fn ($f) => ($k = $llave($f)) === null || ! isset($fechas[$k])));
            }
            foreach ($datos['filas'] as $f) {
                if (trim((string) ($f['contrato'] ?? '')) !== '') {
                    $filas[] = $f;
                }
            }
        }
        foreach ($filas as $i => &$f) {
            $f['ref'] = (string) ($i + 2);
        }
        unset($f);

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

    /** Sucursal → rutas → promotores presentes en el resultado (para los selectores). */
    private function catalogo(array $filas): array
    {
        $out = [];
        foreach ($filas as $f) {
            if ($f['tipo'] === 'promotor') {
                $out[$f['sucursal'] ?? $this->sinSucursal()][$f['oficina']][] = $f['promotor'];
            }
        }

        return array_map(fn ($s, $rutas) => [
            'sucursal' => $s,
            'rutas' => array_map(fn ($r, $p) => ['ruta' => $r, 'promotores' => $p], array_keys($rutas), $rutas),
        ], array_keys($out), $out);
    }

    /**
     * Sucursal de una RUTA (columna "Oficina" de Lendus) según config/efectividad_rutas.php.
     * Rutas fuera del catálogo → "SIN SUCURSAL ASIGNADA" (se calculan igual).
     */
    public function sucursalDe(?string $ruta): string
    {
        static $mapa = null;
        if ($mapa === null) {
            $mapa = [];
            foreach (config('efectividad_rutas.sucursales', []) as $sucursal => $rutas) {
                foreach ($rutas as $r) {
                    $mapa[$this->calc->normalizar($r)] = $sucursal;
                }
            }
        }

        return $mapa[$this->calc->normalizar($ruta)] ?? $this->sinSucursal();
    }

    private function sinSucursal(): string
    {
        return (string) config('efectividad_rutas.sin_sucursal', 'SIN SUCURSAL ASIGNADA');
    }

    /**
     * Agrega la sucursal a cada fila. Con una sola sucursal las filas quedan EXACTAMENTE
     * como las produjo el motor (mismo orden y sumas que la hoja); con varias se
     * reagrupan Sucursal → Ruta → Promotor con "TOTAL SUCURSAL …".
     */
    private function conSucursal(array $filas): array
    {
        $promotores = array_values(array_filter($filas, fn ($f) => $f['tipo'] === 'promotor'));
        foreach ($promotores as &$p) {
            $p['sucursal'] = $this->sucursalDe($p['oficina']);
        }
        unset($p);
        $sucursales = array_values(array_unique(array_column($promotores, 'sucursal')));
        if (count($sucursales) <= 1) {
            $s = $sucursales[0] ?? null;

            return array_map(fn ($f) => array_replace($f, ['sucursal' => $f['tipo'] === 'gran_total' ? null : $s]), $filas);
        }

        return $this->calc->construirFilas(array_map(fn ($p) => $this->base($p), $promotores), $this->sinSucursal());
    }

    private function base(array $f): array
    {
        return array_intersect_key($f, array_flip(['oficina', 'promotor', 'sucursal', 'sano_pc', 'sano_cob', 'atraso_pc', 'atraso_cob', 'vencido_pc', 'vencido_cob']));
    }

    /**
     * Un corte con sus requisitos exactos:
     *  - carteras del domingo base a la fecha de corte (lo que lee el motor);
     *  - ingresos que cubran el rango reportado (DIARIO = el día; ACUMULADO = lunes→día;
     *    SEMANAL = lunes→domingo). Un mismo Excel semanal cubre cualquier día de su semana.
     */
    private function opcionCorte(string $modo, CarbonImmutable $base, CarbonImmutable $corte, CarbonImmutable $desde, CarbonImmutable $hasta, array $fechas, array $cobertura): array
    {
        $requisitos = [];
        $faltanCarteras = [];
        // DIARIO (V12.9.4): solo la cartera del día anterior (base) y la del día de corte.
        $inicio = $modo === 'DIARIO' ? $corte->subDay() : $base;
        for ($d = $inicio, $i = (int) $base->diffInDays($inicio); $d->lte($corte); $d = $d->addDay(), $i++) {
            $ok = isset($fechas[$d->toDateString()]);
            $nombre = $d->equalTo($inicio) ? ($i === 0 ? 'Cartera base (domingo)' : 'Cartera base (cierre del '.$this->diaNombre($d).')') : ($d->equalTo($corte) && $modo !== 'SEMANAL'
                ? 'Cartera del '.$this->diaNombre($d)
                : ($i === 7 ? 'Cartera domingo de cierre' : 'Cartera del '.$this->diaNombre($d)));
            $requisitos[] = ['tipo' => 'cartera', 'etiqueta' => $nombre.' '.$d->format('d/m'), 'fecha' => $d->toDateString(), 'disponible' => $ok];
            if (! $ok) {
                $faltanCarteras[] = $d->format('d/m');
            }
        }
        $faltanIngresos = [];
        for ($d = $desde; $d->lte($hasta); $d = $d->addDay()) {
            if (! isset($cobertura[$d->toDateString()])) {
                $faltanIngresos[] = $d->format('d/m');
            }
        }
        $rango = $desde->equalTo($hasta) ? 'del '.$this->diaNombre($desde).' '.$desde->format('d/m') : 'del '.$desde->format('d/m').' al '.$hasta->format('d/m');
        $requisitos[] = [
            'tipo' => 'ingresos', 'etiqueta' => 'Ingresos Cobranza '.$rango, 'fecha' => $desde->toDateString(),
            'disponible' => ! $faltanIngresos,
            'detalle' => $faltanIngresos && count($faltanIngresos) < (int) $desde->diffInDays($hasta) + 1 ? 'Faltan: '.implode(', ', $faltanIngresos) : null,
        ];

        $carterasOk = ! $faltanCarteras;
        $ingresosOk = ! $faltanIngresos;
        $falta = match (true) {
            ! $carterasOk && ! $ingresosOk => 'Faltan carteras ('.implode(', ', $faltanCarteras).') e Ingresos Cobranza '.$rango.'.',
            ! $carterasOk => 'Falta cargar '.(count($faltanCarteras) === 1 ? 'la cartera del '.$faltanCarteras[0] : 'las carteras: '.implode(', ', $faltanCarteras)).'.',
            ! $ingresosOk => 'Solo falta cargar Ingresos Cobranza '.$rango.' para calcular.',
            default => null,
        };
        $titulo = match ($modo) {
            'DIARIO' => 'Corte diario: '.$this->diaNombre($corte).' '.$corte->format('d/m/Y'),
            'ACUMULADO' => 'Acumulado: del lunes '.$desde->format('d/m/Y').' al '.$this->diaNombre($corte).' '.$corte->format('d/m/Y'),
            default => 'Semana del '.$this->fechaLarga($desde).' al '.$this->fechaLarga($hasta),
        };

        return [
            'valor' => $corte->toDateString(),
            'etiqueta' => match ($modo) {
                'DIARIO' => mb_convert_case($this->diaNombre($corte), MB_CASE_TITLE).' '.$corte->format('d/m/Y'),
                'ACUMULADO' => 'Lunes '.$desde->format('d/m').' al '.$this->diaNombre($corte).' '.$corte->format('d/m/Y'),
                default => 'Del '.$desde->format('d/m/Y').' al '.$hasta->format('d/m/Y'),
            },
            'titulo' => $titulo,
            'base' => $base->toDateString(),
            'corte' => $corte->toDateString(),
            'desde' => $desde->toDateString(),
            'requisitos' => $requisitos,
            'carteras_ok' => $carterasOk,
            'ingresos_ok' => $ingresosOk,
            'ingresos_faltantes_texto' => implode(', ', $faltanIngresos),
            'listo' => $carterasOk && $ingresosOk,
            'estado' => $carterasOk ? ($ingresosOk ? 'listo' : 'faltan_ingresos') : 'faltan_carteras',
            'falta' => $falta,
            'historial_previo' => $cobertura !== [] && min(array_keys($cobertura)) < $base->addDay()->toDateString(),
        ];
    }

    private function diaNombre(CarbonImmutable $d): string
    {
        return $d->locale('es')->translatedFormat('l');
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

    private function dmy(string $ymd): string
    {
        return CarbonImmutable::parse($ymd)->format('d/m/Y');
    }

    private function fechaLarga(CarbonImmutable $d): string
    {
        return $d->locale('es')->translatedFormat('j \d\e F \d\e Y');
    }
}
