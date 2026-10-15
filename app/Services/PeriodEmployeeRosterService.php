<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Period;
use App\Services\Roster\RosterIdentityResolver;
use App\Services\Roster\RosterMovementEvidenceCollector;
use App\Services\Roster\RosterMovementEvidenceCollector as Ev;
use App\Services\Roster\RosterMovementStatus as S;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Roster canónico de empleados activos por periodo — fuente ÚNICA para derivar
 * Rotación de Personal e IMSS patronal desde NOI Nómina + NOI Nómina Fiscal.
 * NUNCA usa el reporte de empleados Lendus para decidir quién está en nómina.
 * La sucursal se resuelve vía employee_branch_assignments del periodo (ya
 * poblado por EmployeeBranchAutoMatchService antes de que este servicio
 * corra), con fallback a la asignación histórica más reciente. Una persona con
 * varios registros de asignación sigue siendo UNA fila del roster.
 *
 * Identidad (cierre 2026-10, ver Roster\RosterIdentityResolver): la persona
 * se resuelve por identificadores — mismo nombre normalizado (NOI normal y
 * fiscal del mismo empleado; duplicados técnicos de una carga) o vínculo
 * conciliado (employee_aliases) — y NUNCA se fusionan dos homónimos con clave
 * NOI distinta (rechazo registrado por el importador). La llave persistida es
 * employee_key: igual al nombre normalizado salvo homónimos ("nombre#id").
 * El cruce con el periodo anterior se hace por employee_id (y por llave para
 * filas antiguas), nunca solo por nombre.
 *
 * Movimientos laborales (18-oct-2026): dejar de aparecer en NOI NO es una baja.
 * Cada fila lleva una categoría (reconciliation_status, ver
 * Roster\RosterMovementStatus) y la evidencia que la sostiene
 * (movement_evidence, con fuente y procedencia). movement_type='baja' queda
 * SOLO para bajas confirmadas (directorio laboral INACTIVO con corte válido
 * para el periodo, finiquito en NOI o conciliación autorizada). Un directorio
 * POSTERIOR al periodo nunca confirma una baja: solo se informa.
 *
 * Idempotente: siempre borra el roster del periodo antes de reinsertar. Las
 * decisiones de conciliación viven aparte (period_employee_movement_reviews) y
 * se vuelven a aplicar en cada reconstrucción.
 */
class PeriodEmployeeRosterService
{
    /** Caída de plantilla que sugiere archivo NOI incompleto, no bajas simultáneas. */
    private const MASS_DROP_RATIO = 0.20;
    private const MASS_DROP_MIN_PREV = 10;

    public function __construct(
        private readonly BranchResolverService $branchResolver,
        private readonly PersonIdentityResolverService $identityResolver,
        private readonly RosterMovementEvidenceCollector $evidence,
        private readonly RosterIdentityResolver $identities,
    ) {}

    /**
     * Calcula y PERSISTE el roster del periodo.
     *
     * @return array<string, mixed>
     */
    public function buildForPeriod(Period $period): array
    {
        $result = $this->computeForPeriod($period);
        $this->persist($period, $result['rows']);

        return $result['stats'];
    }

    /**
     * Calcula el roster del periodo SIN escribir nada (también lo usa
     * `reportes:audit-roster-periodo --dry-run/--diff` para diagnosticar en producción).
     *
     * @return array{rows: array<int, array<string, mixed>>, stats: array<string, mixed>}
     */
    public function computeForPeriod(Period $period): array
    {
        if (!$period->isMonthly()) {
            throw new \InvalidArgumentException('El roster de empleados solo se construye para periodos mensuales.');
        }

        $allPeriods = Period::all();
        $dataIds    = $this->dataIdsFor($period, $allPeriods);

        // ── 1. Empleados (employee_id crudo) con movimiento NOI este periodo, por fuente ──
        $byEmployeeId = $this->noiPresence($dataIds);
        if (empty($byEmployeeId)) {
            return ['rows' => [], 'stats' => $this->emptyStats()];
        }

        $currentSources = [
            'normal' => collect($byEmployeeId)->contains(fn ($f) => $f['normal']),
            'fiscal' => collect($byEmployeeId)->contains(fn ($f) => $f['fiscal']),
        ];

        $rawEmployeeIds = array_keys($byEmployeeId);
        $employees      = Employee::query()->whereIn('id', $rawEmployeeIds)->get(['id', 'full_name', 'normalized_name'])->keyBy('id');

        // ── 2. Identidad: personas reales del periodo (ver RosterIdentityResolver) ──
        $identity = $this->identities->group($employees->values());
        $byIdentity = [];
        foreach ($identity['groups'] as $key => $g) {
            $flags = ['normal' => false, 'fiscal' => false];
            foreach ($g['employee_ids'] as $eid) {
                $flags['normal'] = $flags['normal'] || ($byEmployeeId[$eid]['normal'] ?? false);
                $flags['fiscal'] = $flags['fiscal'] || ($byEmployeeId[$eid]['fiscal'] ?? false);
            }
            $byIdentity[(string) $key] = $g + $flags;
        }
        $presentIds = array_fill_keys($rawEmployeeIds, true);

        // ── 3. Periodo anterior — roster ya construido, cruzado por employee_id y llave ──
        $prevPeriod = $period->previousMonthly($allPeriods);
        $prevRows = $prevPeriod
            ? DB::table('period_employee_rosters')->where('period_id', $prevPeriod->id)->get()
            : collect();
        $prevActive = $prevRows->where('is_active_for_period', true)->keyBy(fn ($r) => $this->rowKey($r));
        $hasBaseline = $prevActive->isNotEmpty();

        $prevKeyById = [];
        foreach ($prevActive as $pk => $r) {
            foreach ($this->rowEmployeeIds($r) as $id) {
                $prevKeyById[$id] ??= (string) $pk;
            }
        }
        $prevKeyOf = [];
        foreach ($byIdentity as $key => $data) {
            $match = null;
            foreach ($data['employee_ids'] as $eid) {
                if (isset($prevKeyById[$eid])) { $match = $prevKeyById[$eid]; break; }
            }
            if ($match === null && $prevActive->has($key)) {
                $match = $key;
            }
            $prevKeyOf[$key] = $match;
        }
        $matchedPrev = array_flip(array_filter($prevKeyOf));

        // ── 4. Resolver sucursal: cualquier employee_id del grupo, periodo actual → histórico ──
        [$currentAssignments, $historicalMap, $branches] = $this->resolveBranchMaps($period, $rawEmployeeIds);

        // ── 5. Evidencia (solo lecturas) ──
        $absentKeys = $prevActive->keys()
            ->reject(fn ($k) => isset($matchedPrev[$k]))
            // Seguridad: si alguno de sus employee_id cobra este periodo, no está ausente.
            ->reject(fn ($k) => collect($this->rowEmployeeIds($prevActive[$k]))->contains(fn ($id) => isset($presentIds[$id])))
            ->map(fn ($k) => (string) $k)->values()->all();
        $newKeys = array_values(array_filter(array_keys($byIdentity), fn ($k) => $prevKeyOf[$k] === null));

        $absentIdentities = [];
        foreach ($absentKeys as $k) {
            $absentIdentities[$k] = $this->rowEmployeeIds($prevActive[$k]);
        }
        $newIdentities = [];
        foreach ($newKeys as $k) {
            $newIdentities[$k] = $byIdentity[$k]['employee_ids'];
        }

        $nameOf = [];
        foreach ($byIdentity as $k => $d) { $nameOf[$k] = $d['name']; }
        foreach ($absentKeys as $k) { $nameOf[$k] = (string) $prevActive[$k]->nombre_normalizado; }
        $nameCounts = array_count_values($nameOf);
        $allNames = array_values(array_unique($nameOf));

        $dirEvidence = $this->evidence->directoryEvidenceFor($period);
        $snapshot    = $dirEvidence['valid'];
        $later       = $dirEvidence['later'];
        $directory   = $this->evidence->directoryStatusByName($snapshot, $allNames);
        $laterDir    = $this->evidence->directoryStatusByName($later, $allNames);
        $prevDataIds = $prevPeriod ? $this->dataIdsFor($prevPeriod, $allPeriods) : [];
        $finiquitos  = $this->evidence->finiquitoEmployees(array_values(array_unique(array_merge($dataIds, $prevDataIds))));
        $activity    = $this->evidence->placementActivity($dataIds);
        $prevActivity = $this->evidence->placementActivity($prevDataIds);
        $reappears   = $this->evidence->reappearsLater($period, $absentIdentities);
        $priorRecord = $hasBaseline ? $this->evidence->lastPriorRecord($period, $newIdentities) : [];
        $reviews     = $this->evidence->latestReviews($period);

        $prevActiveCount = $prevActive->count();
        $dropRatio = $prevActiveCount > 0 ? (($prevActiveCount - count($byIdentity)) / $prevActiveCount) : 0.0;
        $massDrop  = $prevActiveCount >= self::MASS_DROP_MIN_PREV && $dropRatio >= self::MASS_DROP_RATIO;

        $ctx = compact('snapshot', 'later', 'directory', 'laterDir', 'finiquitos', 'activity', 'prevActivity', 'reappears', 'priorRecord', 'reviews', 'massDrop', 'dropRatio', 'currentSources', 'period', 'prevPeriod', 'nameCounts');

        // Duplicados probables: solo entre ausencias SIN confirmación y altas nuevas.
        $unconfirmedAbsent = [];
        foreach ($absentKeys as $k) {
            $name = $nameOf[$k];
            $confirmedByReview = ($this->reviewFor($k, $name, $ctx)?->decision ?? null) === S::DECISION_CONFIRMAR;
            $dir = $this->dirStatus($name, $ctx, 'directory');
            $isConfirmable = $confirmedByReview || $this->finiquitoFor($absentIdentities[$k], $ctx) !== null || (($dir['status'] ?? null) === 'INACTIVO');
            if (!$isConfirmable && !isset($reappears[$k])) {
                $unconfirmedAbsent[$k] = $prevActive[$k]->nombre_original;
            }
        }
        $altaCandidates = [];
        foreach ($newKeys as $k) {
            $altaCandidates[$k] = $byIdentity[$k]['nombre_original'];
        }
        $nameMatches = $hasBaseline ? $this->bestMutualNameMatches($unconfirmedAbsent, $altaCandidates, $nameOf) : [];
        $altaMatches = [];
        foreach ($nameMatches as $absentKey => $m) {
            // Si la ausencia ya se descartó en conciliación ("es la misma persona"), el alta
            // emparejada se trata como continuidad, no como alta ni como pendiente.
            $altaMatches[$m['key']] = ['nombre' => $unconfirmedAbsent[$absentKey], 'percent' => $m['percent'], 'resolved' => $this->reviewFor($absentKey, $nameOf[$absentKey], $ctx) !== null];
        }

        // Posibles duplicados SIN vínculo dentro del propio periodo (solo NOI normal vs solo fiscal).
        $nearDuplicates = $this->identities->unlinkedNearDuplicates(
            array_map(fn ($d) => ['name' => $d['name'], 'normal' => $d['normal'], 'fiscal' => $d['fiscal']], $byIdentity),
            array_map(fn ($d) => $d['nombre_original'], $byIdentity),
        );

        // ── 6. Filas presentes en la nómina del periodo ──
        $now = now();
        $rows = [];
        $sinSucursal = 0;
        foreach ($byIdentity as $key => $data) {
            $branchId = null;
            foreach ($data['employee_ids'] as $eid) {
                if (isset($currentAssignments[$eid])) { $branchId = (int) $currentAssignments[$eid]; break; }
            }
            if ($branchId === null) {
                foreach ($data['employee_ids'] as $eid) {
                    if (isset($historicalMap[$eid])) { $branchId = $historicalMap[$eid]; break; }
                }
            }
            $branch     = $branchId ? $branches->get($branchId) : null;
            $branchName = $branch?->name;
            if (!$branchId) $sinSucursal++;

            $prevKey = $prevKeyOf[$key];
            [$movement, $status, $note, $items] = $this->classifyPresent(
                (string) $key, $data, $branchName, $prevKey !== null ? $prevActive->get($prevKey) : null, $hasBaseline,
                $altaMatches[$key] ?? null, $nearDuplicates[$key] ?? null, $ctx,
            );

            $rows[] = $this->row($period, $data['employee_ids'][0], (string) $key, $data['name'], $data['nombre_original'], $branchId, $branchName,
                $branchName ? $this->branchResolver->isSheetBranch($branchName) : false,
                ($data['normal'] && $data['fiscal']) ? 'ambos' : ($data['fiscal'] ? 'noi_nomina_fiscal' : 'noi_nomina'),
                $data['normal'], $data['fiscal'], true, $movement, $status, $note,
                ['items' => $items, 'employee_ids' => $data['employee_ids'], 'identity' => ['key' => (string) $key, 'homonym' => $data['homonym'], 'links' => $data['links']]], $now);
        }

        // ── 7. Ausencias: activos el periodo anterior que no están en esta nómina ──
        foreach ($absentKeys as $key) {
            $prevRow = $prevActive[$key];
            [$movement, $status, $note, $items] = $this->classifyAbsent($key, $prevRow, $absentIdentities[$key], $nameMatches[$key] ?? null, $ctx);

            $rows[] = $this->row($period, (int) $prevRow->employee_id, $key, (string) $prevRow->nombre_normalizado, $prevRow->nombre_original, $prevRow->branch_id, $prevRow->branch_name,
                (bool) $prevRow->is_branch_operativa, $prevRow->source, false, false, false, $movement, $status, $note,
                ['items' => $items, 'employee_ids' => $absentIdentities[$key], 'identity' => ['key' => $key, 'homonym' => str_contains($key, '#'), 'links' => []]], $now);
        }

        $stats = $this->stats($rows, $prevPeriod?->id, $sinSucursal, $identity['stats'], $massDrop, $dropRatio, $snapshot, $later);

        return ['rows' => $rows, 'stats' => $stats];
    }

    /** @return array<int, array{normal:bool, fiscal:bool}> */
    private function noiPresence(array $dataIds): array
    {
        $movementRows = DB::table('fact_noi_movements as fnm')
            ->join('report_uploads as ru', 'fnm.report_upload_id', '=', 'ru.id')
            ->join('data_sources as ds', 'ru.data_source_id', '=', 'ds.id')
            ->whereIn('fnm.period_id', $dataIds)
            ->whereNotNull('fnm.employee_id')
            ->whereIn('ds.code', ['noi_nomina', 'noi_nomina_fiscal'])
            ->select('fnm.employee_id', 'ds.code as source_code')
            ->distinct()
            ->get();

        $byEmployeeId = [];
        foreach ($movementRows as $row) {
            $eid = (int) $row->employee_id;
            $byEmployeeId[$eid] ??= ['normal' => false, 'fiscal' => false];
            if ($row->source_code === 'noi_nomina') {
                $byEmployeeId[$eid]['normal'] = true;
            } elseif ($row->source_code === 'noi_nomina_fiscal') {
                $byEmployeeId[$eid]['fiscal'] = true;
            }
        }

        return $byEmployeeId;
    }

    /** Llave de identidad de una fila persistida (filas antiguas: el nombre). */
    private function rowKey(object $r): string
    {
        $key = trim((string) ($r->employee_key ?? ''));

        return $key !== '' ? $key : (string) $r->nombre_normalizado;
    }

    /** @return int[] employee_id de la fila + los del grupo guardados en la evidencia. */
    private function rowEmployeeIds(object $r): array
    {
        $ids = [(int) $r->employee_id];
        $evidence = isset($r->movement_evidence) && $r->movement_evidence ? json_decode((string) $r->movement_evidence, true) : null;
        foreach ((array) ($evidence['employee_ids'] ?? []) as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    /** Decisión autorizada por llave; filas antiguas se conciliaban por nombre. */
    private function reviewFor(string $key, string $name, array $ctx): ?object
    {
        return $ctx['reviews'][$key] ?? ($key !== $name || ($ctx['nameCounts'][$name] ?? 1) > 1 ? null : ($ctx['reviews'][$name] ?? null));
    }

    private function finiquitoFor(array $employeeIds, array $ctx): ?string
    {
        foreach ($employeeIds as $id) {
            if (isset($ctx['finiquitos'][$id])) {
                return $ctx['finiquitos'][$id];
            }
        }

        return null;
    }

    /**
     * Estatus del directorio para un nombre. Si en el roster hay más de una
     * persona con ese nombre (homónimos) el directorio no permite atribuirlo.
     */
    private function dirStatus(string $name, array $ctx, string $which): ?array
    {
        if (($ctx['nameCounts'][$name] ?? 1) > 1) {
            return null;
        }

        return $ctx[$which][$name] ?? null;
    }

    /** @return array<int, array{fuente:string, detalle:string}> */
    private function directoryItems(string $name, array $ctx, bool $mentionMissing): array
    {
        $items = [];
        if (($ctx['nameCounts'][$name] ?? 1) > 1 && ($ctx['snapshot'] || $ctx['later'])) {
            return [['fuente' => 'Directorio laboral', 'detalle' => 'Hay homónimos en el roster: el estatus del directorio no se puede atribuir a una sola persona.']];
        }
        if ($ctx['snapshot'] !== null) {
            $dir = $ctx['directory'][$name] ?? null;
            if ($dir || $mentionMissing) {
                $items[] = ['fuente' => 'Directorio laboral', 'detalle' => $dir
                    ? "{$dir['status']} en {$ctx['snapshot']['label']}."
                    : "No aparece en {$ctx['snapshot']['label']}."];
            }
        }
        $later = $ctx['laterDir'][$name] ?? null;
        if ($ctx['later'] !== null && $later !== null) {
            $items[] = ['fuente' => 'Directorio laboral (posterior)', 'detalle' => "{$later['status']} en {$ctx['later']['label']}. Solo informativo: un corte posterior no prueba la situación en {$ctx['period']->label}."];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array{nombre:string, percent:float, resolved?:bool}|null  $absentMatch
     * @param  array{key:string, nombre:string, percent:float}|null  $nearDup
     * @return array{0:string, 1:string, 2:string, 3:array<int, array{fuente:string, detalle:string}>}
     */
    private function classifyPresent(string $key, array $data, ?string $branchName, ?object $prevRow, bool $hasBaseline, ?array $absentMatch, ?array $nearDup, array $ctx): array
    {
        $name = $data['name'];
        $files = array_values(array_filter([$data['normal'] ? 'NOI Nómina' : null, $data['fiscal'] ? 'NOI Nómina Fiscal' : null]));
        $items = [['fuente' => 'NOI', 'detalle' => 'Presente en ' . implode(' + ', $files) . ' de ' . $ctx['period']->label . '.']];
        foreach ($data['links'] as $link) {
            $items[] = ['fuente' => 'Identidad', 'detalle' => $link . '.'];
        }
        if ($data['homonym']) {
            $items[] = ['fuente' => 'Identidad', 'detalle' => 'Homónimo con clave NOI distinta: se cuenta como persona separada.'];
        }
        $items = array_merge($items, $this->directoryItems($name, $ctx, false));
        $dir = $this->dirStatus($name, $ctx, 'directory');
        $finiquito = $this->finiquitoFor($data['employee_ids'], $ctx);
        // Cobrar en la nómina del periodo e INACTIVO en un corte POSTERIOR al cierre
        // no se contradice: la baja ocurrió después. Solo un corte dentro del
        // periodo (o anterior) que diga INACTIVO contradice el pago.
        $cutAfterPeriod = ($ctx['snapshot']['effective_cut'] ?? null) !== null
            && $ctx['snapshot']['effective_cut'] > $ctx['period']->end_date->toDateString();
        $dirInactive = ($dir['status'] ?? null) === 'INACTIVO';
        if ($dirInactive && $cutAfterPeriod) {
            $items[] = ['fuente' => 'Directorio laboral', 'detalle' => "El corte ({$ctx['snapshot']['effective_cut']}) es posterior al cierre del periodo: su INACTIVO indica una baja posterior, no contradice el pago de {$ctx['period']->label}."];
        }
        $dirInactiveWithoutFiniquito = $dirInactive && !$cutAfterPeriod && $finiquito === null;

        if ($nearDup !== null) {
            $items[] = ['fuente' => 'Identidad', 'detalle' => sprintf('Nombre %.0f%% similar a "%s" del mismo periodo (uno solo en NOI normal, otro solo en NOI fiscal) sin vínculo conciliado.', $nearDup['percent'], $nearDup['nombre'])];

            return [S::MOVEMENT_ACTIVO, S::REGISTRO_DUPLICADO, sprintf(
                'Posible registro duplicado de "%s" (%.0f%% similar). No se fusiona sin evidencia: concilia la identidad (alias) para contarlo una sola vez.',
                $nearDup['nombre'], $nearDup['percent'],
            ), $items];
        }

        if ($prevRow !== null) {
            if ($dirInactiveWithoutFiniquito) {
                return [S::MOVEMENT_ACTIVO, S::CONTRADICCION_FUENTES,
                    'Cobra en la nómina NOI del periodo, pero el directorio laboral válido para el periodo lo marca INACTIVO. Requiere conciliación: no se da de baja automáticamente.', $items];
            }
            $prevBranch = $prevRow->branch_name ? mb_strtoupper(trim($prevRow->branch_name)) : null;
            if ($prevBranch !== null && $branchName !== null && $prevBranch !== mb_strtoupper(trim($branchName))) {
                $items[] = ['fuente' => 'Asignación de sucursal', 'detalle' => "Antes {$prevRow->branch_name}, ahora {$branchName}."];

                return [S::MOVEMENT_ACTIVO, S::CAMBIO_SUCURSAL, "Cambió de sucursal: {$prevRow->branch_name} → {$branchName}. No es alta ni baja.", $items];
            }
            if ($this->lacksCommercialActivity($name, $dir, $ctx)) {
                $items[] = ['fuente' => 'Lendus comercial', 'detalle' => 'Sin colocación registrada en el periodo.'];

                return [S::MOVEMENT_ACTIVO, S::SIN_ACTIVIDAD_COMERCIAL, 'Sigue en nómina; no registró colocación este periodo. Dato informativo: no es una baja.', $items];
            }

            return [S::MOVEMENT_ACTIVO, S::ACTIVO_CONFIRMADO, 'En nómina este periodo y el anterior.', $items];
        }

        if (!$hasBaseline) {
            return [S::MOVEMENT_ACTIVO, S::PLANTILLA_INICIAL,
                'No hay roster del periodo anterior para comparar: se registra como plantilla, no como alta.', $items];
        }

        if ($absentMatch !== null) {
            $items[] = ['fuente' => 'Roster', 'detalle' => sprintf('Nombre %.0f%% similar a "%s", que dejó de aparecer este periodo.', $absentMatch['percent'], $absentMatch['nombre'])];
            if ($absentMatch['resolved'] ?? false) {
                return [S::MOVEMENT_ACTIVO, S::ACTIVO_CONFIRMADO, "Conciliado: es la misma persona que \"{$absentMatch['nombre']}\" (nombre capturado distinto). Continúa en nómina.", $items];
            }

            return [S::MOVEMENT_ACTIVO, S::REGISTRO_DUPLICADO, sprintf(
                'Aparece como nuevo, pero su nombre es %.0f%% similar a "%s" (ausente este periodo). Probablemente es la misma persona capturada distinto: no se cuenta como alta hasta conciliar.',
                $absentMatch['percent'], $absentMatch['nombre'],
            ), $items];
        }

        $prior = $ctx['priorRecord'][$key] ?? null;
        // Tras una baja confirmada, un directorio ANTERIOR al periodo todavía lo
        // muestra INACTIVO: es lo esperado en un reingreso, no una contradicción.
        $expectedInactive = ($prior['confirmed_baja'] ?? false) && ($ctx['snapshot']['kind'] ?? null) === Ev::DIR_PRIOR;
        if ($dirInactiveWithoutFiniquito && !$expectedInactive) {
            return [S::MOVEMENT_ALTA, S::CONTRADICCION_FUENTES,
                'Aparece nuevo en la nómina NOI, pero el directorio laboral válido para el periodo lo marca INACTIVO. Requiere conciliación.', $items];
        }

        if ($prior !== null) {
            if ($prior['confirmed_baja']) {
                $items[] = ['fuente' => 'Roster', 'detalle' => "Baja confirmada en {$prior['period']}."];

                return [S::MOVEMENT_ALTA, S::REINGRESO, "Reingreso: tuvo baja confirmada en {$prior['period']} y vuelve a nómina.", $items];
            }
            $items[] = ['fuente' => 'Roster', 'detalle' => "Último registro anterior: {$prior['period']} (" . S::label($prior['status']) . ').'];

            return [S::MOVEMENT_ACTIVO, S::RETORNO_TRAS_AUSENCIA,
                "Vuelve a nómina tras una ausencia sin baja confirmada (último registro: {$prior['period']}). No cuenta como alta.", $items];
        }

        return [S::MOVEMENT_ALTA, S::ALTA_CONFIRMADA, 'Aparece por primera vez en la nómina NOI de este periodo.', $items];
    }

    /**
     * @param  int[]  $employeeIds
     * @param  array{key:string, nombre:string, percent:float}|null  $altaMatch
     * @return array{0:string, 1:string, 2:string, 3:array<int, array{fuente:string, detalle:string}>}
     */
    private function classifyAbsent(string $key, object $prevRow, array $employeeIds, ?array $altaMatch, array $ctx): array
    {
        $name = (string) $prevRow->nombre_normalizado;
        $prevLabel = $ctx['prevPeriod']?->label ?? 'el periodo anterior';
        $items = [['fuente' => 'NOI', 'detalle' => "En nómina de {$prevLabel}; sin registro en {$ctx['period']->label}."]];
        $items = array_merge($items, $this->directoryItems($name, $ctx, true));
        $dir = $this->dirStatus($name, $ctx, 'directory');
        $status = $dir['status'] ?? null;
        $kind = $ctx['snapshot']['kind'] ?? null;

        // 1. Conciliación autorizada — siempre manda.
        $review = $this->reviewFor($key, $name, $ctx);
        if ($review !== null) {
            $who = $review->reviewer ?: 'usuario autorizado';
            $when = substr((string) $review->created_at, 0, 16);
            $items[] = ['fuente' => 'Conciliación', 'detalle' => "{$who} · {$when}" . ($review->note ? " · {$review->note}" : '')];

            return $review->decision === S::DECISION_CONFIRMAR
                ? [S::MOVEMENT_BAJA, S::BAJA_CONFIRMADA, "Baja confirmada por conciliación autorizada ({$who}, {$when}).", $items]
                : [S::MOVEMENT_AUSENCIA, S::AUSENCIA_JUSTIFICADA, "Ausencia descartada como baja por conciliación autorizada ({$who}, {$when}).", $items];
        }

        // 2. Vuelve a nómina en un periodo posterior → no fue baja.
        if (isset($ctx['reappears'][$key])) {
            $items[] = ['fuente' => 'Roster', 'detalle' => "Vuelve a nómina en {$ctx['reappears'][$key]}."];

            return [S::MOVEMENT_AUSENCIA, S::NO_LOCALIZADO, "No aparece en esta nómina, pero vuelve en {$ctx['reappears'][$key]}: ausencia temporal (vacaciones, incapacidad u omisión), no baja.", $items];
        }

        // 3. Finiquito en NOI.
        $finiquito = $this->finiquitoFor($employeeIds, $ctx);
        if ($finiquito !== null) {
            $items[] = ['fuente' => 'NOI', 'detalle' => "Concepto de finiquito: {$finiquito}."];
            if (!($status === 'ACTIVO' && $kind === Ev::DIR_PERIOD)) {
                return [S::MOVEMENT_BAJA, S::BAJA_CONFIRMADA, "Baja confirmada: NOI registra {$finiquito}.", $items];
            }

            return [S::MOVEMENT_AUSENCIA, S::CONTRADICCION_FUENTES, 'NOI registra finiquito pero el directorio laboral del periodo lo mantiene ACTIVO. Requiere conciliación.', $items];
        }

        // 4. Directorio laboral VÁLIDO para el periodo marca INACTIVO → baja confirmada.
        if ($status === 'INACTIVO') {
            $when = $kind === Ev::DIR_PRIOR ? ' (a más tardar en su corte)' : '';

            return [S::MOVEMENT_BAJA, S::BAJA_CONFIRMADA, "Baja confirmada{$when}: no aparece en la nómina y el directorio laboral lo marca INACTIVO ({$ctx['snapshot']['label']}).", $items];
        }

        // 5. Probable mismo colaborador con nombre capturado distinto.
        if ($altaMatch !== null) {
            $items[] = ['fuente' => 'Roster', 'detalle' => sprintf('Alta nueva "%s" con nombre %.0f%% similar.', $altaMatch['nombre'], $altaMatch['percent'])];

            return [S::MOVEMENT_AUSENCIA, S::REGISTRO_DUPLICADO, sprintf(
                'Dejó de aparecer, pero "%s" es un alta nueva con nombre %.0f%% similar: probablemente es la misma persona capturada distinto.',
                $altaMatch['nombre'], $altaMatch['percent'],
            ), $items];
        }

        // 6. NOI y directorio DEL PERIODO se contradicen → incidencia, nunca baja automática.
        //    (Un ACTIVO de un directorio anterior al periodo es viejo: no contradice.)
        if ($status === 'ACTIVO' && $kind === Ev::DIR_PERIOD) {
            return [S::MOVEMENT_AUSENCIA, S::CONTRADICCION_FUENTES, 'No aparece en la nómina NOI, pero el directorio laboral del periodo lo mantiene ACTIVO. Requiere conciliación: no se da de baja automáticamente.', $items];
        }

        // 7. Sigue colocando en Lendus.
        if ($this->evidence->hasCommercialActivity($ctx['activity'], $name, $dir['codes'] ?? [])) {
            $items[] = ['fuente' => 'Lendus comercial', 'detalle' => 'Registra colocación en el periodo.'];

            return [S::MOVEMENT_AUSENCIA, S::NO_LOCALIZADO, 'No aparece en la nómina NOI, pero registró colocación en el periodo. No se considera baja.', $items];
        }

        // 8. Archivo NOI faltante / incompleto.
        $missingFile = ($prevRow->source === 'noi_nomina_fiscal' && !$ctx['currentSources']['fiscal'])
            || ($prevRow->source === 'noi_nomina' && !$ctx['currentSources']['normal']);
        if ($missingFile) {
            $file = $prevRow->source === 'noi_nomina_fiscal' ? 'NOI Nómina Fiscal' : 'NOI Nómina';
            $items[] = ['fuente' => 'NOI', 'detalle' => "{$file} no está cargado en {$ctx['period']->label}."];

            return [S::MOVEMENT_AUSENCIA, S::NO_LOCALIZADO, "Solo aparecía en {$file}, que no está cargado en este periodo.", $items];
        }
        if ($ctx['massDrop']) {
            $items[] = ['fuente' => 'NOI', 'detalle' => sprintf('La plantilla cayó %.0f%% respecto al periodo anterior.', $ctx['dropRatio'] * 100)];

            return [S::MOVEMENT_AUSENCIA, S::NO_LOCALIZADO, sprintf(
                'La plantilla cayó %.0f%% respecto al periodo anterior: probable archivo NOI incompleto, no bajas simultáneas. Verifica la carga antes de confirmar.',
                $ctx['dropRatio'] * 100,
            ), $items];
        }

        // 9. Solo hay evidencia POSTERIOR al periodo: se informa, nunca confirma.
        $laterStatus = $this->dirStatus($name, $ctx, 'laterDir')['status'] ?? null;
        if ($laterStatus === 'INACTIVO') {
            return [S::MOVEMENT_AUSENCIA, S::POSIBLE_BAJA,
                "Posible baja: no aparece en la nómina y un directorio POSTERIOR al periodo lo marca INACTIVO ({$ctx['later']['label']}), sin fecha efectiva de baja. Confírmala o descártala en la conciliación.", $items];
        }
        if ($laterStatus === 'ACTIVO') {
            return [S::MOVEMENT_AUSENCIA, S::NO_LOCALIZADO,
                "No aparece en la nómina, pero un directorio posterior lo muestra ACTIVO ({$ctx['later']['label']}): ausencia temporal o reingreso, no baja.", $items];
        }

        // 10. Sin evidencia a favor ni en contra.
        if ($ctx['snapshot'] === null) {
            return [S::MOVEMENT_AUSENCIA, S::VIGENCIA_DESCONOCIDA, 'No aparece en la nómina y no hay un directorio laboral con corte válido para este periodo: vigencia desconocida.', $items];
        }

        return [S::MOVEMENT_AUSENCIA, S::POSIBLE_BAJA, 'No aparece en la nómina ni como INACTIVO en el directorio laboral del periodo. Posible baja: confírmala o descártala en la conciliación.', $items];
    }

    private function lacksCommercialActivity(string $name, ?array $dir, array $ctx): bool
    {
        if (!$ctx['activity']['loaded']) {
            return false; // sin colocación cargada no se puede afirmar nada
        }
        $codes = $dir['codes'] ?? [];
        $isCommercial = $this->evidence->isCommercialPosition($dir['puesto'] ?? null)
            || $this->evidence->hasCommercialActivity($ctx['prevActivity'], $name, $codes);

        return $isCommercial && !$this->evidence->hasCommercialActivity($ctx['activity'], $name, $codes);
    }

    private function row(Period $period, int $employeeId, string $key, string $name, ?string $original, $branchId, ?string $branchName, bool $operativa, ?string $source,
        bool $normal, bool $fiscal, bool $active, string $movement, string $status, string $note, array $evidence, $now): array
    {
        return [
            'period_id'                => $period->id,
            'employee_id'              => $employeeId,
            'employee_key'             => $key,
            'nombre_normalizado'       => $name,
            'nombre_original'          => $original,
            'branch_id'                => $branchId ? (int) $branchId : null,
            'branch_name'              => $branchName,
            'is_branch_operativa'      => $operativa,
            'source'                   => $source ?: 'noi_nomina',
            'appears_in_nomina_normal' => $normal,
            'appears_in_nomina_fiscal' => $fiscal,
            'is_active_for_period'     => $active,
            'movement_type'            => $movement,
            'reconciliation_status'    => $status,
            'reconciliation_note'      => $note,
            'movement_evidence'        => json_encode($evidence, JSON_UNESCAPED_UNICODE),
            'requires_review'          => S::isPending($status),
            'created_at'               => $now,
            'updated_at'               => $now,
        ];
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function persist(Period $period, array $rows): void
    {
        // Despliegue en curso (migración 2026_10_18 aún sin correr): se omiten las columnas nuevas.
        $hasEvidence = Schema::hasColumn('period_employee_rosters', 'movement_evidence');
        if (!$hasEvidence) {
            $rows = array_map(fn ($r) => array_diff_key($r, ['movement_evidence' => 1, 'requires_review' => 1]), $rows);
        }

        DB::transaction(function () use ($period, $rows) {
            DB::table('period_employee_rosters')->where('period_id', $period->id)->delete();
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('period_employee_rosters')->insert($chunk);
            }
        });
    }

    /**
     * Cifras del periodo — cada una cuenta EXACTAMENTE lo que dice su nombre.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function stats(array $rows, ?int $prevPeriodId, int $sinSucursal, array $identityStats, bool $massDrop, float $dropRatio, ?array $snapshot, ?array $later): array
    {
        $c = collect($rows);
        $active = $c->where('is_active_for_period', true);
        $absent = $c->where('is_active_for_period', false);

        return [
            // Personas (deduplicadas) en NOI normal o fiscal del periodo.
            'active_count'          => $active->count(),
            'con_sucursal'          => $active->whereNotNull('branch_id')->count(),
            'sin_sucursal'          => $sinSucursal,
            // movement_type='alta' = altas confirmadas + reingresos + altas con contradicción (lo que usa Rotación).
            'altas'                 => $c->where('movement_type', S::MOVEMENT_ALTA)->count(),
            'altas_confirmadas'     => $c->where('reconciliation_status', S::ALTA_CONFIRMADA)->count(),
            'reingresos'            => $c->where('reconciliation_status', S::REINGRESO)->count(),
            'cambios_sucursal'      => $c->where('reconciliation_status', S::CAMBIO_SUCURSAL)->count(),
            'retornos'              => $c->where('reconciliation_status', S::RETORNO_TRAS_AUSENCIA)->count(),
            // Solo bajas CONFIRMADAS — lo que cuentan Rotación y reportes.
            'bajas'                 => $c->where('movement_type', S::MOVEMENT_BAJA)->count(),
            'ausencias'             => $absent->count(),
            'posibles_bajas'        => $absent->where('reconciliation_status', S::POSIBLE_BAJA)->count(),
            'ausencias_temporales'  => $absent->whereIn('reconciliation_status', [S::NO_LOCALIZADO, S::AUSENCIA_JUSTIFICADA])->count(),
            'bajas_pendientes'      => $absent->where('requires_review', true)->count(),
            'pendientes_conciliacion' => $c->where('requires_review', true)->count(),
            'contradicciones'       => $c->where('reconciliation_status', S::CONTRADICCION_FUENTES)->count(),
            'por_categoria'         => $c->countBy('reconciliation_status')->all(),
            'prev_period_id'        => $prevPeriodId,
            'duplicados_fusionados' => (int) ($identityStats['merged_same_name'] ?? 0) + (int) ($identityStats['merged_by_alias'] ?? 0),
            'vinculos_alias'        => (int) ($identityStats['merged_by_alias'] ?? 0),
            'homonimos_separados'   => (int) ($identityStats['homonyms_split'] ?? 0),
            'mass_drop_detected'    => $massDrop,
            'drop_ratio'            => round($dropRatio, 4),
            'posibles_coincidencias_nombre' => $c->where('reconciliation_status', S::REGISTRO_DUPLICADO)->where('is_active_for_period', false)->count(),
            'posibles_duplicados_presentes' => $active->where('reconciliation_status', S::REGISTRO_DUPLICADO)->count(),
            'directorio_laboral'    => $snapshot['label'] ?? null,
            'directorio_tipo'       => $snapshot['kind'] ?? null,
            'directorio_posterior'  => $later['label'] ?? null,
        ];
    }

    private function emptyStats(): array
    {
        return [
            'active_count' => 0, 'con_sucursal' => 0, 'sin_sucursal' => 0, 'altas' => 0, 'altas_confirmadas' => 0, 'reingresos' => 0,
            'cambios_sucursal' => 0, 'retornos' => 0, 'bajas' => 0, 'ausencias' => 0, 'posibles_bajas' => 0, 'ausencias_temporales' => 0,
            'bajas_pendientes' => 0, 'pendientes_conciliacion' => 0, 'contradicciones' => 0, 'por_categoria' => [], 'prev_period_id' => null,
            'duplicados_fusionados' => 0, 'vinculos_alias' => 0, 'homonimos_separados' => 0, 'mass_drop_detected' => false, 'drop_ratio' => 0.0,
            'posibles_coincidencias_nombre' => 0, 'posibles_duplicados_presentes' => 0, 'directorio_laboral' => null, 'directorio_tipo' => null,
            'directorio_posterior' => null,
        ];
    }

    /** @return int[] periodos semanales base + el propio mes (donde quedan los fact_* reales). */
    private function dataIdsFor(Period $period, $allPeriods): array
    {
        $weeklyIds = $period->resolveBaseWeeklyIds($allPeriods);

        return array_values(array_unique(array_merge(empty($weeklyIds) ? [] : $weeklyIds, [$period->id])));
    }

    /** @return array{0: \Illuminate\Support\Collection, 1: array<int,int>, 2: \Illuminate\Support\Collection} */
    private function resolveBranchMaps(Period $period, array $rawEmployeeIds): array
    {
        $currentAssignments = DB::table('employee_branch_assignments')
            ->where('period_id', $period->id)
            ->whereIn('employee_id', $rawEmployeeIds)
            ->whereNotNull('branch_id')
            ->pluck('branch_id', 'employee_id');

        $historicalMap = [];
        $needFallback = array_values(array_diff($rawEmployeeIds, $currentAssignments->keys()->all()));
        if (!empty($needFallback)) {
            $historyRows = DB::table('employee_branch_assignments')
                ->whereIn('employee_id', $needFallback)
                ->whereNotNull('branch_id')
                ->orderByDesc('period_id')
                ->get(['employee_id', 'branch_id']);
            foreach ($historyRows as $hr) {
                $historicalMap[(int) $hr->employee_id] ??= (int) $hr->branch_id;
            }
        }

        $allBranchIds = array_values(array_unique(array_filter(array_merge(
            $currentAssignments->values()->all(),
            array_values($historicalMap)
        ))));
        $branches = empty($allBranchIds) ? collect() : Branch::query()->whereIn('id', $allBranchIds)->get()->keyBy('id');

        return [$currentAssignments, $historicalMap, $branches];
    }

    /**
     * Empareja cada ausencia sin confirmar con, a lo mucho, UNA alta nueva — solo
     * cuando cada una es el MEJOR candidato de la otra (mutuo) y ≥ 85% similar.
     * Nombres con plantilla compartida ("Empleado Prueba 8/9/10") superan el
     * umbral contra la MISMA alta sin ser la misma persona; exigir el mejor
     * candidato en ambos sentidos filtra esos falsos positivos.
     *
     * @param array<string,string> $absentCandidates llave => nombreOriginal
     * @param array<string,string> $altaCandidates llave => nombreOriginal
     * @param array<string,string> $nameOf llave => nombre normalizado
     * @return array<string,array{key:string,nombre:string,percent:float}> ausente => mejor alta
     */
    private function bestMutualNameMatches(array $absentCandidates, array $altaCandidates, array $nameOf): array
    {
        if (empty($absentCandidates) || empty($altaCandidates)) {
            return [];
        }

        $bestAltaForAbsent = [];
        $bestAbsentForAlta = [];

        foreach ($absentCandidates as $absentKey => $_) {
            foreach ($altaCandidates as $altaKey => $__) {
                similar_text($nameOf[$absentKey] ?? (string) $absentKey, $nameOf[$altaKey] ?? (string) $altaKey, $percent);

                if (!isset($bestAltaForAbsent[$absentKey]) || $percent > $bestAltaForAbsent[$absentKey][1]) {
                    $bestAltaForAbsent[$absentKey] = [$altaKey, $percent];
                }
                if (!isset($bestAbsentForAlta[$altaKey]) || $percent > $bestAbsentForAlta[$altaKey][1]) {
                    $bestAbsentForAlta[$altaKey] = [$absentKey, $percent];
                }
            }
        }

        $matches = [];
        foreach ($bestAltaForAbsent as $absentKey => [$altaKey, $percent]) {
            $isMutualBest = ($bestAbsentForAlta[$altaKey][0] ?? null) === $absentKey;
            if ($isMutualBest && $percent >= 85.0) {
                $matches[$absentKey] = ['key' => (string) $altaKey, 'nombre' => $altaCandidates[$altaKey], 'percent' => $percent];
            }
        }

        return $matches;
    }

    /**
     * Colaboradores válidos y deduplicados para ESTE periodo — fuente única para
     * selectores UI (ej. Etapa 4 "Buscar empleado o gestor"). A diferencia de
     * buildForPeriod() (que persiste en period_employee_rosters y calcula altas/bajas,
     * solo para periodos mensuales), este método funciona para cualquier tipo de
     * periodo y nunca escribe en BD:
     *   - Si el periodo es mensual y ya tiene roster persistido (se construye durante
     *     "Actualizar BD" — ver RotacionDerivedFromNoiService/ImssFromNoiFiscalService),
     *     lo reutiliza tal cual: cero recálculo.
     *   - En cualquier otro caso (periodo semanal, o mensual sin roster todavía)
     *     recalcula en memoria con la MISMA identidad (RosterIdentityResolver) y
     *     resolución de sucursal que buildForPeriod().
     *
     * NUNCA usar Employee::all()/where('is_active',true) para poblar un selector de
     * colaboradores — eso mezcla bajas históricas de cualquier periodo y no deduplica
     * por identidad real (ver auditoría 2026-08-24: Etapa 4 mostraba a la misma
     * persona dos veces con una ruta como si fuera sucursal).
     *
     * @return array{rows: array<int, array{employee_id:int,name:string,branch_id:?int,branch_name:?string,is_branch_operativa:bool}>, sin_sucursal:int}
     */
    public function rosterRowsForSelector(Period $period): array
    {
        if ($period->isMonthly()) {
            $persisted = DB::table('period_employee_rosters')
                ->where('period_id', $period->id)
                ->where('is_active_for_period', true)
                ->orderBy('nombre_normalizado')
                ->get(['employee_id', 'nombre_normalizado', 'nombre_original', 'branch_id', 'branch_name', 'is_branch_operativa']);

            if ($persisted->isNotEmpty()) {
                return $this->formatSelectorRows($persisted->map(fn ($r) => [
                    'employee_id' => (int) $r->employee_id,
                    'name'        => $r->nombre_original,
                    'branch_id'   => $r->branch_id ? (int) $r->branch_id : null,
                    'branch_name' => $r->branch_name,
                    'is_branch_operativa' => (bool) $r->is_branch_operativa,
                ])->all());
            }
        }

        return $this->formatSelectorRows($this->computeRosterRowsOnTheFly($period));
    }

    private function formatSelectorRows(array $rows): array
    {
        $sinSucursal = 0;
        foreach ($rows as $r) {
            if (!$r['branch_id'] || !$r['is_branch_operativa']) {
                $sinSucursal++;
            }
        }
        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return ['rows' => $rows, 'sin_sucursal' => $sinSucursal];
    }

    /**
     * Misma identidad/resolución de sucursal que buildForPeriod(), pero sin
     * persistir y sin calcular altas/bajas — usable para periodos no mensuales.
     */
    private function computeRosterRowsOnTheFly(Period $period): array
    {
        $dataIds = $this->dataIdsFor($period, Period::all());

        $movementRows = DB::table('fact_noi_movements as fnm')
            ->whereIn('fnm.period_id', $dataIds)
            ->whereNotNull('fnm.employee_id')
            ->select('fnm.employee_id')
            ->distinct()
            ->pluck('employee_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($movementRows->isEmpty()) {
            return [];
        }

        $employees = Employee::query()->whereIn('id', $movementRows)->get(['id', 'full_name', 'normalized_name']);
        $groups = $this->identities->group($employees)['groups'];

        [$currentAssignments, $historicalMap, $branches] = $this->resolveBranchMaps($period, $movementRows->all());

        $rows = [];
        foreach ($groups as $data) {
            $branchId = null;
            foreach ($data['employee_ids'] as $eid) {
                if (isset($currentAssignments[$eid])) { $branchId = (int) $currentAssignments[$eid]; break; }
            }
            if ($branchId === null) {
                foreach ($data['employee_ids'] as $eid) {
                    if (isset($historicalMap[$eid])) { $branchId = $historicalMap[$eid]; break; }
                }
            }
            $branch     = $branchId ? $branches->get($branchId) : null;
            $branchName = $branch?->name;

            $rows[] = [
                'employee_id' => $data['employee_ids'][0],
                'name'        => $data['nombre_original'],
                'branch_id'   => $branchId,
                'branch_name' => $branchName,
                'is_branch_operativa' => $branchName ? $this->branchResolver->isSheetBranch($branchName) : false,
            ];
        }

        return $rows;
    }
}
