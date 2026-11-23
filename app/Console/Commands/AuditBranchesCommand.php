<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Services\BranchResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Comando `reportes:audit-branches` — clasifica cada registro de `branches` como
 * SUCURSAL_OFICIAL, EXCLUIDA (CORPORATIVO), RUTA_ALIAS (una ruta que mapea a una sucursal
 * oficial) o DESCONOCIDA, y muestra cuántas filas de hechos cuelgan de cada una
 * (cobranzas, colocaciones, cartera, gastos y asignaciones de empleados).
 *
 * Cuándo usarlo: antes de `reportes:cleanup-branches` / `reportes:branches --cleanup` para
 * ver qué rutas alias quedan en el catálogo.
 * Escribe en BD: NO — solo lectura.
 *
 * Ejemplos:
 *   php artisan reportes:audit-branches
 *   php artisan reportes:audit-branches --json
 */
class AuditBranchesCommand extends Command
{
    protected $signature = 'reportes:audit-branches {--json : Output as JSON}';

    protected $description = 'Classify every branch record as SUCURSAL_OFICIAL / EXCLUIDA / RUTA_ALIAS / DESCONOCIDA and show fact-table row counts.';

    // Only CORPORATIVO is always excluded from operations — SJR is now an official branch.
    private const EXCLUDED = ['CORPORATIVO'];

    private BranchResolverService $resolver;

    /**
     * @param  BranchResolverService  $resolver  Servicio que conoce las sucursales operativas oficiales
     *                                           y resuelve una ruta a su sucursal real.
     */
    public function __construct(BranchResolverService $resolver)
    {
        parent::__construct();
        $this->resolver = $resolver;
    }

    /**
     * Recorre todas las sucursales, las clasifica, calcula el conteo de filas de hechos por
     * sucursal e imprime una tabla (o JSON con `--json`) más un resumen por clasificación. Si hay
     * rutas alias, sugiere ejecutar el plan de limpieza.
     *
     * @return int self::SUCCESS siempre.
     */
    public function handle(): int
    {
        $branches = Branch::query()->orderBy('name')->get();

        $operative = collect($this->resolver->operativeFinancialBranches())
            ->map(fn ($n) => $this->normalize($n))
            ->flip();

        $rows = [];
        $totals = ['SUCURSAL_OFICIAL' => 0, 'EXCLUIDA' => 0, 'RUTA_ALIAS' => 0, 'DESCONOCIDA' => 0];

        foreach ($branches as $branch) {
            $normName = $this->normalize($branch->name);

            $classification = $this->classify($branch->name, $normName, $operative);
            $mapsTo = null;

            if ($classification === 'RUTA_ALIAS') {
                $mapsTo = $this->resolver->resolveRealBranchFromRoute($branch->name);
            }

            $counts = $this->factCounts($branch->id);
            $total = array_sum($counts);

            $rows[] = [
                'id' => $branch->id,
                'name' => $branch->name,
                'active' => $branch->is_active ? 'yes' : 'no',
                'classification' => $classification,
                'maps_to' => $mapsTo ?? '—',
                'recoveries' => $counts['recoveries'],
                'placements' => $counts['placements'],
                'portfolios' => $counts['portfolios'],
                'expenses' => $counts['expenses'],
                'assignments' => $counts['assignments'],
                'total_rows' => $total,
            ];

            $totals[$classification]++;
        }

        if ($this->option('json')) {
            $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Nombre', 'Activa', 'Clasificación', 'Mapea a', 'Cobranzas', 'Colocs.', 'Cartera', 'Gastos', 'Asig.', 'Total filas'],
            array_map(fn ($r) => array_values($r), $rows),
        );

        $this->newLine();
        $this->info('Resumen:');
        foreach ($totals as $class => $count) {
            $this->line("  {$class}: {$count}");
        }

        $aliases = array_filter($rows, fn ($r) => $r['classification'] === 'RUTA_ALIAS');
        if ($aliases) {
            $this->newLine();
            $this->warn(count($aliases).' ruta(s) alias encontrada(s). Ejecuta `reportes:cleanup-branches --dry-run` para ver el plan de limpieza.');
        }

        return self::SUCCESS;
    }

    /**
     * Determina la clasificación de una sucursal: oficial si está en el set operativo, EXCLUIDA si
     * coincide con la lista EXCLUDED, RUTA_ALIAS si el resolvedor la mapea a una sucursal oficial
     * y DESCONOCIDA en otro caso.
     *
     * @param  string  $name  Nombre original de la sucursal.
     * @param  string  $normName  Nombre normalizado (minúsculas, sin acentos).
     * @param  Collection  $operative  Set (flip) de nombres operativos normalizados.
     * @return string SUCURSAL_OFICIAL | EXCLUIDA | RUTA_ALIAS | DESCONOCIDA.
     */
    private function classify(string $name, string $normName, Collection $operative): string
    {
        if (isset($operative[$normName])) {
            return 'SUCURSAL_OFICIAL';
        }

        foreach (self::EXCLUDED as $excl) {
            if ($this->normalize($excl) === $normName) {
                return 'EXCLUIDA';
            }
        }

        $resolved = $this->resolver->resolveRealBranchFromRoute($name);
        if ($resolved && isset($operative[$this->normalize($resolved)])) {
            return 'RUTA_ALIAS';
        }

        return 'DESCONOCIDA';
    }

    /**
     * Cuenta las filas que referencian la sucursal en las tablas de hechos principales.
     *
     * @param  int  $branchId  ID de la sucursal.
     * @return array<string,int> Conteos con llaves recoveries, placements, portfolios, expenses y assignments.
     */
    private function factCounts(int $branchId): array
    {
        return [
            'recoveries' => (int) DB::table('fact_recoveries')->where('branch_id', $branchId)->count(),
            'placements' => (int) DB::table('fact_placements')->where('branch_id', $branchId)->count(),
            'portfolios' => (int) DB::table('fact_portfolios')->where('branch_id', $branchId)->count(),
            'expenses' => (int) DB::table('fact_expenses')->where('branch_id', $branchId)->count(),
            'assignments' => (int) DB::table('employee_branch_assignments')->where('branch_id', $branchId)->count(),
        ];
    }

    /**
     * Normaliza un nombre para comparar: recorta, pasa a minúsculas y quita acentos/ñ.
     *
     * @param  string  $value  Texto a normalizar.
     * @return string Texto normalizado.
     */
    private function normalize(string $value): string
    {
        $value = trim(mb_strtolower($value, 'UTF-8'));

        return str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $value,
        );
    }
}
