<?php

namespace App\Services\Api;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeMatchRejection;
use App\Models\Sucursal;
use App\Services\Catalog\BranchRouteCatalog;
use App\Services\EmployeeNameCanonicalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * API v1 — directorio de PERSONAS y SUCURSALES (2026-11-17, Vínculos de MR. LANA PEOPLE).
 * Solo lectura.
 *
 * `employees` NO es un catálogo de personas: la misma persona puede tener varios
 * registros (alta por NOI con clave, alta por Lendus cobranza sin clave, variantes de
 * captura del nombre) y cada uno su ruta. Aquí se agrupan con las MISMAS reglas de
 * identidad que el reporte (RadiographySnapshotBuilder):
 *
 *   1. Nombre normalizado (EmployeeNameCanonicalizer::normalize — sin acentos ni signos).
 *   2. Variantes de captura: EmployeeNameCanonicalizer::buildCanonicalMap (Levenshtein ≤ 2
 *      y ≥ 3 palabras en común), con los nombres de NOI como forma preferente.
 *   3. Homónimos con rechazo de fusión registrado (employee_match_rejections) NUNCA se
 *      juntan: el grupo se separa por clave NOI y los registros sin clave se quedan con la
 *      primera persona (igual que el reporte, «hasta conciliarlas»).
 *
 * Rutas → sucursal: catálogo oficial BranchRouteCatalog (fuente única ruta → sucursal);
 * si una ruta no está en el catálogo se usa la atribución de la BD (branches.sucursal_id)
 * y si tampoco existe queda `sucursal: null` (nunca se adivina).
 *
 * Registros que no son personas («VACANTE ACAMBAY», «HUAMANTLA VENCIDOS», códigos de
 * promotor «ORI09247») se marcan `kind: placeholder` con su motivo, nunca se ocultan.
 *
 * Caché: 6 h, con una llave que cambia cuando cambian empleados, asignaciones, rechazos o
 * branches (un import nuevo la invalida sola).
 */
class PeopleDirectoryService
{
    private const CACHE_TTL = 21_600;

    private const CACHE_VERSION = 'v1';

    public function __construct(private readonly EmployeeNameCanonicalizer $canonicalizer) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function people(): array
    {
        return Cache::remember($this->cacheKey('people'), self::CACHE_TTL, fn () => $this->buildPeople());
    }

    /**
     * Sucursales oficiales con sus rutas (branches activos).
     *
     * @return list<array<string, mixed>>
     */
    public function sucursales(): array
    {
        return Cache::remember($this->cacheKey('sucursales'), self::CACHE_TTL, fn () => $this->buildSucursales());
    }

    /**
     * Sucursal oficial de un branch: catálogo de rutas → BD → null.
     *
     * @return array{code: string, name: string, id: int|null, okr_operative: bool, source: string}|null
     */
    public function sucursalForBranch(Branch $branch): ?array
    {
        $official = BranchRouteCatalog::officialBranchFor($branch->name);
        $source = 'catalog';

        // Sucursales históricas (San Juan del Río…) no tienen rutas en el catálogo:
        // el branch con ese nombre ES la sucursal.
        foreach (BranchRouteCatalog::HISTORICAL_FINANCIAL_BRANCHES as $historical) {
            if ($official === null && BranchRouteCatalog::normalize($historical) === BranchRouteCatalog::normalize($branch->name)) {
                $official = $historical;
            }
        }

        if ($official === null && $branch->sucursal !== null) {
            $official = $branch->sucursal->nombre;
            $source = 'database';
        }

        if ($official === null) {
            return null;
        }

        $normalized = BranchRouteCatalog::normalize($official);

        return [
            'code' => str_replace(' ', '_', $normalized),
            'name' => $official,
            'id' => $this->dbSucursalIds()[$normalized] ?? null,
            'okr_operative' => BranchRouteCatalog::isOkrOperative($official),
            'source' => $source,
        ];
    }

    /** Motivo por el que un registro NO es una persona, o null si lo es. */
    public static function placeholderReason(string $fullName): ?string
    {
        $name = BranchRouteCatalog::normalize($fullName);

        return match (true) {
            $name === '' => 'sin_nombre',
            (bool) preg_match('/\bVACANTES?\b/', $name) => 'vacante',
            (bool) preg_match('/\bVENCID[OA]S?\b/', $name) => 'cartera_vencida',
            (bool) preg_match('/^[A-Z]{2,4}-?\d{4,}$/', $name) => 'codigo_de_promotor',
            (bool) preg_match('/\b(SIN ASIGNAR|SIN GESTOR|OFICINA|CORPORATIVO)\b/', $name) => 'cuenta_operativa',
            count(array_filter(explode(' ', $name), fn (string $t) => mb_strlen($t) >= 2)) < 2 => 'nombre_incompleto',
            default => null,
        };
    }

    private function cacheKey(string $what): string
    {
        $stamp = implode('|', [
            Employee::query()->count(),
            (string) Employee::query()->max('updated_at'),
            (string) DB::table('employee_branch_assignments')->max('id'),
            (string) DB::table('employee_branch_assignments')->max('updated_at'),
            (string) EmployeeMatchRejection::query()->max('id'),
            (string) Branch::query()->max('updated_at'),
            Branch::query()->count(),
        ]);

        return sprintf('api_v1_people_directory:%s:%s:%s', self::CACHE_VERSION, $what, md5($stamp));
    }

    /** @var array<string, int>|null */
    private ?array $dbSucursalIdsMemo = null;

    /** @return array<string, int> nombre normalizado → sucursales.id */
    private function dbSucursalIds(): array
    {
        if ($this->dbSucursalIdsMemo !== null) {
            return $this->dbSucursalIdsMemo;
        }

        $ids = [];
        foreach (Sucursal::query()->get(['id', 'nombre']) as $sucursal) {
            $ids[BranchRouteCatalog::normalize($sucursal->nombre)] = (int) $sucursal->id;
        }

        return $this->dbSucursalIdsMemo = $ids;
    }

    /**
     * @return array<int, array{id: int, code: string|null, name: string, active: bool, sucursal: array<string, mixed>|null}>
     */
    private function branchesById(): array
    {
        $result = [];
        foreach (Branch::query()->with('sucursal')->get() as $branch) {
            $result[(int) $branch->id] = [
                'id' => (int) $branch->id,
                'code' => $branch->code,
                'name' => (string) $branch->name,
                'active' => (bool) $branch->is_active,
                'sucursal' => $this->sucursalForBranch($branch),
            ];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function buildSucursales(): array
    {
        $bySucursal = [];
        $unattributed = [];

        foreach ($this->branchesById() as $branch) {
            if (! $branch['active']) {
                continue;
            }
            $route = ['id' => $branch['id'], 'code' => $branch['code'], 'name' => $branch['name']];

            if ($branch['sucursal'] === null) {
                $unattributed[] = $route;

                continue;
            }

            $code = $branch['sucursal']['code'];
            $bySucursal[$code] ??= [
                'code' => $code,
                'name' => $branch['sucursal']['name'],
                'id' => $branch['sucursal']['id'],
                'okr_operative' => $branch['sucursal']['okr_operative'],
                'routes' => [],
            ];
            $bySucursal[$code]['routes'][] = $route;
        }

        ksort($bySucursal);
        $result = array_values($bySucursal);

        // Rutas sin sucursal en el catálogo ni en la BD: se entregan aparte (auditable).
        if ($unattributed !== []) {
            $result[] = ['code' => null, 'name' => null, 'id' => null, 'okr_operative' => false, 'routes' => $unattributed];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function buildPeople(): array
    {
        $employees = Employee::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'employee_code', 'full_name', 'position', 'source_system']);

        // ── 1) Llave por nombre normalizado + fusión de variantes (misma regla que el reporte).
        $normById = [];
        $noiNames = [];
        foreach ($employees as $employee) {
            $norm = $this->canonicalizer->normalize((string) $employee->full_name);
            $normById[(int) $employee->id] = $norm;
            if ($employee->source_system === 'noi') {
                $noiNames[] = $norm;
            }
        }
        $uniqueNorms = array_values(array_unique(array_filter($normById, fn (string $n) => $n !== '')));
        $mergeMap = $this->canonicalizer->buildCanonicalMap($uniqueNorms, $noiNames);

        $groups = [];
        foreach ($employees as $employee) {
            $norm = $normById[(int) $employee->id];
            $key = $norm === '' ? sprintf('#%d', $employee->id) : ($mergeMap[$norm] ?? $norm);
            $groups[$key][] = $employee;
        }

        // ── 2) Homónimos con rechazo de fusión: separar por clave NOI.
        $rejected = [];
        foreach (EmployeeMatchRejection::query()->get(['employee_id_a', 'employee_id_b']) as $r) {
            $rejected[(int) $r->employee_id_a][(int) $r->employee_id_b] = true;
            $rejected[(int) $r->employee_id_b][(int) $r->employee_id_a] = true;
        }

        $persons = [];
        foreach ($groups as $members) {
            foreach ($this->splitHomonyms($members, $rejected) as $subgroup) {
                $persons[] = $subgroup;
            }
        }

        // ── 3) Rutas (asignaciones) de cada registro, de la más reciente a la más antigua.
        $branches = $this->branchesById();
        $assignments = DB::table('employee_branch_assignments as a')
            ->leftJoin('periods as p', 'p.id', '=', 'a.period_id')
            ->whereIn('a.employee_id', $employees->pluck('id')->all())
            ->orderByDesc('p.start_date')
            ->orderByDesc('a.id')
            ->get(['a.employee_id', 'a.branch_id', 'a.period_id', 'p.start_date']);

        $routesByEmployee = [];
        foreach ($assignments as $a) {
            if ($a->branch_id !== null) {
                $routesByEmployee[(int) $a->employee_id][] = ['branch_id' => (int) $a->branch_id, 'start' => (string) ($a->start_date ?? '')];
            }
        }

        $result = [];
        foreach ($persons as $members) {
            $result[] = $this->personRow($members, $routesByEmployee, $branches);
        }

        usort($result, fn (array $a, array $b) => strcmp((string) $a['full_name'], (string) $b['full_name']) ?: $a['id'] <=> $b['id']);

        return $result;
    }

    /**
     * @param  list<Employee>  $members
     * @param  array<int, array<int, bool>>  $rejected
     * @return list<list<Employee>>
     */
    private function splitHomonyms(array $members, array $rejected): array
    {
        $ids = array_map(fn (Employee $e) => (int) $e->id, $members);
        $hasRejection = false;
        foreach ($ids as $id) {
            if (isset($rejected[$id]) && array_intersect(array_keys($rejected[$id]), $ids) !== []) {
                $hasRejection = true;
                break;
            }
        }

        if (! $hasRejection) {
            return [$members];
        }

        $byCode = [];
        $withoutCode = [];
        foreach ($members as $member) {
            $code = trim((string) $member->employee_code);
            if ($code === '') {
                $withoutCode[] = $member;
            } else {
                $byCode[$code][] = $member;
            }
        }

        if ($byCode === []) {
            return array_map(fn (Employee $e) => [$e], $members);
        }

        $subgroups = array_values($byCode);
        $subgroups[0] = [...$subgroups[0], ...$withoutCode];

        return $subgroups;
    }

    /**
     * @param  list<Employee>  $members
     * @param  array<int, list<array{branch_id: int, start: string}>>  $routesByEmployee
     * @param  array<int, array{id: int, code: string|null, name: string, active: bool, sucursal: array<string, mixed>|null}>  $branches
     * @return array<string, mixed>
     */
    private function personRow(array $members, array $routesByEmployee, array $branches): array
    {
        // Registro principal: el de NOI con clave (más antiguo); si no hay, el más antiguo.
        usort($members, function (Employee $a, Employee $b) {
            $rank = fn (Employee $e) => ($e->source_system === 'noi' ? 0 : 2) + (trim((string) $e->employee_code) === '' ? 1 : 0);

            return $rank($a) <=> $rank($b) ?: (int) $a->id <=> (int) $b->id;
        });
        $primary = $members[0];
        $ids = array_map(fn (Employee $e) => (int) $e->id, $members);
        sort($ids);

        // Rutas de todos sus registros: la más reciente primero, sin repetir.
        $events = [];
        foreach ($ids as $id) {
            foreach ($routesByEmployee[$id] ?? [] as $event) {
                $events[] = $event;
            }
        }
        usort($events, fn (array $a, array $b) => strcmp($b['start'], $a['start']));

        $routes = [];
        foreach ($events as $event) {
            $branch = $branches[$event['branch_id']] ?? null;
            if ($branch === null || isset($routes[$branch['id']])) {
                continue;
            }
            $routes[$branch['id']] = [
                'id' => $branch['id'],
                'code' => $branch['code'],
                'name' => $branch['name'],
                'sucursal_code' => $branch['sucursal']['code'] ?? null,
            ];
        }
        $routes = array_values($routes);
        $current = $routes[0] ?? null;
        $sucursal = $current === null ? null : ($branches[$current['id']]['sucursal'] ?? null);

        $codes = array_values(array_unique(array_filter(array_map(fn (Employee $e) => trim((string) $e->employee_code), $members), fn (string $c) => $c !== '')));
        $variants = array_values(array_unique(array_map(fn (Employee $e) => mb_strtoupper(trim((string) $e->full_name)), $members)));
        $reason = self::placeholderReason((string) $primary->full_name);

        return [
            'id' => (int) $primary->id,
            'employee_ids' => $ids,
            'full_name' => mb_strtoupper(trim((string) $primary->full_name)),
            'name_variants' => $variants,
            'employee_codes' => $codes,
            'position' => $primary->position,
            'kind' => $reason === null ? 'person' : 'placeholder',
            'placeholder_reason' => $reason,
            'current_branch_id' => $current['id'] ?? null,
            'sucursal' => $sucursal === null ? null : [
                'code' => $sucursal['code'],
                'name' => $sucursal['name'],
                'id' => $sucursal['id'],
            ],
            'routes' => $routes,
        ];
    }
}
