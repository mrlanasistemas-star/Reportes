<?php

namespace App\Console\Commands;

use App\Models\Period;
use App\Services\RotacionDerivedFromNoiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Comando `reportes:audit-rotacion-derivada` — compara la rotación derivada de NOI
 * (`fact_rotacion` source = derived_noi, FUENTE OFICIAL) contra el archivo legacy
 * (source = file, solo referencia histórica). Mientras exista derivado para el periodo, el
 * reporte usa el derivado. Índice = bajas / plantilla actual × 100.
 *
 * Opciones:
 *   --rebuild  re-deriva la rotación antes de auditar (ESCRIBE en `fact_rotacion`).
 *   --detail   lista altas/bajas por sucursal desde period_employee_rosters.
 *
 * Escribe en BD: solo con `--rebuild` (RotacionDerivedFromNoiService::deriveForPeriod).
 *
 * Ejemplos:
 *   php artisan reportes:audit-rotacion-derivada 21
 *   php artisan reportes:audit-rotacion-derivada 21 --rebuild --detail
 */
class AuditRotacionDerivadaCommand extends Command
{
    protected $signature = 'reportes:audit-rotacion-derivada
                                {period_id}
                                {--detail : listar empleados de alta/baja por sucursal}
                                {--rebuild : re-derivar antes de auditar}';

    protected $description = 'Auditoría Rotación: compara derivado de NOI (source=derived_noi, fuente oficial) contra archivo legacy (source=file, solo referencia histórica). El derivado manda mientras exista para el periodo.';

    /**
     * Opcionalmente re-deriva, compara legacy vs derivado por sucursal y muestra el resultado que
     * ve el reporte.
     *
     * @param  RotacionDerivedFromNoiService  $service  Servicio que deriva la rotación desde NOI.
     * @return int SUCCESS al terminar; FAILURE si el periodo no existe.
     */
    public function handle(RotacionDerivedFromNoiService $service): int
    {
        $period = Period::find($this->argument('period_id'));
        if (! $period) {
            $this->error("Periodo {$this->argument('period_id')} no encontrado.");

            return self::FAILURE;
        }

        $this->line('');
        $this->info('════════════════════════════════════════════════════════════');
        $this->info("  AUDITORÍA ROTACIÓN — {$period->label} (ID {$period->id})");
        $this->info('  Fórmula: Índice = bajas / plantilla actual × 100');
        $this->info('════════════════════════════════════════════════════════════');

        if ($this->option('rebuild')) {
            $res = $service->deriveForPeriod($period);
            $this->line('  Derivado recalculado: '.json_encode($res));
        }

        $legacyRows = DB::table('fact_rotacion')->where('period_id', $period->id)->where('source', 'file')->orderBy('sucursal_nombre')->get()->keyBy('sucursal_nombre');
        $derivedRows = DB::table('fact_rotacion')->where('period_id', $period->id)->where('source', 'derived_noi')->orderBy('sucursal_nombre')->get()->keyBy('sucursal_nombre');

        if ($legacyRows->isEmpty() && $derivedRows->isEmpty()) {
            $this->warn('  Sin datos en fact_rotacion para este periodo (ni legacy ni derivado). Ejecuta con --rebuild.');

            return self::SUCCESS;
        }

        $sucursales = $legacyRows->keys()->merge($derivedRows->keys())->unique()->sort()->values();

        $tableRows = [];
        foreach ($sucursales as $suc) {
            $l = $legacyRows->get($suc);
            $d = $derivedRows->get($suc);
            $diffAltas = ($d->altas ?? 0) - ($l->altas ?? 0);
            $diffBajas = ($d->bajas ?? 0) - ($l->bajas ?? 0);
            $diffPlant = (int) ($d->promedio_personal ?? 0) - (int) ($l->promedio_personal ?? 0);
            $accion = $d ? 'Usa DERIVADO (NOI, oficial)' : 'Usa LEGACY (fallback histórico, sin derivado)';

            $tableRows[] = [
                $suc,
                $l->altas ?? '—', $d->altas ?? '—',
                $l->bajas ?? '—', $d->bajas ?? '—',
                $l ? (int) $l->promedio_personal : '—', $d ? (int) $d->promedio_personal : '—',
                ($diffAltas || $diffBajas || $diffPlant) ? "altas:{$diffAltas} bajas:{$diffBajas} plant:{$diffPlant}" : 'OK',
                $accion,
            ];
        }

        $this->table(
            ['Sucursal', 'Altas val.', 'Altas der.', 'Bajas val.', 'Bajas der.', 'Plant. val.', 'Plant. der.', 'Diferencia', 'Acción aplicada'],
            $tableRows
        );

        $legacySum = ['altas' => (int) $legacyRows->sum('altas'), 'bajas' => (int) $legacyRows->sum('bajas'), 'plant' => (int) $legacyRows->sum('promedio_personal')];
        $derivedSum = ['altas' => (int) $derivedRows->sum('altas'), 'bajas' => (int) $derivedRows->sum('bajas'), 'plant' => (int) $derivedRows->sum('promedio_personal')];

        $usaDerivado = $derivedRows->isNotEmpty();
        $activo = $usaDerivado ? $derivedSum : $legacySum;
        $indiceGlobal = $activo['plant'] > 0 ? round($activo['bajas'] / $activo['plant'] * 100, 2) : 0.0;

        $this->line('');
        $this->info('════ RESULTADO QUE VE EL REPORTE ('.($usaDerivado ? 'DERIVADO NOI — oficial' : 'LEGACY — fallback histórico').') ════');
        $this->line("  Altas: {$activo['altas']}  |  Bajas: {$activo['bajas']}  |  Plantilla: {$activo['plant']}  |  Índice: {$indiceGlobal}%");

        if ($usaDerivado && $legacyRows->isNotEmpty()) {
            $matches = $legacySum === $derivedSum;
            $this->line('');
            $this->line('  Comparación DERIVADO vs LEGACY (informativa — el archivo manual ya NO sustituye al derivado):');
            $this->line("    Derivado -> Altas {$derivedSum['altas']} · Bajas {$derivedSum['bajas']} · Plantilla {$derivedSum['plant']} (oficial)");
            $this->line("    Legacy   -> Altas {$legacySum['altas']} · Bajas {$legacySum['bajas']} · Plantilla {$legacySum['plant']} (referencia histórica)");
            $this->line($matches ? '    Estado: COINCIDE ✓' : '    Estado: NO coincide — el legacy estaba mal o usaba otra fuente (--detail para lista de empleados)');
        }

        if (! $usaDerivado) {
            $this->line('');
            $this->warn('  No hay derivado NOI para este periodo (fact_rotacion sin source=derived_noi) — se está usando el archivo LEGACY como fallback histórico. Ejecuta reportes:rebuild-derived-noi '.$period->id.' para derivar desde NOI.');
        }

        if ($this->option('detail')) {
            $branchRows = DB::table('period_employee_rosters')
                ->where('period_id', $period->id)
                ->where('is_branch_operativa', true)
                ->orderBy('branch_name')
                ->get();

            foreach ($branchRows->groupBy('branch_name') as $branchName => $group) {
                $altasG = $group->where('is_active_for_period', true)->where('movement_type', 'alta');
                $bajasG = $group->where('is_active_for_period', false)->where('movement_type', 'baja');
                if ($altasG->isEmpty() && $bajasG->isEmpty()) {
                    continue;
                }

                $this->line('');
                $this->info("  {$branchName} (roster derivado de NOI)");
                foreach ($altasG as $a) {
                    $this->line("    + ALTA: {$a->nombre_normalizado}");
                }
                foreach ($bajasG as $b) {
                    $this->line("    − BAJA: {$b->nombre_normalizado}");
                }
            }
        }

        return self::SUCCESS;
    }
}
