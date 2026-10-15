<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\PeriodEmployeeRosterService;
use App\Services\Roster\RosterMovementEvidenceCollector;
use App\Services\Roster\RosterMovementStatus as S;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Auditoría del roster de empleados por periodo.
 *
 * 18-oct-2026 — `--dry-run` calcula la clasificación nueva de movimientos
 * (alta/baja confirmada, posible baja, no localizado, contradicción, …) EN
 * MEMORIA, sin escribir nada, y muestra de dónde salen las cifras: cargas NOI
 * del periodo, empleados por archivo, fusiones por nombre y estatus del
 * directorio laboral de cada ausencia. Seguro para correr en producción.
 *
 * Cierre 2026-10 — `--diff` muestra, ANTES de reconstruir, qué filas cambiarían
 * de categoría (y por qué) sin tocar nada; las decisiones de conciliación
 * autorizadas viven en period_employee_movement_reviews y nunca se
 * sobrescriben. Siempre se imprime de qué directorio laboral sale la evidencia
 * (corte efectivo, periodo de carga) y cuáles se descartan por ser posteriores.
 */
class AuditRosterPeriodoCommand extends Command
{
    protected $signature = 'reportes:audit-roster-periodo
                                {period_id}
                                {--detail : listar empleado por empleado}
                                {--dry-run : calcular la clasificación nueva en memoria, SIN escribir}
                                {--diff : comparar la clasificación nueva (en memoria) contra el roster guardado, SIN escribir}
                                {--rebuild : reconstruir el roster antes de auditar (ESCRIBE en la BD)}';

    protected $description = 'Auditoría del roster de empleados (NOI normal + fiscal): altas, bajas confirmadas, pendientes de conciliación, origen de las cifras.';

    public function handle(PeriodEmployeeRosterService $rosterService): int
    {
        $period = Period::find($this->argument('period_id'));
        if (! $period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");

            return self::FAILURE;
        }

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA ROSTER DE EMPLEADOS — {$period->label} (ID {$period->id})");
        $this->info('  Fuente de presencia: NOI Nómina + NOI Nómina Fiscal · Vigencia: directorio laboral');
        $this->info('════════════════════════════════════════════════════════════');

        $this->printNoiOrigin($period);
        $this->printDirectoryEvidence($period);

        if ($this->option('diff')) {
            $this->printDiff($period, $rosterService->computeForPeriod($period));

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $result = $rosterService->computeForPeriod($period);
            $this->line('');
            $this->info('════ CLASIFICACIÓN NUEVA (en memoria, nada se escribió) ════');
            $this->printClassification(collect($result['rows'])->map(fn ($r) => (object) $r), $result['stats']);

            return self::SUCCESS;
        }

        if ($this->option('rebuild')) {
            $stats = $rosterService->buildForPeriod($period);
            $this->line('  Roster reconstruido: '.json_encode($stats, JSON_UNESCAPED_UNICODE));
        }

        $rows = DB::table('period_employee_rosters')->where('period_id', $period->id)->orderBy('nombre_normalizado')->get();
        if ($rows->isEmpty()) {
            $this->warn('  Sin roster construido para este periodo. Usa --dry-run (sin escribir) o --rebuild.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->info('════ ROSTER PERSISTIDO ════');
        $this->printClassification($rows, null);

        $prevPeriod = $period->previousMonthly(Period::all());
        if ($prevPeriod) {
            $prevCount = DB::table('period_employee_rosters')->where('period_id', $prevPeriod->id)->where('is_active_for_period', true)->count();
            $this->line("  Periodo anterior: {$prevPeriod->label} (ID {$prevPeriod->id}) — plantilla NOI: {$prevCount}");
            if ($prevCount === 0) {
                $this->warn('  ⚠ El periodo anterior no tiene roster: no hay base para altas/bajas (se registra como plantilla inicial).');
            }
        }

        return self::SUCCESS;
    }

    /** Origen de las cifras: cargas NOI, empleados por archivo y fusiones por nombre. */
    private function printNoiOrigin(Period $period): void
    {
        $weekly = $period->resolveBaseWeeklyIds(Period::all());
        $dataIds = array_values(array_unique(array_merge($weekly, [$period->id])));

        $uploads = DB::table('fact_noi_movements as f')
            ->join('report_uploads as u', 'u.id', '=', 'f.report_upload_id')
            ->join('data_sources as ds', 'ds.id', '=', 'u.data_source_id')
            ->whereIn('f.period_id', $dataIds)
            ->whereIn('ds.code', ['noi_nomina', 'noi_nomina_fiscal'])
            ->groupBy('u.id', 'ds.code', 'u.original_name', 'u.covered_period_ids', 'f.period_id')
            ->selectRaw('u.id, ds.code, u.original_name, u.covered_period_ids, f.period_id, COUNT(*) as filas, COUNT(DISTINCT f.employee_id) as empleados, MIN(f.movement_date) as desde, MAX(f.movement_date) as hasta')
            ->get();

        $this->line('');
        $this->info('════ ORIGEN NOI (fact_noi_movements del periodo) ════');
        $this->line('  period_ids consultados: '.implode(', ', $dataIds));
        if ($uploads->isEmpty()) {
            $this->warn('  ⚠ No hay movimientos NOI para este periodo.');

            return;
        }
        $this->table(['Carga', 'Fuente', 'Archivo', 'covered_period_ids', 'period_id filas', 'Filas', 'Empleados', 'Desde', 'Hasta'],
            $uploads->map(fn ($u) => [$u->id, $u->code, $u->original_name, $u->covered_period_ids, $u->period_id, $u->filas, $u->empleados, $u->desde, $u->hasta])->all());

        $emp = DB::table('fact_noi_movements as f')
            ->join('employees as e', 'e.id', '=', 'f.employee_id')
            ->whereIn('f.period_id', $dataIds)
            ->selectRaw('COUNT(DISTINCT f.employee_id) as ids, COUNT(DISTINCT e.normalized_name) as nombres')
            ->first();
        $this->line(sprintf('  employee_id distintos: %d · nombres normalizados distintos: %d (fusionados por nombre: %d)',
            $emp->ids, $emp->nombres, $emp->ids - $emp->nombres));

        $otherPeriods = DB::table('report_uploads as u')
            ->join('data_sources as ds', 'ds.id', '=', 'u.data_source_id')
            ->whereIn('ds.code', ['noi_nomina', 'noi_nomina_fiscal'])
            ->whereIn('u.id', $uploads->pluck('id')->all())
            ->whereNotIn('u.period_id', $dataIds)
            ->count();
        if ($otherPeriods > 0) {
            $this->warn("  ⚠ {$otherPeriods} carga(s) NOI con filas en este periodo pertenecen a OTRO periodo (revisa covered_period_ids: puede mezclar dos meses).");
        }
    }

    private function printClassification($rows, ?array $stats): void
    {
        $active = $rows->where('is_active_for_period', true);
        $resolved = $rows->map(fn ($r) => S::resolve($r->reconciliation_status ?? null, $r->movement_type, (bool) $r->is_active_for_period));

        $this->table(['Métrica', 'Valor'], [
            ['Plantilla NOI del periodo', $active->count()],
            ['  en ambos NOI / solo normal / solo fiscal', sprintf('%d / %d / %d',
                $active->where('source', 'ambos')->count(), $active->where('source', 'noi_nomina')->count(), $active->where('source', 'noi_nomina_fiscal')->count())],
            ['Con sucursal / sin sucursal', sprintf('%d / %d', $active->whereNotNull('branch_id')->count(), $active->whereNull('branch_id')->count())],
            ['Altas (movement_type=alta, lo que usa Rotación)', $rows->where('movement_type', 'alta')->count()],
            ['  altas confirmadas / reingresos', sprintf('%d / %d', $resolved->filter(fn ($s) => $s === S::ALTA_CONFIRMADA)->count(), $resolved->filter(fn ($s) => $s === S::REINGRESO)->count())],
            ['Cambios de sucursal', $resolved->filter(fn ($s) => $s === S::CAMBIO_SUCURSAL)->count()],
            ['Bajas CONFIRMADAS (cuentan en rotación)', $rows->filter(fn ($r) => S::isConfirmedBaja($r->reconciliation_status ?? null, $r->movement_type))->count()],
            ['Ausencias sin confirmar', $rows->where('is_active_for_period', false)->count() - $rows->filter(fn ($r) => S::isConfirmedBaja($r->reconciliation_status ?? null, $r->movement_type))->count()],
            ['Pendientes de conciliación (total)', $resolved->filter(fn ($s) => S::isPending($s))->count()],
            ['Directorio laboral usado', $stats['directorio_laboral'] ?? '—'],
            ['Directorio posterior (solo informativo)', $stats['directorio_posterior'] ?? '—'],
            ['Identidad: fusionados / alias / homónimos separados', $stats ? sprintf('%d / %d / %d', $stats['duplicados_fusionados'] ?? 0, $stats['vinculos_alias'] ?? 0, $stats['homonimos_separados'] ?? 0) : '—'],
        ]);

        $this->table(['Categoría', 'Filas'], $resolved->countBy()->sortDesc()->map(fn ($n, $s) => [S::label((string) $s)." ({$s})", $n])->values()->all());

        if ($this->option('detail')) {
            $this->table(['Colaborador', 'Movimiento', 'Categoría', 'Sucursal', 'Motivo'], $rows
                ->filter(fn ($r) => ! $r->is_active_for_period || ($r->movement_type ?? null) === 'alta' || S::isPending(S::resolve($r->reconciliation_status ?? null, $r->movement_type, (bool) $r->is_active_for_period)))
                ->map(fn ($r) => [$r->nombre_original, $r->movement_type, S::resolve($r->reconciliation_status ?? null, $r->movement_type, (bool) $r->is_active_for_period), $r->branch_name ?? '—', mb_strimwidth((string) ($r->reconciliation_note ?? ''), 0, 110, '…')])
                ->values()->all());
        }
    }

    /** De qué directorio laboral sale la evidencia y cuáles se descartan por fecha. */
    private function printDirectoryEvidence(Period $period): void
    {
        $evidence = app(RosterMovementEvidenceCollector::class)->directoryEvidenceFor($period);
        $this->line('');
        $this->info('════ DIRECTORIO LABORAL (evidencia de vigencia) ════');
        if ($evidence['timeline'] === []) {
            $this->warn('  No hay directorio laboral cargado: ninguna ausencia puede confirmarse como baja por directorio.');

            return;
        }
        $limit = $period->end_date?->copy()->addDays(RosterMovementEvidenceCollector::DIRECTORY_GRACE_DAYS)->toDateString();
        $this->table(['Carga', 'Archivo', 'Cargado en periodo', 'Ingreso más reciente', 'Corte efectivo', 'Uso para este periodo'],
            collect($evidence['timeline'])->map(function ($d) use ($evidence, $limit) {
                $use = match (true) {
                    ($evidence['valid']['upload_id'] ?? null) === $d['upload_id'] => strtoupper($evidence['valid']['kind']).' (válido)',
                    $d['effective_cut'] !== null && $d['effective_cut'] > $limit => 'POSTERIOR: no confirma bajas',
                    default => 'no usado',
                };

                return [$d['upload_id'], $d['file'], $d['declared_period'] ?? '—', $d['max_hire_date'] ?? '—', $d['effective_cut'] ?? '—',
                    $use.($d['content_after_declared'] ? ' · contenido posterior a su periodo' : '')];
            })->all());
        $this->line(sprintf('  Ventana válida: %s a %s (fin del periodo + %d días de cierre).', $period->start_date?->toDateString(), $limit, RosterMovementEvidenceCollector::DIRECTORY_GRACE_DAYS));
    }

    /** Diferencias entre el roster guardado y la clasificación nueva, sin escribir. */
    private function printDiff(Period $period, array $result): void
    {
        $persisted = DB::table('period_employee_rosters')->where('period_id', $period->id)->get()
            ->keyBy(fn ($r) => (string) ($r->employee_key ?: $r->nombre_normalizado));
        $new = collect($result['rows'])->map(fn ($r) => (object) $r)->keyBy(fn ($r) => (string) $r->employee_key);
        $label = fn ($r) => $r ? S::resolve($r->reconciliation_status ?? null, $r->movement_type, (bool) $r->is_active_for_period) : '—';

        $this->line('');
        $this->info('════ DIFERENCIAS: roster guardado → clasificación nueva (nada se escribió) ════');
        if ($persisted->isEmpty()) {
            $this->warn('  No hay roster guardado: todo sería nuevo. Usa --dry-run para ver la clasificación.');
        }

        $changes = [];
        foreach ($persisted->keys()->merge($new->keys())->unique() as $key) {
            $old = $persisted->get($key);
            $now = $new->get($key);
            $before = $label($old);
            $after = $label($now);
            if ($before === $after && ($old?->movement_type ?? null) === ($now?->movement_type ?? null)) {
                continue;
            }
            $changes[] = [($now ?? $old)->nombre_original, $before.' / '.($old?->movement_type ?? '—'), $after.' / '.($now?->movement_type ?? '—'),
                mb_strimwidth((string) ($now?->reconciliation_note ?? 'Ya no forma parte del roster del periodo.'), 0, 110, '…')];
        }

        $summary = fn ($c) => [
            'Plantilla NOI' => $c->where('is_active_for_period', true)->count(),
            'Altas (movement_type=alta)' => $c->where('movement_type', 'alta')->count(),
            'Bajas confirmadas' => $c->filter(fn ($r) => S::isConfirmedBaja($label($r), $r->movement_type))->count(),
            'Pendientes de conciliación' => $c->filter(fn ($r) => S::isPending($label($r)))->count(),
        ];
        $a = $summary($persisted);
        $b = $summary($new);
        $this->table(['Cifra', 'Guardado', 'Nuevo', 'Diferencia'], collect($a)->map(fn ($v, $k) => [$k, $v, $b[$k], sprintf('%+d', $b[$k] - $v)])->values()->all());

        if ($changes === []) {
            $this->info('  Sin cambios de categoría.');
        } else {
            $this->table(['Colaborador', 'Antes (categoría / movimiento)', 'Después', 'Motivo nuevo'], $changes);
        }

        $reviews = app(RosterMovementEvidenceCollector::class)->latestReviews($period);
        if ($reviews !== []) {
            $this->line('  Conciliaciones autorizadas del periodo (se respetan siempre, nunca se sobrescriben): '.count($reviews));
            foreach ($reviews as $key => $r) {
                $row = $new->get($key);
                if ($row === null || $row->is_active_for_period) {
                    $this->warn("  ⚠ La decisión sobre {$key} ya no aplica: la persona ".($row ? 'aparece en la nómina del periodo' : 'no está en el roster nuevo').'. Revísala.');
                }
            }
        }
        $this->line('  Para aplicar: reportes:audit-roster-periodo '.$period->id.' --rebuild (o "Actualizar BD" del periodo).');
    }
}
