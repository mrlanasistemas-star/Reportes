<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\BranchResolverService;
use App\Services\Reporting\OperativeBranchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnóstico y limpieza SEGURA de filas de `branches` que no son ninguna de
 * las 13 sucursales operativas (ni una de las regiones reconocidas:
 * Aguascalientes, Chihuahua, Durango, Pachuca, Tulancingo, Corporativo).
 *
 * Por qué puede existir esto: los importadores actuales (LendusCarteraFotoImportService,
 * LendusIngresosCobranzaImportService, LendusSaldosClienteImportService) YA usan
 * BranchResolverService::resolveOfficialBranchByName()/resolveRealBranchFromRoute()
 * y NUNCA crean una fila nueva para una ruta desconocida — se verificó leyendo su
 * código real. Si existen filas como "ATLIX-GTE" en un entorno, son HERENCIA de
 * datos/código anterior a ese resolver, no un bug que este comando repita.
 *
 * Por defecto SOLO DIAGNOSTICA (no modifica nada):
 *  - Lista cada sucursal "extra", cuántas filas la referencian en cada tabla
 *    financiera/operativa real (fact_portfolios, fact_recoveries, etc.), y a
 *    qué sucursal real resolvería su nombre según BranchResolverService.
 *
 * Con `--deactivate-unresolved`:
 *  - SOLO desactiva (is_active=false) las filas que el resolver NO puede mapear
 *    a ninguna sucursal real conocida. Nunca borra la fila, nunca toca sus
 *    referencias históricas (quedan disponibles para auditoría). Deja de
 *    aparecer en los selectores (que ya filtran por sucursal operativa), sin
 *    adivinar una atribución que no está demostrada.
 *
 * La REASIGNACIÓN de las filas ya referenciadas (fact_portfolios.branch_id,
 * fact_recoveries.branch_id, etc.) hacia la sucursal real resuelta es un paso
 * deliberadamente MANUAL: afecta datos financieros reales en 16 tablas con
 * reglas de unicidad distintas y debe verificarse contra los datos reales del
 * entorno donde aparezca el problema antes de aplicarse — este comando nunca
 * la ejecuta automáticamente.
 */
class AuditNonOperativeBranchesCommand extends Command
{
    protected $signature = 'branches:audit-non-operative
        {--deactivate-unresolved : Desactiva (is_active=false) las filas sin mapeo conocido. Nunca borra ni reasigna referencias.}';

    protected $description = 'Diagnostica sucursales que no son una de las 13 operativas (p.ej. rutas de Lendus heredadas) y, opcionalmente, desactiva las que no tienen mapeo conocido.';

    /** Tablas reales con branch_id — mismas que Schema::getTables() reporta hoy. */
    private const REFERENCING_TABLES = [
        'employee_branch_assignments', 'fact_placements', 'fact_recoveries', 'fact_portfolios',
        'fact_expenses', 'fact_period_employee_summary', 'fact_imss', 'fact_rotacion',
        'fact_portfolio_snapshot_rows', 'okr_objectives', 'period_branch_exclusions',
        'period_branch_summaries', 'period_employee_rosters', 'period_radiography_runs',
        'efectividad_cobranza_runs', 'api_query_logs',
    ];

    /** Regiones reales reconocidas que NO son de las 13 operativas pero sí existen de verdad. */
    private const RECOGNIZED_NON_OPERATIVE = ['AGUASCALIENTES', 'CHIHUAHUA', 'DURANGO', 'PACHUCA', 'TULANCINGO', 'CORPORATIVO'];

    public function handle(BranchResolverService $resolver): int
    {
        $apply = (bool) $this->option('deactivate-unresolved');

        $allowed = array_merge(OperativeBranchService::NAMES, self::RECOGNIZED_NON_OPERATIVE);

        $extra = Branch::query()
            ->whereNotIn('name', $allowed)
            ->orderBy('name')
            ->get();

        if ($extra->isEmpty()) {
            $this->info('No hay sucursales fuera del catálogo reconocido (13 operativas + regiones). Nada que revisar.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($extra as $branch) {
            $resolved = $resolver->resolveRealBranchFromRoute($branch->name);
            $refCount = $this->referenceCount($branch->id);

            $rows[] = [
                $branch->id,
                $branch->name,
                $branch->is_active ? 'activa' : 'inactiva',
                $refCount,
                $resolved ?? '— sin mapeo conocido —',
            ];

            if ($apply && ! $resolved) {
                $branch->update(['is_active' => false]);
            }
        }

        $this->table(['ID', 'Nombre', 'Estado', 'Filas que la referencian', 'Resolvería a (BranchResolverService)'], $rows);

        $resolvable = $extra->filter(fn (Branch $b) => $resolver->resolveRealBranchFromRoute($b->name) !== null);
        $unresolved = $extra->count() - $resolvable->count();

        if ($resolvable->isNotEmpty()) {
            $this->warn(sprintf(
                '%d sucursal(es) tienen un mapeo conocido pero NO se reasignaron automáticamente: reasignar sus %d referencia(s) en las %d tablas reales requiere verificación manual contra los datos de este entorno (reglas de unicidad distintas por tabla). Usa este diagnóstico para decidir caso por caso.',
                $resolvable->count(),
                $resolvable->sum(fn (Branch $b) => $this->referenceCount($b->id)),
                count(self::REFERENCING_TABLES),
            ));
        }

        if ($unresolved > 0) {
            $this->line($apply
                ? "{$unresolved} sucursal(es) sin mapeo conocido se desactivaron (is_active=false) — dejan de ofrecerse en los selectores; sus referencias históricas NO se tocaron."
                : "{$unresolved} sucursal(es) sin mapeo conocido se desactivarían con --deactivate-unresolved.");
        }

        return self::SUCCESS;
    }

    private function referenceCount(int $branchId): int
    {
        $total = 0;
        foreach (self::REFERENCING_TABLES as $table) {
            $total += DB::table($table)->where('branch_id', $branchId)->count();
        }

        return $total;
    }
}
