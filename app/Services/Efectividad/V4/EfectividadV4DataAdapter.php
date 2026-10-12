<?php

namespace App\Services\Efectividad\V4;

use App\Enums\ReportUploadStatus;
use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Models\PortfolioSnapshot;
use App\Models\PortfolioSnapshotRow;
use App\Models\Recovery;
use App\Models\ReportUpload;
use App\Services\Efectividad\EfectividadCobranzaIssue as Issue;
use App\Services\Imports\LendusCarteraFotoImportService;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Conecta la BD de Reportes con {@see EfectividadV4Calculator}.
 *
 * Periodificación: la macro V4 calcula UNA semana operativa lunes-domingo
 * (SEMANAL) o UN día de esa semana (DIARIO). En Reportes eso es exactamente un
 * periodo `weekly` (start_date lunes, end_date domingo). Para meses/bimestres
 * no existe regla verificada en la macro: se bloquea en vez de inventar una
 * agregación.
 *
 *  - Domingo base = start_date − 1 día; corte SEMANAL = end_date.
 *  - Fotografías: `portfolio_snapshots` activas de cada fecha domingo base…corte
 *    (8 en SEMANAL). La foto de un domingo sirve como corte de una semana y como
 *    base de la siguiente — es la misma fotografía.
 *  - INGRESOS del corte = `fact_recoveries` con Fecha transacción dentro del
 *    rango reportado (mapa de responsables de ruta).
 *  - _HIST_INGRESOS = `fact_recoveries` completo, pre-filtrado a los movimientos
 *    cuya fecha de reconocimiento max(transacción, cuota) cae en el rango (los
 *    demás la macro los descarta sin efecto alguno). Si dos cargas de Ingresos
 *    traen la misma fecha de transacción, se usa la más reciente para esa fecha
 *    (equivalente a `ActualizarHistorialIngresos`, que reemplaza las fechas
 *    importadas en vez de duplicarlas).
 */
class EfectividadV4DataAdapter
{
    public const MODOS = [EfectividadCobranzaRun::MODE_SEMANAL, EfectividadCobranzaRun::MODE_DIARIO];

    /** Campos de ingresos que, si vienen vacíos en TODOS los movimientos, equivalen a columna ausente. */
    private const INGRESOS_CAMPOS = [
        'oficina' => 'office_name_raw', 'promotor' => 'promoter_name', 'contrato' => 'contract', 'acreditado' => 'acreditado',
        'operacion' => 'operation', 'transaccion' => 'transaction', 'fecha_transaccion' => 'payment_date',
        'fecha_cuota' => 'installment_due_date', 'fecha_vencimiento' => 'contract_due_date', 'capital' => 'capital',
        'interes' => 'interest', 'impuesto' => 'tax', 'cargos_calendario' => 'charges', 'producto' => 'product_name',
        'concepto' => 'concept', 'numero_cuota' => 'installment_number',
    ];

    private const INGRESOS_SELECT = [
        'id', 'report_upload_id', 'branch_id', 'contract', 'acreditado', 'office_name_raw', 'promoter_name', 'operation',
        'transaction', 'payment_date', 'installment_due_date', 'contract_due_date', 'capital', 'interest', 'tax', 'charges',
        'product_name', 'product_name_raw', 'concept', 'installment_number', 'importer_version',
    ];

    /**
     * @return array{modo:string, domingo_base:string, fecha_corte:string, inicio:string, fin:string, semana_inicio:string, semana_fin:string, fechas_foto: list<string>}
     */
    public function window(Period $period, string $modo = EfectividadCobranzaRun::MODE_SEMANAL, ?string $corte = null): array
    {
        if ($period->type !== 'weekly') {
            throw new EfectividadV4Exception('EC-V4-PERIODO', 'La Efectividad de Cobranza V4 se calcula por semana operativa (lunes a domingo) o por un día de esa semana. Selecciona un periodo semanal: la macro no define una regla para meses o bimestres y no se inventa una agregación.', ['period_type' => $period->type]);
        }
        if (! $period->start_date || ! $period->end_date) {
            throw new EfectividadV4Exception('EC-V4-PERIODO', "El periodo {$period->label} no tiene fechas de inicio y fin.");
        }
        $ini = new DateTimeImmutable($period->start_date->format('Y-m-d'));
        $fin = new DateTimeImmutable($period->end_date->format('Y-m-d'));
        if ($ini->format('N') !== '1' || $fin->format('N') !== '7' || $ini->diff($fin)->days !== 6) {
            throw new EfectividadV4Exception('EC-V4-PERIODO', "El periodo {$period->label} no es una semana lunes-domingo ({$ini->format('d/m/Y')} a {$fin->format('d/m/Y')}); la macro V4 exige esa semana.");
        }
        $modo = strtoupper($modo);
        if (! in_array($modo, self::MODOS, true)) {
            throw new EfectividadV4Exception('EC-V4-904', 'El modo debe ser SEMANAL o DIARIO.');
        }
        $base = $ini->modify('-1 day');
        if ($modo === EfectividadCobranzaRun::MODE_SEMANAL) {
            $fCorte = $fin;
        } else {
            if (! $corte) {
                throw new EfectividadV4Exception('EC-V4-903', 'En modo DIARIO indica la fecha de corte.');
            }
            $fCorte = new DateTimeImmutable($corte);
            if ($fCorte < $ini || $fCorte > $fin) {
                throw new EfectividadV4Exception('EC-V4-905', sprintf('La fecha de corte debe estar entre %s y %s.', $ini->format('d/m/Y'), $fin->format('d/m/Y')));
            }
        }
        $fechas = [];
        for ($d = $base; $d <= $fCorte; $d = $d->modify('+1 day')) {
            $fechas[] = $d->format('Y-m-d');
        }

        return [
            'modo' => $modo,
            'domingo_base' => $base->format('Y-m-d'),
            'fecha_corte' => $fCorte->format('Y-m-d'),
            'inicio' => $modo === EfectividadCobranzaRun::MODE_DIARIO ? $fCorte->format('Y-m-d') : $ini->format('Y-m-d'),
            'fin' => $fCorte->format('Y-m-d'),
            'semana_inicio' => $ini->format('Y-m-d'),
            'semana_fin' => $fin->format('Y-m-d'),
            'fechas_foto' => $fechas,
        ];
    }

    /**
     * Disponibilidad de fuentes (sin leer filas de detalle): lo que muestra la
     * sección "Disponibilidad de información" y lo que valida el Job antes de calcular.
     */
    public function availability(Period $period, ?int $branchId, string $modo = EfectividadCobranzaRun::MODE_SEMANAL, ?string $corte = null): array
    {
        $w = $this->window($period, $modo, $corte);
        $issues = [];

        $snapshots = PortfolioSnapshot::query()->active()
            ->whereBetween('snapshot_date', [$w['domingo_base'], $this->hasta($w['fecha_corte'])])
            ->orderBy('id')
            ->get()
            ->filter(fn (PortfolioSnapshot $s) => $s->coversBranch($branchId));
        $uploads = ReportUpload::snapshots()
            ->whereBetween('snapshot_date', [$w['domingo_base'], $this->hasta($w['fecha_corte'])])
            ->orderBy('id')
            ->get();

        $fotos = [];
        $sha = [];
        foreach ($w['fechas_foto'] as $i => $fecha) {
            $nombre = EfectividadV4Calculator::NOMBRES_FOTO[$i];
            $delDia = $snapshots->filter(fn ($s) => $s->snapshot_date->format('Y-m-d') === $fecha)->values();
            $subidas = $uploads->filter(fn ($u) => $u->snapshot_date->format('Y-m-d') === $fecha);
            $pendientes = $subidas->filter(fn ($u) => in_array($u->status, [ReportUploadStatus::Pending, ReportUploadStatus::Processing], true));
            $fallidas = $subidas->filter(fn ($u) => $u->status === ReportUploadStatus::Failed && ! $delDia->contains('report_upload_id', $u->id));

            $estado = $delDia->isNotEmpty() ? 'disponible' : ($pendientes->isNotEmpty() ? 'pendiente' : ($fallidas->isNotEmpty() ? 'fallida' : 'faltante'));
            foreach ($delDia as $s) {
                if ($s->file_sha1) {
                    $sha[$s->file_sha1][] = $fecha;
                }
            }

            $fotos[] = [
                'indice' => $i,
                'nombre' => $nombre,
                'dia' => $this->diaSemana($fecha, $i),
                'fecha' => $fecha,
                'estado' => $estado,
                'contratos' => (int) $delDia->sum('rows_count'),
                'oficinas' => $delDia->flatMap(fn ($s) => $s->offices ?? [])->unique()->values()->all(),
                'archivos' => $delDia->map(fn (PortfolioSnapshot $s) => [
                    'snapshot_id' => $s->id, 'upload_id' => $s->report_upload_id, 'filas' => $s->rows_count,
                    'importado' => $s->imported_at?->toISOString(), 'archivo' => $uploads->firstWhere('id', $s->report_upload_id)?->original_name,
                ])->all(),
                'pendientes' => $pendientes->count(),
                'error' => $fallidas->isNotEmpty() ? $this->ultimoError($fallidas->last()) : null,
            ];

            $ctx = ['source' => 'fotos', 'sheet' => $nombre, 'expected_column' => $fecha, 'period' => $period->label];
            $fechaTxt = date('d/m/Y', strtotime($fecha));
            if ($estado === 'pendiente') {
                $issues[] = Issue::make('EC-V4-FOTO-PENDIENTE', Issue::SEVERITY_CRITICAL, Issue::CATEGORY_PARTIAL_COVERAGE, "La fotografía {$nombre} ({$fechaTxt}) se cargó pero todavía no termina de procesarse.", 'Espera a que termine su procesamiento y vuelve a generar.', $ctx);
            } elseif ($estado !== 'disponible') {
                $issues[] = Issue::make('EC-V4-FOTO-FALTANTE', Issue::SEVERITY_CRITICAL, Issue::CATEGORY_MISSING_DATA,
                    "Falta la fotografía {$nombre} del {$fechaTxt}".($branchId ? ' para esta sucursal' : '').'.'.($estado === 'fallida' ? ' La última carga de esa fecha falló: '.$fotos[$i]['error'] : ''),
                    "Descarga de Lendus el reporte Saldos por Cliente con la cartera al cierre del {$fechaTxt} y cárgalo en «Disponibilidad de información» indicando esa fecha. No se sustituye con otra fotografía.",
                    $ctx);
            }
        }
        foreach ($sha as $hash => $fechas) {
            if (count(array_unique($fechas)) > 1) {
                $txt = implode(', ', array_map(fn ($f) => date('d/m/Y', strtotime($f)), array_unique($fechas)));
                $issues[] = Issue::make('EC-V4-FOTO-DUPLICADA', Issue::SEVERITY_CRITICAL, Issue::CATEGORY_INCOMPATIBLE,
                    "El mismo archivo de cartera se cargó como fotografía de varias fechas ({$txt}). Una fotografía no puede representar dos días distintos.",
                    'Carga para cada fecha el archivo descargado de Lendus ese día.', ['source' => 'fotos', 'expected_column' => $hash]);
            }
        }
        $conjuntos = $snapshots->groupBy(fn ($s) => $s->snapshot_date->format('Y-m-d'))
            ->map(fn ($g) => $g->flatMap(fn ($s) => $s->branch_ids ?? [])->unique()->sort()->values()->implode(','))
            ->unique();
        if ($branchId === null && $conjuntos->count() > 1) {
            $issues[] = Issue::make('EC-V4-FOTO-SUCURSALES-DISTINTAS', Issue::SEVERITY_WARNING, Issue::CATEGORY_INCOMPATIBLE,
                'Las fotografías de la semana no cubren las mismas sucursales. Los contratos que solo aparecen en algunas fotos se evaluarán con la información disponible de cada día.',
                'Verifica que cada fotografía se haya descargado con el mismo filtro de sucursales, o genera el reporte por sucursal.', ['source' => 'fotos']);
        }

        $ingresos = $this->ingresosResumen($w, $branchId, $snapshots);
        $issues = [...$issues, ...$ingresos['issues']];
        unset($ingresos['issues']);

        $bloqueante = collect($issues)->contains('severity', Issue::SEVERITY_CRITICAL);

        return [
            'ventana' => $w,
            'fotos' => $fotos,
            'fotos_disponibles' => count(array_filter($fotos, fn ($f) => $f['estado'] === 'disponible')),
            'fotos_requeridas' => count($fotos),
            'ingresos' => $ingresos,
            'issues' => Issue::normalize($issues),
            'bloqueante' => $bloqueante,
            'requiere_confirmacion' => ! $bloqueante && $ingresos['requiere_confirmacion'],
        ];
    }

    /**
     * Entrada completa del calculador. Debe llamarse solo si `availability()` no es bloqueante.
     *
     * @return array{parametros: array, fotos: array, ingresos: array, historial: array, cobertura: array}
     */
    public function build(Period $period, ?int $branchId, string $modo = EfectividadCobranzaRun::MODE_SEMANAL, ?string $corte = null, ?callable $onProgress = null): array
    {
        $w = $this->window($period, $modo, $corte);
        $snapshots = PortfolioSnapshot::query()->active()
            ->whereBetween('snapshot_date', [$w['domingo_base'], $this->hasta($w['fecha_corte'])])
            ->orderBy('id')->get()
            ->filter(fn (PortfolioSnapshot $s) => $s->coversBranch($branchId));

        $total = (int) $snapshots->sum('rows_count');
        $leidas = 0;
        $onProgress && $onProgress('cartera', 0, $total);

        $fotos = [];
        $observados = [];
        $calc = new EfectividadV4Calculator;
        foreach ($w['fechas_foto'] as $i => $fecha) {
            $delDia = $snapshots->filter(fn ($s) => $s->snapshot_date->format('Y-m-d') === $fecha)->values();
            if ($delDia->isEmpty()) {
                continue; // el calculador lanza EC-V4-907 con el nombre de la foto
            }
            $columnas = null;
            foreach ($delDia as $s) {
                $columnas = $columnas === null ? ($s->headers ?? []) : array_values(array_intersect($columnas, $s->headers ?? []));
            }
            $filas = [];
            PortfolioSnapshotRow::query()
                ->whereIn('portfolio_snapshot_id', $delDia->pluck('id'))
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->orderBy('portfolio_snapshot_id')->orderBy('source_row')
                ->select(['contract', 'acreditado', 'office_name_raw', 'promoter_name', 'promoter_code', 'client_code', 'status', 'substatus',
                    'product_name_raw', 'origin', 'first_payment_date', 'contract_due_date', 'next_payment_date', 'installment_total',
                    'periodicity', 'num_payments', 'total_overdue', 'overdue_installments', 'total_pending', 'saldo_capital', 'saldo_interes',
                    'saldo_impuesto', 'saldo_cargos_calendario', 'saldo_impuesto_cargos_calendario'])
                ->orderBy('id')
                ->toBase()
                ->lazy(2000)
                ->each(function ($r) use (&$filas, &$observados, &$leidas, $onProgress, $total, $calc) {
                    $filas[] = [
                        'contrato' => $r->contract, 'acreditado' => $r->acreditado, 'oficina' => $r->office_name_raw,
                        'nombre_promotor' => $r->promoter_name, 'promotor_codigo' => $r->promoter_code, 'cliente' => $r->client_code,
                        'estatus' => $r->status, 'substatus' => $r->substatus, 'producto' => $r->product_name_raw, 'origen' => $r->origin,
                        'fecha_primer_pago' => $r->first_payment_date, 'fecha_vencimiento' => $r->contract_due_date,
                        'fecha_siguiente_pago' => $r->next_payment_date, 'cuota_total' => $this->f($r->installment_total),
                        'periodicidad' => $r->periodicity, 'pagos' => $this->f($r->num_payments), 'total_atrasado' => $this->f($r->total_overdue),
                        'pagos_atrasados' => $this->f($r->overdue_installments), 'total_pendiente' => $this->f($r->total_pending),
                        'saldo_capital' => $this->f($r->saldo_capital), 'saldo_interes' => $this->f($r->saldo_interes),
                        'saldo_impuesto' => $this->f($r->saldo_impuesto), 'saldo_cargos_calendario' => $this->f($r->saldo_cargos_calendario),
                        'saldo_impuesto_cargos_calendario' => $this->f($r->saldo_impuesto_cargos_calendario),
                    ];
                    $observados[$calc->normalizar($r->contract)] = true;
                    $leidas++;
                    if ($onProgress && $leidas % 1000 === 0) {
                        $onProgress('cartera', $leidas, $total);
                    }
                });
            $fotos[$i] = ['nombre' => EfectividadV4Calculator::NOMBRES_FOTO[$i], 'fecha' => $fecha, 'columnas' => $columnas ?? [], 'filas' => $filas];
        }
        $onProgress && $onProgress('cartera', $leidas, $total);

        [$corteRows, $histRows, $dedup] = $this->ingresosFilas($w, $branchId, $observados, $onProgress);

        $columnas = $this->columnasIngresos([...$corteRows, ...$histRows]);

        return [
            'parametros' => ['modo' => $w['modo'], 'domingo_base' => $w['domingo_base'], 'fecha_corte' => $w['fecha_corte']],
            'ventana' => $w,
            'fotos' => $fotos,
            'ingresos' => ['columnas' => $columnas, 'filas' => $corteRows],
            'historial' => ['columnas' => $columnas, 'filas' => $histRows],
            'cobertura' => [
                'fotos' => collect($fotos)->map(fn ($f) => ['nombre' => $f['nombre'], 'fecha' => $f['fecha'], 'filas' => count($f['filas'])])->values()->all(),
                'filas_fotos' => $leidas,
                'ingresos_corte' => count($corteRows),
                'ingresos_historial' => count($histRows),
                'fechas_con_cargas_solapadas' => $dedup,
            ],
        ];
    }

    // ------------------------------------------------------------------

    private function ingresosResumen(array $w, ?int $branchId, Collection $snapshots): array
    {
        $issues = [];
        $base = Recovery::query()->whereBetween('payment_date', [$w['inicio'], $this->hasta($w['fin'])])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $stats = (clone $base)->toBase()->selectRaw('COUNT(*) as n, COUNT(DISTINCT payment_date) as dias, MIN(payment_date) as min_f, MAX(payment_date) as max_f,
            SUM(CASE WHEN installment_due_date IS NULL THEN 1 ELSE 0 END) as sin_cuota,
            SUM(CASE WHEN office_name_raw IS NULL THEN 1 ELSE 0 END) as sin_oficina,
            SUM(CASE WHEN product_name_raw IS NULL THEN 1 ELSE 0 END) as sin_producto_original')->first();
        $uploadIds = (clone $base)->toBase()->distinct()->pluck('report_upload_id')->all();
        $historial = Recovery::query()->where('payment_date', '<', $w['inicio'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->toBase()->selectRaw('COUNT(*) as n, MIN(payment_date) as min_f, MAX(payment_date) as max_f')->first();
        $semanaPrevia = Recovery::query()->whereBetween('payment_date', [
            date('Y-m-d', strtotime($w['semana_inicio'].' -7 days')), $this->hasta(date('Y-m-d', strtotime($w['semana_inicio'].' -1 day'))),
        ])->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->count();

        $n = (int) ($stats->n ?? 0);
        $dias = (int) ($stats->dias ?? 0);
        $minF = $stats->min_f ? substr((string) $stats->min_f, 0, 10) : null;
        $maxF = $stats->max_f ? substr((string) $stats->max_f, 0, 10) : null;
        $ctx = ['source' => 'ingresos'];
        $rango = date('d/m/Y', strtotime($w['inicio'])).' al '.date('d/m/Y', strtotime($w['fin']));

        $confirmar = false;
        if ($n === 0) {
            $confirmar = true; // la macro pide confirmar un corte sin movimientos
            $issues[] = Issue::make('EC-V4-INGRESOS-SIN-MOVIMIENTOS', Issue::SEVERITY_WARNING, Issue::CATEGORY_PARTIAL_COVERAGE,
                "No hay movimientos de Ingresos Cobranza del {$rango}.",
                'Si sí hubo cobranza, carga el Excel de Ingresos Cobranza del mes que contiene estas fechas (Histórico General). Si confirmas que no hubo movimientos, puedes generar el reporte.', $ctx);
        } else {
            if ((int) $stats->sin_cuota === $n) {
                $issues[] = Issue::make('EC-V4-INGRESOS-SIN-FECHA-CUOTA', Issue::SEVERITY_CRITICAL, Issue::CATEGORY_STALE,
                    "Los {$n} movimientos de Ingresos del {$rango} no tienen «Fecha cuota» en la base de datos. La macro la necesita para reconocer anticipos y clasificar cada pago.",
                    'Vuelve a procesar el archivo de Ingresos Cobranza (si el Excel trae la columna Fecha cuota) o carga el formato completo de 35 columnas.', $ctx + ['field' => 'installment_due_date', 'record_count' => $n]);
            } elseif ((int) $stats->sin_cuota > 0) {
                $issues[] = Issue::make('EC-V4-INGRESOS-FECHA-CUOTA-PARCIAL', Issue::SEVERITY_WARNING, Issue::CATEGORY_PARTIAL_COVERAGE,
                    "{$stats->sin_cuota} de {$n} movimientos del {$rango} no tienen «Fecha cuota»; la macro los omite.",
                    'Revisa que todos los archivos de Ingresos del periodo sean del formato completo.', $ctx + ['record_count' => (int) $stats->sin_cuota]);
            }
            if ((int) $stats->sin_oficina === $n) {
                $issues[] = Issue::make('EC-V4-INGRESOS-SIN-OFICINA', Issue::SEVERITY_CRITICAL, Issue::CATEGORY_MISSING_COLUMNS,
                    'Los movimientos de Ingresos no tienen «Oficina». La macro la requiere para asignar el responsable de ruta.',
                    'Carga el formato completo de Ingresos Cobranza (con columna Oficina) y vuelve a procesarlo.', $ctx + ['field' => 'office_name_raw']);
            }
            if ((int) $stats->sin_producto_original > 0) {
                $issues[] = Issue::make('EC-V4-INGRESOS-PRODUCTO-NORMALIZADO', Issue::SEVERITY_INFO, Issue::CATEGORY_STALE,
                    "{$stats->sin_producto_original} movimientos se importaron antes de guardar el texto original de «Producto de crédito»; para la exclusión de reestructuras se usa el producto normalizado (conserva REESTRUCTURA/UNIFICACIÓN/CONDONACIÓN/COBERTURA).",
                    'Opcional: reprocesa el archivo de Ingresos para guardar el texto original.', $ctx);
            }
            // Misma condición con la que la macro pide confirmar la semana completa.
            if ($w['modo'] === EfectividadCobranzaRun::MODE_SEMANAL && ($dias <= 3 || $minF > $w['inicio'])) {
                $confirmar = true;
                $issues[] = Issue::make('EC-V4-INGRESOS-SEMANA-PARCIAL', Issue::SEVERITY_WARNING, Issue::CATEGORY_PARTIAL_COVERAGE,
                    sprintf('Ingresos Cobranza solo tiene movimientos entre %s y %s (%d día(s) con movimientos).', date('d/m/Y', strtotime($minF)), date('d/m/Y', strtotime($maxF)), $dias),
                    "Confirma que el archivo cargado incluye la SEMANA COMPLETA del {$rango}; si no, carga el archivo completo antes de generar.", $ctx);
            }
            if ($maxF !== null && $maxF < date('Y-m-d', strtotime($w['fin'].' -1 day'))) {
                $issues[] = Issue::make('EC-V4-INGRESOS-CORTE-INCOMPLETO', Issue::SEVERITY_WARNING, Issue::CATEGORY_PARTIAL_COVERAGE,
                    'El último día con movimientos de Ingresos es el '.date('d/m/Y', strtotime($maxF)).' y el corte es el '.date('d/m/Y', strtotime($w['fin'])).'.',
                    'Si hubo cobranza después de esa fecha, vuelve a cargar el Excel de Ingresos actualizado.', $ctx);
            }
        }
        if ((int) ($historial->n ?? 0) === 0) {
            $issues[] = Issue::make('EC-V4-HISTORIAL-VACIO', Issue::SEVERITY_INFO, Issue::CATEGORY_PARTIAL_COVERAGE,
                'No hay movimientos de Ingresos anteriores al periodo. Los anticipos pagados antes de la semana (a fecha de cuota de esta semana) no podrán reconocerse.',
                'Carga los Ingresos Cobranza de los meses anteriores si se requiere reconocer anticipos previos.', $ctx);
        } elseif ($semanaPrevia === 0) {
            $issues[] = Issue::make('EC-V4-HISTORIAL-SEMANA-PREVIA', Issue::SEVERITY_INFO, Issue::CATEGORY_PARTIAL_COVERAGE,
                'No hay movimientos de Ingresos de la semana anterior: los anticipos pagados esa semana no se reconocerán.',
                'Carga los Ingresos de la semana previa si existen.', $ctx);
        }

        return [
            'movimientos_corte' => $n,
            'dias_con_movimientos' => $dias,
            'primer_dia' => $minF,
            'ultimo_dia' => $maxF,
            'cargas' => $uploadIds,
            'historial_movimientos' => (int) ($historial->n ?? 0),
            'historial_desde' => $historial->min_f ? substr((string) $historial->min_f, 0, 10) : null,
            'historial_hasta' => $historial->max_f ? substr((string) $historial->max_f, 0, 10) : null,
            'semana_previa_movimientos' => $semanaPrevia,
            'requiere_confirmacion' => $confirmar,
            'issues' => $issues,
        ];
    }

    /** @return array{0: list<array>, 1: list<array>, 2: array<string,int>} */
    private function ingresosFilas(array $w, ?int $branchId, array $observados, ?callable $onProgress): array
    {
        // Movimientos que pueden afectar el corte: reconocimiento = max(transacción, cuota) dentro del rango,
        // más los del rango por fecha de transacción (mapa de responsables de ruta).
        $query = Recovery::query()->toBase()->select(self::INGRESOS_SELECT)
            ->where(fn ($q) => $q
                ->whereBetween('payment_date', [$w['inicio'], $this->hasta($w['fin'])])
                ->orWhere(fn ($q2) => $q2->where('payment_date', '<=', $this->hasta($w['fin']))->whereBetween('installment_due_date', [$w['inicio'], $this->hasta($w['fin'])])))
            ->orderBy('id');

        $total = (clone $query)->count();
        $onProgress && $onProgress('ingresos', 0, $total);

        $filas = [];
        $leidas = 0;
        $calc = new EfectividadV4Calculator;
        foreach ($query->lazy(2000) as $r) {
            $leidas++;
            if ($onProgress && $leidas % 1000 === 0) {
                $onProgress('ingresos', $leidas, $total);
            }
            if ($branchId && (int) $r->branch_id !== $branchId && ! isset($observados[$calc->normalizar($r->contract)])) {
                continue; // movimiento de otra sucursal sin contrato en las fotos de esta
            }
            $filas[] = $r;
        }
        $onProgress && $onProgress('ingresos', $leidas, $total);

        // Reemplazo por fecha (ActualizarHistorialIngresos): una fecha de transacción proviene de UNA sola carga.
        $fechas = array_values(array_unique(array_map(fn ($r) => substr((string) $r->payment_date, 0, 10), $filas)));
        $vigente = [];
        $solapadas = [];
        if ($fechas) {
            sort($fechas);
            $enUso = array_flip($fechas);
            // Rango (aprovecha el índice de payment_date) y filtro exacto por día en PHP.
            $porFecha = DB::table('fact_recoveries')
                ->whereBetween('payment_date', [$fechas[0], $this->hasta(end($fechas))])
                ->selectRaw('payment_date, report_upload_id, COUNT(*) as n')
                ->groupBy('payment_date', 'report_upload_id')->get()
                ->filter(fn ($x) => isset($enUso[substr((string) $x->payment_date, 0, 10)]));
            foreach ($porFecha->groupBy(fn ($x) => substr((string) $x->payment_date, 0, 10)) as $fecha => $g) {
                $vigente[$fecha] = (int) $g->max('report_upload_id');
                if ($g->pluck('report_upload_id')->unique()->count() > 1) {
                    $solapadas[$fecha] = $g->pluck('report_upload_id')->unique()->count();
                }
            }
        }

        $corte = [];
        $hist = [];
        foreach ($filas as $r) {
            $fecha = substr((string) $r->payment_date, 0, 10);
            if (isset($vigente[$fecha]) && (int) $r->report_upload_id !== $vigente[$fecha]) {
                continue;
            }
            $fila = [
                'ref' => (string) $r->id,
                'oficina' => $r->office_name_raw, 'promotor' => $r->promoter_name, 'contrato' => $r->contract, 'acreditado' => $r->acreditado,
                'operacion' => $r->operation, 'transaccion' => $r->transaction, 'fecha_transaccion' => $r->payment_date,
                'fecha_cuota' => $r->installment_due_date, 'fecha_vencimiento' => $r->contract_due_date,
                'capital' => $this->f($r->capital), 'interes' => $this->f($r->interest), 'impuesto' => $this->f($r->tax),
                'cargos_calendario' => $this->f($r->charges), 'producto' => $r->product_name_raw ?? $r->product_name,
                'concepto' => $r->concept, 'numero_cuota' => $r->installment_number,
            ];
            $hist[] = $fila;
            if ($fecha >= $w['inicio'] && $fecha <= $w['fin']) {
                $corte[] = $fila;
            }
        }

        return [$corte, $hist, $solapadas];
    }

    /** Una columna "existe" si al menos un movimiento trae dato (equivale a encabezado presente). */
    private function columnasIngresos(array $filas): array
    {
        $cols = [];
        foreach (array_keys(self::INGRESOS_CAMPOS) as $campo) {
            foreach ($filas as $f) {
                if (($f[$campo] ?? null) !== null && $f[$campo] !== '') {
                    $cols[] = $campo;
                    break;
                }
            }
        }
        if (! $filas) {
            return array_keys(self::INGRESOS_CAMPOS); // sin movimientos: los encabezados no influyen
        }
        // Montos: en BD siempre existen (0 cuando el Excel no los traía); la macro solo exige el encabezado.
        foreach (['capital', 'interes', 'impuesto', 'cargos_calendario'] as $monto) {
            if (! in_array($monto, $cols, true)) {
                $cols[] = $monto;
            }
        }

        return $cols;
    }

    /** Límite superior inclusivo de un día (columnas date guardadas con o sin hora, MySQL y SQLite). */
    private function hasta(string $ymd): string
    {
        return $ymd.' 23:59:59';
    }

    private function f(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    private function diaSemana(string $fecha, int $i): string
    {
        $dias = ['Domingo base', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo corte'];

        return $dias[$i] ?? date('l', strtotime($fecha));
    }

    private function ultimoError(ReportUpload $u): ?string
    {
        $log = DB::table('process_runs')->where('report_upload_id', $u->id)->orderByDesc('id')->value('log');

        return $log ? mb_strimwidth((string) LendusCarteraFotoImportService::mensajePublico($log), 0, 300, '…') : 'El procesamiento falló.';
    }
}
