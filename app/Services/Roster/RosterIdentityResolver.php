<?php

namespace App\Services\Roster;

use App\Models\EmployeeMatchRejection;
use App\Services\PersonIdentityResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Identidad laboral del roster (cierre 2026-10): agrupa registros `employees`
 * en PERSONAS usando identificadores, no solo el nombre.
 *
 *   1. Mismo nombre normalizado → misma persona (NOI normal y NOI fiscal de un
 *      mismo empleado, o duplicados técnicos de una carga), SALVO que exista un
 *      rechazo explícito entre ambos registros (employee_match_rejections): lo
 *      registra el importador NOI cuando dos homónimos con CLAVE distinta
 *      aparecen en la misma nómina, o un usuario al conciliar. Homónimos
 *      separados reciben llave propia "nombre#id" y nunca se fusionan.
 *   2. Vínculo conciliado (employee_aliases): un registro cuyo nombre es alias
 *      conciliado de otro empleado del periodo es la MISMA persona (nombre
 *      capturado distinto), salvo rechazo.
 *   3. Sin evidencia de vínculo, dos nombres parecidos NO se fusionan: el
 *      roster los marca como posible duplicado para conciliación.
 *
 * Solo lecturas. La llave devuelta es la que el roster persiste como
 * employee_key (igual al nombre normalizado en el caso normal, así las filas
 * antiguas siguen empatando).
 */
class RosterIdentityResolver
{
    public function __construct(private readonly PersonIdentityResolverService $names) {}

    /**
     * @param  iterable<object{id:int, full_name:?string, normalized_name:?string}>  $employees
     * @return array{groups: array<string, array{employee_ids:int[], name:string, nombre_original:string, links:string[], homonym:bool}>, stats: array{merged_same_name:int, merged_by_alias:int, homonyms_split:int}}
     */
    public function group(iterable $employees): array
    {
        $byName = [];
        $info = [];
        foreach ($employees as $e) {
            $id = (int) $e->id;
            $name = trim((string) ($e->normalized_name ?: $this->names->normalizePersonName($e->full_name ?? '')));
            if ($name === '') {
                $name = 'sin_nombre_'.$id;
            }
            $info[$id] = ['name' => $name, 'full_name' => (string) ($e->full_name ?? $name)];
            $byName[$name][] = $id;
        }
        ksort($byName);

        $allIds = array_keys($info);
        $rejected = $this->rejectedPairs($allIds);
        $isRejected = fn (int $a, int $b) => isset($rejected[EmployeeMatchRejection::pairKey($a, $b)]);

        // 1. Mismo nombre, partido por rechazos explícitos.
        $groups = [];
        $stats = ['merged_same_name' => 0, 'merged_by_alias' => 0, 'homonyms_split' => 0];
        foreach ($byName as $name => $ids) {
            sort($ids);
            $subgroups = [];
            foreach ($ids as $id) {
                $placed = false;
                foreach ($subgroups as $i => $members) {
                    if (! collect($members)->contains(fn ($m) => $isRejected($m, $id))) {
                        $subgroups[$i][] = $id;
                        $stats['merged_same_name']++;
                        $placed = true;
                        break;
                    }
                }
                if (! $placed) {
                    $subgroups[] = [$id];
                }
            }
            $split = count($subgroups) > 1;
            if ($split) {
                $stats['homonyms_split'] += count($subgroups) - 1;
            }
            foreach ($subgroups as $members) {
                $key = $split ? $name.'#'.min($members) : $name;
                $groups[$key] = [
                    'employee_ids' => $members,
                    'name' => $name,
                    'nombre_original' => $info[min($members)]['full_name'],
                    'links' => count($members) > 1 ? ['Mismo nombre en '.count($members).' registros de empleado'] : [],
                    'homonym' => $split,
                ];
            }
        }

        // 2. Vínculos conciliados (alias) entre grupos distintos del periodo.
        foreach ($this->aliasLinks($byName) as [$aliasName, $ownerId]) {
            $from = $this->keyOfName($groups, $aliasName);
            $to = $this->keyOfId($groups, $ownerId);
            if ($from === null || $to === null || $from === $to || $groups[$from]['homonym'] || $groups[$to]['homonym']) {
                continue;
            }
            $conflict = false;
            foreach ($groups[$from]['employee_ids'] as $a) {
                foreach ($groups[$to]['employee_ids'] as $b) {
                    $conflict = $conflict || $isRejected($a, $b);
                }
            }
            if ($conflict) {
                continue;
            }
            $groups[$to]['employee_ids'] = array_values(array_unique(array_merge($groups[$to]['employee_ids'], $groups[$from]['employee_ids'])));
            sort($groups[$to]['employee_ids']);
            $groups[$to]['links'][] = "Vínculo conciliado: \"{$groups[$from]['nombre_original']}\" es alias de \"{$groups[$to]['nombre_original']}\"";
            $stats['merged_by_alias']++;
            unset($groups[$from]);
        }

        return ['groups' => $groups, 'stats' => $stats];
    }

    /** @return array<string, true> */
    private function rejectedPairs(array $ids): array
    {
        if ($ids === [] || ! Schema::hasTable('employee_match_rejections')) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::table('employee_match_rejections')
                ->whereIn('employee_id_a', $chunk)
                ->orWhereIn('employee_id_b', $chunk)
                ->pluck('pair_key')
                ->each(function ($k) use (&$out) {
                    $out[(string) $k] = true;
                });
        }

        return $out;
    }

    /**
     * Pares (nombre alias, employee_id dueño) donde AMBOS lados están en el
     * conjunto evaluado y el alias es un nombre distinto al del dueño.
     *
     * @param  array<string, int[]>  $byName
     * @return array<int, array{0:string, 1:int}>
     */
    private function aliasLinks(array $byName): array
    {
        if (count($byName) < 2 || ! Schema::hasTable('employee_aliases')) {
            return [];
        }
        $ownerIds = array_merge(...array_values($byName));
        $out = [];
        foreach (array_chunk(array_keys($byName), 500) as $chunk) {
            DB::table('employee_aliases')
                ->whereIn('normalized_alias', $chunk)
                ->whereIn('employee_id', $ownerIds)
                ->get(['normalized_alias', 'employee_id'])
                ->each(function ($r) use (&$out, $byName) {
                    $alias = (string) $r->normalized_alias;
                    if (! in_array((int) $r->employee_id, $byName[$alias] ?? [], true)) {
                        $out[] = [$alias, (int) $r->employee_id];
                    }
                });
        }

        return $out;
    }

    private function keyOfName(array $groups, string $name): ?string
    {
        $keys = array_keys(array_filter($groups, fn ($g) => $g['name'] === $name));

        return count($keys) === 1 ? (string) $keys[0] : null;
    }

    private function keyOfId(array $groups, int $id): ?string
    {
        foreach ($groups as $k => $g) {
            if (in_array($id, $g['employee_ids'], true)) {
                return (string) $k;
            }
        }

        return null;
    }

    /**
     * Posibles duplicados SIN vínculo: dos personas del periodo con nombres muy
     * parecidos donde una solo aparece en NOI normal y la otra solo en NOI
     * fiscal. No se fusionan (falta evidencia); se devuelven para conciliar.
     *
     * @param  array<string, array{name:string, normal:bool, fiscal:bool}>  $present
     * @return array<string, array{key:string, nombre:string, percent:float}>
     */
    public function unlinkedNearDuplicates(array $present, array $originals, float $threshold = 90.0): array
    {
        $onlyNormal = array_filter($present, fn ($p) => $p['normal'] && ! $p['fiscal']);
        $onlyFiscal = array_filter($present, fn ($p) => $p['fiscal'] && ! $p['normal']);
        if ($onlyNormal === [] || $onlyFiscal === []) {
            return [];
        }

        $out = [];
        foreach ($onlyFiscal as $fk => $f) {
            $best = null;
            foreach ($onlyNormal as $nk => $n) {
                similar_text($f['name'], $n['name'], $pct);
                if ($pct >= $threshold && ($best === null || $pct > $best[1])) {
                    $best = [$nk, $pct];
                }
            }
            if ($best !== null && ! isset($out[$best[0]])) {
                $out[$fk] = ['key' => (string) $best[0], 'nombre' => (string) ($originals[$best[0]] ?? $best[0]), 'percent' => $best[1]];
                $out[$best[0]] = ['key' => (string) $fk, 'nombre' => (string) ($originals[$fk] ?? $fk), 'percent' => $best[1]];
            }
        }

        return $out;
    }
}
