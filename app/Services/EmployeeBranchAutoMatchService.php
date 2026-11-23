<?php

namespace App\Services;

use App\Enums\MatchType;
use App\Enums\SourceType;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\NoiMovement;
use App\Models\Period;
use App\Models\ReportUpload;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Asignación automática de sucursal ("automatch") para los colaboradores
 * detectados en NOI / NOI Fiscal de cada periodo.
 *
 * Para qué sirve en el negocio: la nómina (NOI) no trae la sucursal operativa
 * del colaborador; este servicio la infiere con evidencia de operación
 * (cobranza, colocación, cartera, gastos, directorio Lendus, histórico, alias y
 * registros canónicos) y la guarda en employee_branch_assignments, que después
 * usan la radiografía, el roster del periodo y los reportes por colaborador.
 * Nunca sobrescribe una asignación manual confirmada. Lo invocan el comando de
 * automatch y el flujo de actualización de base de datos del periodo.
 *
 * Contrato de rendimiento: debe correr en O(filas_cobranza + filas_colocación +
 * filas_cartera + filas_gastos + empleados) por periodo — NUNCA
 * O(empleados × filas_cobranza). Todas las fuentes candidatas se preindexan UNA
 * vez antes del ciclo de empleados. El ciclo solo hace búsquedas en memoria por
 * hash y, como último recurso, comparaciones difusas contra un conjunto reducido
 * de nombres distintos (vistos en cobranza/colocación/directorio — normalmente
 * unos cientos, nunca el número crudo de filas).
 */
class EmployeeBranchAutoMatchService
{
    /**
     * @param  PersonIdentityResolverService  $resolver  Calcula la similitud difusa entre nombres.
     * @param  BranchResolverService  $branchResolver  Provee las sucursales operativas oficiales y resuelve sucursal por código Lendus.
     */
    public function __construct(
        private PersonIdentityResolverService $resolver,
        private BranchResolverService $branchResolver,
    ) {}

    /**
     * Ejecuta el automatch para un periodo o para todos (del más reciente al más antiguo).
     *
     * Activa temporalmente el query log de Laravel para contar consultas (solo si
     * no estaba activo; lo desactiva al terminar) en vez de un listener permanente,
     * para no fugar memoria en workers de larga vida.
     *
     * @param  int|null  $periodId  Periodo a procesar; null = todos los periodos.
     * @param  callable|null  $progress  Callback opcional fn(int $porcentaje, string $etapa, string $detalle) para reportar avance.
     * @return array<string, int> Totales acumulados (ver emptyStats()) más duration_ms y query_count.
     */
    public function handle(?int $periodId = null, ?callable $progress = null): array
    {
        $periods = Period::query()
            ->when($periodId, fn ($query) => $query->whereKey($periodId))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('sequence')
            ->get();

        $totals = $this->emptyStats();
        $start = microtime(true);

        // Scoped query counting via Laravel's query log (not DB::listen — a permanent
        // listener would leak memory across repeated calls in a long-lived worker process).
        $wasLogging = DB::logging();
        if (! $wasLogging) {
            DB::enableQueryLog();
        }
        $queryCountBefore = count(DB::getQueryLog());

        foreach ($periods as $period) {
            $result = $this->processPeriod($period, $progress);
            foreach ($result as $key => $value) {
                if (array_key_exists($key, $totals)) {
                    $totals[$key] += $value;
                }
            }
        }

        $totals['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
        $totals['query_count'] = count(DB::getQueryLog()) - $queryCountBefore;
        if (! $wasLogging) {
            DB::disableQueryLog();
        }

        return $totals;
    }

    /**
     * Estructura de contadores en cero que se acumula por periodo y en total.
     *
     * @return array<string, int> processed, unique_persons, matched, unmatched, ambiguous, manual_kept, contadores por método, duration_ms, query_count.
     */
    private function emptyStats(): array
    {
        return [
            'processed' => 0,
            'unique_persons' => 0,
            'matched' => 0,
            'unmatched' => 0,
            'ambiguous' => 0,
            'manual_kept' => 0,
            'exact_identifier' => 0,
            'historical' => 0,
            'exact_name' => 0,
            'majority' => 0,
            'fuzzy' => 0,
            'duration_ms' => 0,
            'query_count' => 0,
        ];
    }

    /**
     * Resuelve y guarda la sucursal de todos los colaboradores NOI de un periodo.
     *
     * Pasos: determina semanas cubiertas y uploads NOI/NOI Fiscal/cobranza,
     * obtiene empleados, preindexa todas las fuentes, agrupa los duplicados
     * NOI vs NOI Fiscal por nombre normalizado (los homónimos con rechazo de
     * fusión quedan en grupos separados) y resuelve UNA sucursal por persona real.
     * Reglas: (A) una asignación manual confirmada en el grupo se conserva y se
     * hereda a los demás registros del grupo; si no, se usa
     * resolveCandidateForGroup(); sin candidato queda como Unmatched (branch_id null).
     *
     * Efecto en BD: upsert por lotes de 200 en employee_branch_assignments
     * (llave period_id + employee_id), siempre con was_manual_reviewed = false;
     * las filas manuales existentes no se incluyen en el upsert.
     *
     * @param  Period  $period  Periodo a procesar.
     * @param  callable|null  $progress  Callback de avance (55-100 %).
     * @return array<string, int> Contadores del periodo.
     */
    private function processPeriod(Period $period, ?callable $progress = null): array
    {
        $stats = $this->emptyStats();

        $coveredWeekIds = $this->resolveCoveredWeekIds($period);
        $noiUploadIds = $this->resolveUploadIdsForSource('noi_nomina', $coveredWeekIds);
        $fiscalUploadIds = $this->resolveUploadIdsForSource('noi_nomina_fiscal', $coveredWeekIds);
        $cobranzaUploadIds = $this->resolveUploadIdsForSource('lendus_ingresos_cobranza', $coveredWeekIds);
        $allNoiUploadIds = array_values(array_unique(array_merge($noiUploadIds, $fiscalUploadIds)));

        $employees = $this->resolveEmployeesForPeriod($period, $allNoiUploadIds);

        if ($employees->isEmpty()) {
            return $stats;
        }

        $employeeIds = $employees->pluck('id')->all();
        $operativeNames = array_map('strtoupper', $this->branchResolver->operativeFinancialBranches());

        $progress && $progress(55, 'Preparando índices', 'Agrupando cobranza, colocación, cartera y directorio Lendus una sola vez para todo el periodo.');

        // ── Preindex every candidate source ONCE (no per-employee queries beyond this point) ──
        $existingAssignments = EmployeeBranchAssignment::query()
            ->where('period_id', $period->id)
            ->whereIn('employee_id', $employeeIds)
            ->get()
            ->keyBy('employee_id');

        $cobranzaIndex = $this->buildCobranzaIndex($cobranzaUploadIds, $operativeNames);
        $placementsIndex = $this->buildPlacementsIndex($period->id);
        $portfoliosIndex = $this->buildPortfoliosIndex($period->id, $operativeNames);
        $expensesIndex = $this->buildExpensesIndex($period->id, $employeeIds, $operativeNames);
        $lendusIndex = $this->buildLendusIndex();
        $historicalIndex = $this->buildHistoricalIndex($period->id, $employeeIds, $operativeNames);
        $aliasIndex = $this->buildAliasIndex($employees, $operativeNames);
        // Cierre 2026-10: homónimos con rechazo de fusión explícito son personas distintas —
        // cada uno es su propio grupo y no hereda la sucursal del otro por nombre.
        $homonymKeys = $this->homonymGroupKeys($employees);
        $canonicalIndex = $this->buildCanonicalIndex($employees, $operativeNames, array_values(array_unique(array_map(fn ($k) => strstr($k, '#', true), $homonymKeys))));

        $progress && $progress(65, 'Coincidencias exactas', 'Resolviendo por historial confirmado y nombre exacto.');

        // ── Unify NOI + NOI Fiscal duplicates: resolve branch ONCE per real person ──
        $groups = $employees->groupBy(fn (Employee $e) => $homonymKeys[$e->id] ?? ($e->normalized_name ?: ('id:'.$e->id)));

        $upserts = [];
        $groupsDone = 0;
        $totalGroups = max(1, $groups->count());

        foreach ($groups as $normName => $groupEmployees) {
            $stats['processed'] += $groupEmployees->count();
            $stats['unique_persons']++;
            $groupsDone++;

            if ($progress && $groupsDone % 40 === 0) {
                $pct = 65 + (int) round(($groupsDone / $totalGroups) * 20); // 65..85
                $progress(min(85, $pct), 'Resolviendo asignaciones', "Procesadas {$groupsDone}/{$totalGroups} personas.");
            }

            // A) Preserve any existing manual/confirmed assignment in the group.
            $manual = $groupEmployees
                ->map(fn (Employee $e) => $existingAssignments->get($e->id))
                ->filter(fn ($a) => $a && $a->was_manual_reviewed && $a->match_type?->value === MatchType::Manual->value && $a->branch_id)
                ->first();

            if ($manual) {
                foreach ($groupEmployees as $e) {
                    $existing = $existingAssignments->get($e->id);
                    if ($existing && $existing->was_manual_reviewed && $existing->match_type?->value === MatchType::Manual->value && $existing->branch_id) {
                        $stats['manual_kept']++;

                        continue; // untouched — never overwrite a manual assignment
                    }
                    $upserts[] = $this->buildUpsertRow(
                        $period->id, $e->id, $manual->branch_id, SourceType::Manual,
                        "heredado de asignación manual del empleado #{$manual->employee_id}",
                        MatchType::Manual, 1.00,
                        'Sucursal heredada de una asignación manual confirmada para la misma persona.',
                    );
                    $stats['matched']++;
                }

                continue;
            }

            $candidate = $this->resolveCandidateForGroup(
                str_contains((string) $normName, '#') ? strstr((string) $normName, '#', true) : $normName,
                $groupEmployees,
                $cobranzaIndex,
                $placementsIndex,
                $portfoliosIndex,
                $expensesIndex,
                $lendusIndex,
                $historicalIndex,
                $aliasIndex,
                $canonicalIndex,
            );

            $isAmbiguous = is_array($candidate) && $candidate['branch_id'] === null;
            $isMatched = is_array($candidate) && $candidate['branch_id'] !== null;

            foreach ($groupEmployees as $e) {
                if ($isMatched) {
                    $upserts[] = $this->buildUpsertRow(
                        $period->id, $e->id, $candidate['branch_id'], $candidate['source_type'],
                        $candidate['source_reference'], $candidate['match_type'], $candidate['confidence'],
                        $candidate['notes'],
                    );
                } else {
                    $notes = $isAmbiguous
                        ? $candidate['notes']
                        : 'No se encontró sucursal clara para el colaborador en cobranza u operación. Requiere revisión manual.';
                    $upserts[] = $this->buildUpsertRow(
                        $period->id, $e->id, null, SourceType::Lendus, null, MatchType::Unmatched, 0, $notes,
                    );
                }
            }

            if ($isMatched) {
                $stats['matched']++;
                $stats[$candidate['stat_key']] = ($stats[$candidate['stat_key']] ?? 0) + 1;
            } elseif ($isAmbiguous) {
                $stats['ambiguous']++;
            } else {
                $stats['unmatched']++;
            }
        }

        $progress && $progress(95, 'Guardando asignaciones', 'Escribiendo asignaciones automáticas en lote.');

        foreach (array_chunk($upserts, 200) as $chunk) {
            EmployeeBranchAssignment::query()->upsert(
                $chunk,
                ['period_id', 'employee_id'],
                ['branch_id', 'source_type', 'source_reference', 'match_type', 'confidence', 'was_manual_reviewed', 'notes', 'updated_at'],
            );
        }

        $progress && $progress(100, 'Automatch completado', 'Asignaciones guardadas.');

        return $stats;
    }

    /**
     * Arma una fila para el upsert de employee_branch_assignments.
     *
     * @param  int  $periodId  Periodo de la asignación.
     * @param  int  $employeeId  Colaborador.
     * @param  int|null  $branchId  Sucursal resuelta; null cuando no hubo coincidencia.
     * @param  SourceType  $sourceType  Origen de la evidencia (Lendus, Manual...).
     * @param  string|null  $sourceReference  Tabla/fuente concreta usada como evidencia.
     * @param  MatchType  $matchType  Método de coincidencia aplicado.
     * @param  float  $confidence  Confianza 0-1.
     * @param  string  $notes  Explicación legible para la revisión manual.
     * @return array<string, mixed> Fila lista para upsert (was_manual_reviewed siempre false).
     */
    private function buildUpsertRow(
        int $periodId,
        int $employeeId,
        ?int $branchId,
        SourceType $sourceType,
        ?string $sourceReference,
        MatchType $matchType,
        float $confidence,
        string $notes,
    ): array {
        return [
            'period_id' => $periodId,
            'employee_id' => $employeeId,
            'branch_id' => $branchId,
            'source_type' => $sourceType->value,
            'source_reference' => $sourceReference,
            'match_type' => $matchType->value,
            'confidence' => $confidence,
            'was_manual_reviewed' => false,
            'notes' => $notes,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Elige la sucursal de una persona (grupo de registros NOI) con la evidencia
     * preindexada, en orden de prioridad:
     *  1. Histórico: última sucursal operativa confirmada en otro periodo (0.85).
     *  2. Cobranza: sucursal única (exacta, 1.00) o mayoría clara (0.85).
     *  3. Colocación y 4. Cartera: mayoría clara (0.82).
     *  5. Gastos por employee_id: mayoría clara (0.80).
     *  6. Directorio Lendus: sucursal inferida (0.88) o por código Lendus (0.85).
     *  7. Alias confirmado (0.95) y 8. otro registro con el mismo nombre normalizado (0.98).
     *  9. Fuzzy contra el conjunto reducido de nombres: acepta con ≥85 % y
     *     margen ≥8 puntos sobre el segundo lugar.
     * Un empate en cualquier fuente o un fuzzy plausible (≥70 %) sin confianza
     * marca el caso como ambiguo, pero se siguen probando las demás fuentes.
     * Puede hacer una consulta puntual a branches al resolver por código Lendus.
     *
     * @param  string  $normName  Nombre normalizado del grupo (sin sufijo de homónimo).
     * @param  Collection  $groupEmployees  Registros Employee de la misma persona.
     * @param  array  $cobranzaIndex  Índice de buildCobranzaIndex().
     * @param  array  $placementsIndex  Índice de buildPlacementsIndex().
     * @param  array  $portfoliosIndex  Índice de buildPortfoliosIndex().
     * @param  array  $expensesIndex  Índice de buildExpensesIndex().
     * @param  array  $lendusIndex  Índice de buildLendusIndex().
     * @param  array  $historicalIndex  Índice de buildHistoricalIndex().
     * @param  array  $aliasIndex  Índice de buildAliasIndex().
     * @param  array  $canonicalIndex  Índice de buildCanonicalIndex().
     * @return array{branch_id:int,source_type:SourceType,source_reference:string,match_type:MatchType,confidence:float,notes:string,stat_key:string}|null
     *                                                                                                                                                     Candidato resuelto; array con branch_id null (stat_key 'ambiguous') si es ambiguo; null si no hay evidencia.
     */
    private function resolveCandidateForGroup(
        string $normName,
        Collection $groupEmployees,
        array $cobranzaIndex,
        array $placementsIndex,
        array $portfoliosIndex,
        array $expensesIndex,
        array $lendusIndex,
        array $historicalIndex,
        array $aliasIndex,
        array $canonicalIndex,
    ): ?array {
        // D) Historical confirmed branch from a prior period — unambiguous by construction
        // (we only ever store the single most recent operative branch per employee).
        foreach ($groupEmployees as $e) {
            if (isset($historicalIndex[$e->id])) {
                return [
                    'branch_id' => $historicalIndex[$e->id],
                    'source_type' => SourceType::Lendus,
                    'source_reference' => 'employee_branch_assignments (histórico)',
                    'match_type' => MatchType::Historical,
                    'confidence' => 0.85,
                    'notes' => 'Sucursal heredada de la última asignación confirmada en un periodo anterior.',
                    'stat_key' => 'historical',
                ];
            }
        }

        $ambiguous = false;

        // E/F) Cobranza — exact name, then clear majority
        if (isset($cobranzaIndex['byName'][$normName])) {
            $branches = $cobranzaIndex['byName'][$normName];
            arsort($branches);
            $branchIds = array_keys($branches);
            $topBranch = $branchIds[0];
            $topCount = $branches[$topBranch];
            $secondCount = isset($branchIds[1]) ? $branches[$branchIds[1]] : 0;

            if (count($branches) === 1) {
                return [
                    'branch_id' => $topBranch, 'source_type' => SourceType::Lendus,
                    'source_reference' => 'fact_recoveries', 'match_type' => MatchType::Exact,
                    'confidence' => 1.00, 'stat_key' => 'exact_name',
                    'notes' => "Coincidencia exacta por nombre en cobranza ({$topCount} registro(s), sucursal única).",
                ];
            }

            if ($topCount > $secondCount) {
                return [
                    'branch_id' => $topBranch, 'source_type' => SourceType::Lendus,
                    'source_reference' => 'fact_recoveries', 'match_type' => MatchType::Majority,
                    'confidence' => 0.85, 'stat_key' => 'majority',
                    'notes' => "Sucursal dominante en cobranza: {$topCount} registro(s) contra {$secondCount} del segundo lugar.",
                ];
            }

            $ambiguous = true; // tie between top branches — don't guess, try other sources
        }

        // G) Colocación (placements) — exact name, clear majority
        if (isset($placementsIndex['byName'][$normName])) {
            $list = $placementsIndex['byName'][$normName];
            $top = $list[0];
            $second = $list[1] ?? null;
            if (! $second || $top['cnt'] > $second['cnt']) {
                return [
                    'branch_id' => $top['branch_id'], 'source_type' => SourceType::Lendus,
                    'source_reference' => 'fact_placements', 'match_type' => MatchType::Majority,
                    'confidence' => 0.82, 'stat_key' => 'majority',
                    'notes' => 'Sucursal asignada con base en colocación del periodo.',
                ];
            }
            $ambiguous = true;
        }

        // Cartera (portfolios)
        if (isset($portfoliosIndex['byName'][$normName])) {
            $list = $portfoliosIndex['byName'][$normName];
            $top = $list[0];
            $second = $list[1] ?? null;
            if (! $second || $top['cnt'] > $second['cnt']) {
                return [
                    'branch_id' => $top['branch_id'], 'source_type' => SourceType::Lendus,
                    'source_reference' => 'fact_portfolios', 'match_type' => MatchType::Majority,
                    'confidence' => 0.82, 'stat_key' => 'majority',
                    'notes' => 'Sucursal asignada con base en cartera del periodo.',
                ];
            }
            $ambiguous = true;
        }

        // Gastos (expenses) — keyed by employee_id, not name
        foreach ($groupEmployees as $e) {
            if (isset($expensesIndex['byEmployee'][$e->id])) {
                $list = $expensesIndex['byEmployee'][$e->id];
                $top = $list[0];
                $second = $list[1] ?? null;
                if (! $second || $top['cnt'] > $second['cnt']) {
                    return [
                        'branch_id' => $top['branch_id'], 'source_type' => SourceType::Lendus,
                        'source_reference' => 'fact_expenses', 'match_type' => MatchType::Majority,
                        'confidence' => 0.80, 'stat_key' => 'majority',
                        'notes' => 'Sucursal asignada con base en gastos del periodo.',
                    ];
                }
                $ambiguous = true;
            }
        }

        // Directorio Lendus — exact name
        if (isset($lendusIndex['byName'][$normName])) {
            $record = $lendusIndex['byName'][$normName];
            if ($record->inferred_branch_id) {
                return [
                    'branch_id' => (int) $record->inferred_branch_id, 'source_type' => SourceType::Lendus,
                    'source_reference' => 'lendus_employee_directory', 'match_type' => MatchType::Exact,
                    'confidence' => 0.88, 'stat_key' => 'exact_name',
                    'notes' => "Sucursal del directorio Lendus. Código: {$record->codigo}, Puesto: {$record->puesto}.",
                ];
            }
            if ($record->codigo) {
                $branchName = $this->branchResolver->resolveBranchNameFromCode((string) $record->codigo);
                if ($branchName) {
                    $branchId = DB::table('branches')->whereRaw('UPPER(TRIM(name)) = ?', [strtoupper(trim($branchName))])->value('id');
                    if ($branchId) {
                        return [
                            'branch_id' => (int) $branchId, 'source_type' => SourceType::Lendus,
                            'source_reference' => 'lendus_employee_directory', 'match_type' => MatchType::Exact,
                            'confidence' => 0.85, 'stat_key' => 'exact_name',
                            'notes' => "Sucursal por código {$record->codigo} → {$branchName}. Puesto: {$record->puesto}.",
                        ];
                    }
                }
            }
        }

        // Alias confirmado / empleado canónico con mismo nombre normalizado
        if (isset($aliasIndex[$normName])) {
            return [
                'branch_id' => $aliasIndex[$normName], 'source_type' => SourceType::Lendus,
                'source_reference' => 'employee_aliases', 'match_type' => MatchType::Normalized,
                'confidence' => 0.95, 'stat_key' => 'exact_name',
                'notes' => 'Sucursal heredada de alias de persona confirmado.',
            ];
        }
        if (isset($canonicalIndex[$normName])) {
            return [
                'branch_id' => $canonicalIndex[$normName], 'source_type' => SourceType::Lendus,
                'source_reference' => 'employees.normalized_name', 'match_type' => MatchType::CanonicalSameName,
                'confidence' => 0.98, 'stat_key' => 'exact_name',
                'notes' => 'Sucursal heredada de otro registro con el mismo nombre normalizado.',
            ];
        }

        // G) Fuzzy — last resort, against a REDUCED candidate pool only (never raw rows).
        $pool = array_unique(array_merge(
            $cobranzaIndex['distinctNames'] ?? [],
            $placementsIndex['distinctNames'] ?? [],
            $lendusIndex['distinctNames'] ?? [],
        ));

        if (! empty($pool)) {
            // Cheap bucket by first token to shrink the comparison set further.
            $firstToken = strtok($normName, ' ') ?: $normName;
            $bucket = array_filter($pool, function ($candidateName) use ($firstToken, $normName) {
                $candidateFirstToken = strtok($candidateName, ' ') ?: $candidateName;

                return $candidateFirstToken === $firstToken
                    || str_starts_with($candidateName, $firstToken)
                    || str_starts_with($normName, $candidateFirstToken);
            });
            $searchPool = ! empty($bucket) ? $bucket : $pool;

            $bestName = null;
            $bestScore = 0.0;
            $secondScore = 0.0;

            foreach ($searchPool as $candidateName) {
                $score = $this->resolver->scoreNameSimilarity($normName, $candidateName);
                if ($score > $bestScore) {
                    $secondScore = $bestScore;
                    $bestScore = $score;
                    $bestName = $candidateName;
                } elseif ($score > $secondScore) {
                    $secondScore = $score;
                }
            }

            if ($bestName && $bestScore >= 85.0 && ($bestScore - $secondScore) >= 8.0) {
                $branchInfo = $cobranzaIndex['byName'][$bestName] ?? null;
                if ($branchInfo) {
                    arsort($branchInfo);
                    $branchId = array_key_first($branchInfo);
                    $scoreLabel = round($bestScore, 1);

                    return [
                        'branch_id' => $branchId, 'source_type' => SourceType::Lendus,
                        'source_reference' => 'fact_recoveries (fuzzy)', 'match_type' => MatchType::Fuzzy,
                        'confidence' => round($bestScore / 100, 2), 'stat_key' => 'fuzzy',
                        'notes' => "Coincidencia fuzzy con \"{$bestName}\" ({$scoreLabel}%), candidato siguiente al {$secondScore}%.",
                    ];
                }

                $lendusRecord = $lendusIndex['byName'][$bestName] ?? null;
                if ($lendusRecord && $lendusRecord->inferred_branch_id) {
                    $scoreLabel = round($bestScore, 1);

                    return [
                        'branch_id' => (int) $lendusRecord->inferred_branch_id, 'source_type' => SourceType::Lendus,
                        'source_reference' => 'lendus_employee_directory (fuzzy)', 'match_type' => MatchType::Fuzzy,
                        'confidence' => round($bestScore / 100, 2), 'stat_key' => 'fuzzy',
                        'notes' => "Coincidencia fuzzy con directorio Lendus \"{$bestName}\" ({$scoreLabel}%).",
                    ];
                }
            } elseif ($bestName && $bestScore >= 70.0) {
                $ambiguous = true; // there's a plausible-but-not-confident candidate — flag, don't guess
            }
        }

        if ($ambiguous) {
            return [
                'branch_id' => null,
                'stat_key' => 'ambiguous',
                'notes' => 'Se detectaron varias sucursales candidatas sin mayoría clara (cobranza, colocación, cartera o gastos empatados, o similitud de nombre insuficiente). Requiere revisión manual.',
            ];
        }

        return null;
    }

    /**
     * IDs de las semanas cubiertas por el periodo: él mismo si es semanal, o las
     * semanas que se traslapan con sus fechas si es mensual.
     *
     * @param  Period  $period  Periodo a evaluar.
     * @return array<int, int> IDs de periodos semanales ordenados por fecha de inicio.
     */
    private function resolveCoveredWeekIds(Period $period): array
    {
        if ($period->type === 'weekly') {
            return [$period->id];
        }

        return Period::query()
            ->where('type', 'weekly')
            ->whereDate('start_date', '<=', $period->end_date)
            ->whereDate('end_date', '>=', $period->start_date)
            ->orderBy('start_date')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * IDs de report_uploads de una fuente cuyo covered_period_ids (JSON)
     * contiene alguna de las semanas indicadas.
     *
     * @param  string  $sourceCode  Código de data_sources (noi_nomina, noi_nomina_fiscal, lendus_ingresos_cobranza...).
     * @param  array<int, int>  $coveredWeekIds  Semanas cubiertas por el periodo.
     * @return array<int, int> IDs de uploads; [] si no hay semanas.
     */
    private function resolveUploadIdsForSource(string $sourceCode, array $coveredWeekIds): array
    {
        if (empty($coveredWeekIds)) {
            return [];
        }

        return ReportUpload::query()
            ->whereHas('dataSource', fn ($query) => $query->where('code', $sourceCode))
            ->where(function ($query) use ($coveredWeekIds) {
                foreach ($coveredWeekIds as $weekId) {
                    $query->orWhereJsonContains('covered_period_ids', $weekId);
                }
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * Colaboradores con movimientos NOI en el periodo: por uploads NOI cuando
     * existen; si no, por noi_movements.period_id.
     *
     * @param  Period  $period  Periodo procesado.
     * @param  array<int, int>  $noiUploadIds  Uploads NOI y NOI Fiscal del periodo.
     * @return Collection<int, Employee> Empleados ordenados por nombre completo.
     */
    private function resolveEmployeesForPeriod(Period $period, array $noiUploadIds): Collection
    {
        $employeeIds = collect();

        if (! empty($noiUploadIds)) {
            $employeeIds = $employeeIds->merge(
                NoiMovement::query()
                    ->whereIn('report_upload_id', $noiUploadIds)
                    ->whereNotNull('employee_id')
                    ->pluck('employee_id')
            );
        } else {
            $employeeIds = $employeeIds->merge(
                NoiMovement::query()
                    ->where('period_id', $period->id)
                    ->whereNotNull('employee_id')
                    ->pluck('employee_id')
            );
        }

        $employeeIds = $employeeIds->filter()->unique()->values();

        if ($employeeIds->isEmpty()) {
            return collect();
        }

        return Employee::query()
            ->whereIn('id', $employeeIds)
            ->orderBy('full_name')
            ->get();
    }

    /**
     * Agrega todas las filas de cobranza (decenas de miles) en un mapa
     * nombre_normalizado_del_promotor → [branch_id => conteo], calculado una sola
     * vez por periodo. Solo considera sucursales operativas oficiales y toma el
     * nombre de raw_payload.promoter_name.
     *
     * @param  array<int, int>  $cobranzaUploadIds  Uploads de lendus_ingresos_cobranza del periodo.
     * @param  array<int, string>  $operativeNames  Nombres en mayúsculas de las sucursales operativas.
     * @return array{byName: array<string, array<int, int>>, distinctNames: array<int, string>}
     */
    private function buildCobranzaIndex(array $cobranzaUploadIds, array $operativeNames): array
    {
        if (empty($cobranzaUploadIds)) {
            return ['byName' => [], 'distinctNames' => []];
        }

        $rows = DB::table('fact_recoveries as r')
            ->join('branches as b', 'r.branch_id', '=', 'b.id')
            ->whereIn('r.report_upload_id', $cobranzaUploadIds)
            ->whereNotNull('r.branch_id')
            ->whereIn(DB::raw('UPPER(TRIM(b.name))'), $operativeNames)
            ->select('r.raw_payload', 'r.branch_id')
            ->get();

        $byName = [];

        foreach ($rows as $row) {
            $payload = is_string($row->raw_payload) ? json_decode($row->raw_payload, true) : (array) $row->raw_payload;
            $promoterRaw = $payload['promoter_name'] ?? null;
            if (! $promoterRaw) {
                continue;
            }

            $norm = self::normalizeHumanName($promoterRaw);
            if ($norm === '') {
                continue;
            }

            $byName[$norm][$row->branch_id] = ($byName[$norm][$row->branch_id] ?? 0) + 1;
        }

        return ['byName' => $byName, 'distinctNames' => array_keys($byName)];
    }

    /**
     * Índice de colocación del periodo: nombre normalizado del promotor →
     * lista de sucursales con su conteo, ordenada de mayor a menor.
     * (No filtra por sucursales operativas, a diferencia de los demás índices.)
     *
     * @param  int  $periodId  Periodo procesado.
     * @return array{byName: array<string, array<int, array{branch_id:int, cnt:int}>>, distinctNames: array<int, string>}
     */
    private function buildPlacementsIndex(int $periodId): array
    {
        $rows = DB::table('fact_placements as p')
            ->where('p.period_id', $periodId)
            ->whereNotNull('p.branch_id')
            ->whereNotNull('p.normalized_promoter_name')
            ->selectRaw('p.normalized_promoter_name, p.branch_id, COUNT(*) as cnt')
            ->groupBy('p.normalized_promoter_name', 'p.branch_id')
            ->get();

        $byName = [];
        foreach ($rows as $r) {
            $byName[$r->normalized_promoter_name][] = ['branch_id' => (int) $r->branch_id, 'cnt' => (int) $r->cnt];
        }
        foreach ($byName as $k => $list) {
            usort($list, fn ($a, $b) => $b['cnt'] <=> $a['cnt']);
            $byName[$k] = $list;
        }

        return ['byName' => $byName, 'distinctNames' => array_keys($byName)];
    }

    /**
     * Índice de cartera del periodo (solo sucursales operativas): nombre
     * normalizado del promotor → sucursales con conteo, de mayor a menor.
     *
     * @param  int  $periodId  Periodo procesado.
     * @param  array<int, string>  $operativeNames  Sucursales operativas en mayúsculas.
     * @return array{byName: array<string, array<int, array{branch_id:int, cnt:int}>>}
     */
    private function buildPortfoliosIndex(int $periodId, array $operativeNames): array
    {
        $rows = DB::table('fact_portfolios as p')
            ->join('branches as b', 'p.branch_id', '=', 'b.id')
            ->where('p.period_id', $periodId)
            ->whereNotNull('p.branch_id')
            ->whereNotNull('p.promoter_name')
            ->whereIn(DB::raw('UPPER(TRIM(b.name))'), $operativeNames)
            ->selectRaw('p.promoter_name, p.branch_id, COUNT(*) as cnt')
            ->groupBy('p.promoter_name', 'p.branch_id')
            ->get();

        $byName = [];
        foreach ($rows as $r) {
            $norm = self::normalizeHumanName($r->promoter_name);
            if ($norm === '') {
                continue;
            }
            $byName[$norm][] = ['branch_id' => (int) $r->branch_id, 'cnt' => (int) $r->cnt];
        }
        foreach ($byName as $k => $list) {
            usort($list, fn ($a, $b) => $b['cnt'] <=> $a['cnt']);
            $byName[$k] = $list;
        }

        return ['byName' => $byName];
    }

    /**
     * Índice de gastos del periodo por employee_id (solo sucursales operativas):
     * sucursales con conteo de gastos, de mayor a menor.
     *
     * @param  int  $periodId  Periodo procesado.
     * @param  array<int, int>  $employeeIds  Colaboradores del periodo.
     * @param  array<int, string>  $operativeNames  Sucursales operativas en mayúsculas.
     * @return array{byEmployee: array<int, array<int, array{branch_id:int, cnt:int}>>}
     */
    private function buildExpensesIndex(int $periodId, array $employeeIds, array $operativeNames): array
    {
        if (empty($employeeIds)) {
            return ['byEmployee' => []];
        }

        $rows = DB::table('fact_expenses as e')
            ->join('branches as b', 'e.branch_id', '=', 'b.id')
            ->where('e.period_id', $periodId)
            ->whereIn('e.employee_id', $employeeIds)
            ->whereNotNull('e.branch_id')
            ->whereIn(DB::raw('UPPER(TRIM(b.name))'), $operativeNames)
            ->selectRaw('e.employee_id, e.branch_id, COUNT(*) as cnt')
            ->groupBy('e.employee_id', 'e.branch_id')
            ->get();

        $byEmployee = [];
        foreach ($rows as $r) {
            $byEmployee[$r->employee_id][] = ['branch_id' => (int) $r->branch_id, 'cnt' => (int) $r->cnt];
        }
        foreach ($byEmployee as $k => $list) {
            usort($list, fn ($a, $b) => $b['cnt'] <=> $a['cnt']);
            $byEmployee[$k] = $list;
        }

        return ['byEmployee' => $byEmployee];
    }

    /**
     * Índice del directorio de empleados Lendus (solo operativos) por nombre normalizado.
     *
     * @return array{byName: array<string, object>, distinctNames: array<int, string>} Registros con codigo, nombre, puesto e inferred_branch_id.
     */
    private function buildLendusIndex(): array
    {
        $rows = DB::table('lendus_employee_directory')
            ->where('is_operational', true)
            ->whereNotNull('normalized_name')
            ->get(['codigo', 'nombre', 'normalized_name', 'puesto', 'inferred_branch_id']);

        $byName = [];
        foreach ($rows as $r) {
            $byName[$r->normalized_name] = $r;
        }

        return ['byName' => $byName, 'distinctNames' => array_keys($byName)];
    }

    /**
     * Sucursal operativa más reciente por colaborador tomada de OTRO periodo
     * (nunca el que se procesa, para que una corrida no se alimente de su propio
     * resultado previo). "Más reciente" = mayor period_id.
     *
     * @param  int  $currentPeriodId  Periodo en proceso (excluido).
     * @param  array<int, int>  $employeeIds  Colaboradores del periodo.
     * @param  array<int, string>  $operativeNames  Sucursales operativas en mayúsculas.
     * @return array<int, int> employee_id => branch_id.
     */
    private function buildHistoricalIndex(int $currentPeriodId, array $employeeIds, array $operativeNames): array
    {
        if (empty($employeeIds)) {
            return [];
        }

        $operativeIds = DB::table('branches')->whereIn(DB::raw('UPPER(TRIM(name))'), $operativeNames)->pluck('id')->all();
        if (empty($operativeIds)) {
            return [];
        }

        $rows = DB::table('employee_branch_assignments')
            ->whereIn('employee_id', $employeeIds)
            ->where('period_id', '!=', $currentPeriodId)
            ->whereNotNull('branch_id')
            ->whereIn('branch_id', $operativeIds)
            ->orderByDesc('period_id')
            ->select('employee_id', 'branch_id')
            ->get();

        $byEmployee = [];
        foreach ($rows as $r) {
            if (! isset($byEmployee[$r->employee_id])) {
                $byEmployee[$r->employee_id] = (int) $r->branch_id; // first hit = most recent (orderByDesc)
            }
        }

        return $byEmployee;
    }

    /**
     * Índice nombre normalizado → sucursal, heredada del colaborador canónico
     * al que apunta un alias confirmado (employee_aliases). Usa la asignación
     * operativa de mayor period_id del canónico.
     *
     * @param  Collection  $employees  Colaboradores del periodo.
     * @param  array<int, string>  $operativeNames  Sucursales operativas en mayúsculas.
     * @return array<string, int> normalized_alias => branch_id.
     */
    private function buildAliasIndex(Collection $employees, array $operativeNames): array
    {
        $normNames = $employees->pluck('normalized_name')->filter()->unique()->values()->all();
        if (empty($normNames)) {
            return [];
        }

        $aliasRows = DB::table('employee_aliases')
            ->whereIn('normalized_alias', $normNames)
            ->select('normalized_alias', 'employee_id')
            ->get();

        if ($aliasRows->isEmpty()) {
            return [];
        }

        $operativeIds = DB::table('branches')->whereIn(DB::raw('UPPER(TRIM(name))'), $operativeNames)->pluck('id')->all();
        $canonicalIds = $aliasRows->pluck('employee_id')->unique()->all();

        $branchByCanonical = DB::table('employee_branch_assignments')
            ->whereIn('employee_id', $canonicalIds)
            ->whereNotNull('branch_id')
            ->whereIn('branch_id', $operativeIds)
            ->orderByDesc('period_id')
            ->select('employee_id', 'branch_id')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($g) => (int) $g->first()->branch_id);

        $byNormName = [];
        foreach ($aliasRows as $a) {
            $branchId = $branchByCanonical[$a->employee_id] ?? null;
            if ($branchId) {
                $byNormName[$a->normalized_alias] = $branchId;
            }
        }

        return $byNormName;
    }

    /**
     * Índice nombre normalizado → sucursal tomada de otros registros Employee con
     * el mismo normalized_name (duplicados NOI vs NOI Fiscal nunca unificados) que
     * ya tienen sucursal operativa asignada. Excluye nombres de homónimos con
     * rechazo de fusión explícito.
     *
     * @param  Collection  $employees  Colaboradores del periodo.
     * @param  array<int, string>  $operativeNames  Sucursales operativas en mayúsculas.
     * @param  array<int, string>  $homonymNames  Nombres normalizados que no deben heredar sucursal.
     * @return array<string, int> normalized_name => branch_id.
     */
    private function buildCanonicalIndex(Collection $employees, array $operativeNames, array $homonymNames = []): array
    {
        // Un nombre compartido por homónimos separados no se usa para heredar sucursal.
        $normNames = $employees->pluck('normalized_name')->filter()->unique()->reject(fn ($n) => in_array($n, $homonymNames, true))->values()->all();
        if (empty($normNames)) {
            return [];
        }

        $operativeIds = DB::table('branches')->whereIn(DB::raw('UPPER(TRIM(name))'), $operativeNames)->pluck('id')->all();

        $siblings = DB::table('employees')->whereIn('normalized_name', $normNames)->select('id', 'normalized_name')->get();
        $idsByName = $siblings->groupBy('normalized_name')->map(fn ($g) => $g->pluck('id')->all());
        $allIds = $siblings->pluck('id')->all();

        $branchByEmployee = DB::table('employee_branch_assignments')
            ->whereIn('employee_id', $allIds)
            ->whereNotNull('branch_id')
            ->whereIn('branch_id', $operativeIds)
            ->orderByDesc('period_id')
            ->select('employee_id', 'branch_id')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($g) => (int) $g->first()->branch_id);

        $byNormName = [];
        foreach ($idsByName as $norm => $ids) {
            foreach ($ids as $id) {
                if (isset($branchByEmployee[$id])) {
                    $byNormName[$norm] = $branchByEmployee[$id];
                    break;
                }
            }
        }

        return $byNormName;
    }

    /**
     * employee_id → llave de grupo para homónimos con rechazo de fusión explícito
     * ("nombre#idMenor" por persona). Vacío si no hay rechazos: agrupación sin cambios.
     * Usa CanonicalEmployeeResolver para agrupar y consulta employee_match_rejections.
     *
     * @param  Collection  $employees  Colaboradores del periodo.
     * @return array<int, string>
     */
    private function homonymGroupKeys(Collection $employees): array
    {
        if (! Schema::hasTable('employee_match_rejections') || ! DB::table('employee_match_rejections')->exists()) {
            return [];
        }

        $keys = [];
        $groups = app(CanonicalEmployeeResolver::class)->canonicalize($employees);
        foreach ($groups->groupBy(fn ($g) => (string) $employees->firstWhere('id', $g->id)?->normalized_name) as $name => $sameName) {
            if ($name === '' || $sameName->count() < 2) {
                continue;
            }
            foreach ($sameName as $g) {
                foreach ($g->employee_ids as $id) {
                    $keys[(int) $id] = $name.'#'.min($g->employee_ids);
                }
            }
        }

        return $keys;
    }

    /**
     * Normaliza un nombre de persona para comparar: ASCII, minúsculas, solo
     * letras/dígitos y espacios simples.
     *
     * @param  string|null  $value  Nombre crudo.
     * @return string Nombre normalizado ('' si viene vacío).
     */
    public static function normalizeHumanName(?string $value): string
    {
        return Str::of((string) $value)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]/', ' ')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->value();
    }
}
