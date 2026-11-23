<?php

namespace App\Http\Controllers;

use App\Enums\MatchType;
use App\Enums\SourceType;
use App\Models\Branch;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Services\Access\ActionPermissionService;
use App\Services\Access\DataScopeService;
use App\Services\BranchResolverService;
use App\Services\EmployeeBranchAutoMatchService;
use App\Services\EmployeeNameCanonicalizer;
use App\Services\Roster\RosterMovementReviewService;
use App\Services\Roster\RosterMovementsPresenter;
use App\Services\Roster\RosterMovementStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Módulo "Asignación de sucursal" / Colaboradores (página Inertia
 * AsignacionSucursal/Index).
 *
 * Cada fila de employee_branch_assignments dice a qué sucursal pertenece un
 * colaborador en un periodo mensual; de esa asignación dependen la nómina,
 * gastos y efectividad por sucursal de la radiografía. Aquí se consulta la
 * asignación del periodo (deduplicada por persona real), las altas/bajas del
 * roster, se corre el cruce automático, se corrige a mano y se concilian
 * ausencias de nómina (confirmar o descartar una baja).
 *
 * Todo respeta el alcance por sucursal del usuario (DataScopeService).
 */
class EmployeeBranchAssignmentController extends Controller
{
    /**
     * Muestra las asignaciones del periodo mensual elegido (por defecto el más
     * reciente, nunca periodos con año futuro de prueba).
     *
     * Deduplica variantes de nombre (typos, NOI regular vs. fiscal) con
     * EmployeeNameCanonicalizer: una fila por persona canónica con sus alias
     * anidados. Arma incidencias (pendiente, sin match, confianza baja o sin
     * sucursal), resumen de conteos, y altas/bajas/plantilla leídas del roster
     * canónico (period_employee_rosters vía RosterMovementsPresenter): solo las
     * bajas confirmadas con evidencia cuentan como bajas; las ausencias van a
     * "pendientes de conciliación".
     *
     * @param  Request  $request  `period_id` opcional y `branch_id` opcional (filtro de
     *                            sucursal; debe estar dentro del alcance del usuario).
     * @return Response Página Inertia con assignments, branches, periods, summary,
     *                  incidences, hires, leavers, labor, labor_history y can_reconcile.
     *
     * Lanza 403 si `branch_id` está fuera del alcance. Solo lectura.
     */
    public function index(Request $request): Response
    {
        // Only monthly periods are valid for employee–branch assignments — nunca un
        // periodo "de prueba"/migración con año absurdo (ej. 2099, "Test Migracion
        // 1406" en BD de desarrollo) apareciendo primero y seleccionado por defecto
        // (mismo filtro que DashboardController::availablePeriods(), cierre 17-sep-2026
        // ronda 5).
        $periods = Period::query()
            ->where('type', 'monthly')
            ->where('year', '<=', now()->year)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('sequence')
            ->get(['id', 'name', 'custom_name', 'code', 'type', 'year', 'month', 'sequence', 'start_date', 'end_date']);

        $selectedPeriod = $periods->firstWhere('id', (int) $request->integer('period_id'))
            ?? $periods->first();

        $assignments = collect();

        // Only real assignable branches (operational + CORPORATIVO, no routes)
        $resolver = app(BranchResolverService::class);
        $realBranchNames = $resolver->realAssignableBranches();
        $branches = Branch::query()
            ->whereIn(DB::raw('UPPER(name)'), $realBranchNames)
            ->orderBy('name')
            ->get(['id', 'name']);

        // Fase 6.4 — alcance por sucursal: un usuario restringido solo ve (y asigna a)
        // sus sucursales; nunca personas de otras sucursales ni sin sucursal.
        $dataScope = app(DataScopeService::class);
        $allowedBranchIds = $dataScope->allowedBranchIds($request->user());
        if ($allowedBranchIds !== null) {
            $branches = $branches->whereIn('id', $allowedBranchIds)->values();
        }

        // Filtro de sucursal elegido en pantalla (`branch_id`): aplica a conteos, listas,
        // plantilla e historial — también para alcance general (antes un admin con
        // ATLIXCO elegido recibía la plantilla de toda la empresa). Una sucursal fuera
        // del alcance → 403 con mensaje claro.
        $selectedBranchId = $request->integer('branch_id') ?: null;
        if ($selectedBranchId !== null) {
            $dataScope->authorizeBranch($request->user(), $selectedBranchId);
        }
        $scopeBranchIds = $selectedBranchId !== null ? [$selectedBranchId] : $allowedBranchIds;

        $summary = [
            'total' => 0,
            'matched' => 0,
            'manual' => 0,
            'pending' => 0,
            'unmatched' => 0,
            'with_branch' => 0,
            'without_branch' => 0,
            'high_confidence' => 0,
            'needs_review' => 0,
            'hires' => 0,
            'leavers' => 0,
            'leavers_confirmadas' => 0,
            'leavers_pendientes' => 0,
            'pendientes_conciliacion' => 0,
            'plantilla' => 0,
            'roster_calculado' => false,
            'mass_drop_detected' => false,
            'drop_ratio' => 0.0,
        ];

        $hires = collect();
        $leavers = collect();
        $incidences = collect();
        $labor = null;
        $laborHistory = [];

        if ($selectedPeriod) {
            $rawAssignments = EmployeeBranchAssignment::query()
                ->with([
                    'employee:id,full_name,normalized_name,position',
                    'branch:id,name',
                    'period:id,name,custom_name,code,type,year,month,sequence,start_date,end_date',
                ])
                ->where('period_id', $selectedPeriod->id)
                ->when($scopeBranchIds !== null, fn ($q) => $q->whereIn('branch_id', $scopeBranchIds))
                ->orderByDesc('updated_at')
                ->get();

            // Build canonical map over employee names to detect typo variants
            $canonicalizer = app(EmployeeNameCanonicalizer::class);
            $allNames = $rawAssignments
                ->map(fn ($a) => $a->employee?->full_name)
                ->filter()
                ->unique()
                ->values()
                ->all();
            $canonicalMap = $canonicalizer->buildCanonicalMap($allNames);

            // Index assignments by normalized employee name
            $byNorm = $rawAssignments->keyBy(fn ($a) => $canonicalizer->normalize($a->employee?->full_name ?? ''));

            // Build deduplicated list: one row per canonical, aliases nested inside
            $seen = [];
            $assignments = collect();

            foreach ($rawAssignments as $assignment) {
                $empName = $assignment->employee?->full_name ?? '';
                $norm = $canonicalizer->normalize($empName);
                $canonical = $canonicalMap[$norm] ?? $norm;

                // Skip if we already emitted this canonical
                if (isset($seen[$canonical])) {
                    continue;
                }
                $seen[$canonical] = true;

                // Find all aliases for this canonical (normalized keys that map to it)
                $aliasNorms = array_values(array_filter(
                    array_keys($canonicalMap),
                    fn ($k) => $canonicalMap[$k] === $canonical && $k !== $canonical
                ));

                // Pick the canonical assignment (prefer canonical key, else this one)
                $canonicalAssignment = $byNorm[$canonical] ?? $assignment;

                $row = $this->transformAssignment($canonicalAssignment);
                $row['aliases'] = collect($aliasNorms)
                    ->map(fn ($aliasNorm) => $byNorm[$aliasNorm] ?? null)
                    ->filter()
                    ->map(fn ($a) => [
                        'employee_id' => $a->employee_id,
                        'employee_name' => $a->employee?->full_name ?? '',
                        'branch_name' => $a->branch?->name,
                        'branch_id' => $a->branch_id,
                        'match_type' => $a->match_type?->value,
                    ])
                    ->values()
                    ->all();

                $assignments->push($row);
            }

            $assignments = $assignments->values();

            // Altas/bajas — se leen DIRECTO de `period_employee_rosters` (roster canónico,
            // deduplicado por persona real, calculado mes-contra-mes-anterior mensual
            // durante "Actualizar BD") — la MISMA fuente que Rotación y OKR, nunca un
            // diff crudo de employee_id (bug del 17-sep-2026: 132 "altas" falsas).
            //
            // 18-oct-2026: una ausencia de nómina NO es una baja. Solo las bajas
            // confirmadas con evidencia cuentan como "Bajas"; el resto va a
            // "Pendientes de conciliación" (ver Roster\RosterMovementsPresenter).
            if ($selectedPeriod->isMonthly()) {
                $presenter = app(RosterMovementsPresenter::class);
                // Fase 6.4 — alcance restringido: personas, conteos, plantilla e historial
                // solo de SUS sucursales (null = todas, sin cambios para alcance general).
                $labor = $presenter->forPeriod($selectedPeriod, $periods, $scopeBranchIds);
                $laborHistory = $presenter->history($periods, $scopeBranchIds);
            }

            $hires = collect($labor['movements']['altas'] ?? [])->merge($labor['movements']['reingresos'] ?? [])->values();
            $leavers = collect($labor['movements']['bajas'] ?? [])->values();

            $incidences = $assignments
                ->filter(function (array $item) {
                    return in_array($item['ui_status'], ['pending', 'unmatched'], true)
                        || $item['needs_manual_attention']
                        || ! $item['branch_id'];
                })
                ->values();

            $summary = [
                'total' => $assignments->count(),
                'matched' => $assignments->where('ui_status', 'matched')->count(),
                'manual' => $assignments->where('ui_status', 'manual')->count(),
                'pending' => $assignments->where('ui_status', 'pending')->count(),
                'unmatched' => $assignments->where('ui_status', 'unmatched')->count(),
                'with_branch' => $assignments->whereNotNull('branch_id')->count(),
                'without_branch' => $assignments->whereNull('branch_id')->count(),
                'high_confidence' => $assignments->filter(fn ($item) => ($item['confidence'] ?? 0) >= 0.9)->count(),
                'needs_review' => $incidences->count(),
                'hires' => $hires->count(),
                // Solo bajas CONFIRMADAS con evidencia (directorio INACTIVO, finiquito o conciliación).
                'leavers' => $leavers->count(),
                'leavers_confirmadas' => $leavers->count(),
                'leavers_pendientes' => (int) ($labor['counts']['ausencias_pendientes'] ?? 0),
                'pendientes_conciliacion' => (int) ($labor['counts']['pendientes'] ?? 0),
                'plantilla' => (int) ($labor['roster']['plantilla'] ?? 0),
                'roster_calculado' => (bool) ($labor['roster']['calculado'] ?? false),
                'mass_drop_detected' => (bool) ($labor['roster']['mass_drop_detected'] ?? false),
                'drop_ratio' => (float) ($labor['roster']['drop_ratio'] ?? 0.0),
            ];
        }

        return Inertia::render('AsignacionSucursal/Index', [
            'assignments' => $assignments,
            'branches' => $branches,
            'periods' => $periods->map(fn (Period $period) => [
                'id' => $period->id,
                'label' => $period->label,
                'type' => $period->type,
                'start_date' => optional($period->start_date)->format('Y-m-d'),
                'end_date' => optional($period->end_date)->format('Y-m-d'),
            ])->values(),
            'selected_period_id' => $selectedPeriod?->id,
            'selected_period_label' => $selectedPeriod?->label,
            'selected_branch_id' => $selectedBranchId,
            'summary' => $summary,
            'incidences' => $incidences,
            'hires' => $hires,
            'leavers' => $leavers,
            'labor' => $labor,
            'labor_history' => $laborHistory,
            'can_reconcile' => app(ActionPermissionService::class)->canAction($request->user(), 'colaboradores', 'conciliar'),
        ]);
    }

    /**
     * Conciliación autorizada de una ausencia de nómina (18-oct-2026): confirmar
     * la baja o descartarla, con motivo obligatorio (queda en la bitácora).
     *
     * Un usuario restringido solo concilia personas cuya sucursal (en el roster
     * del periodo, o la última conocida) está en su alcance; si no se puede
     * determinar la sucursal, solo alguien con alcance general puede hacerlo.
     *
     * @param  Request  $request  nombre_normalizado (employee_key del roster), decision
     *                            (confirmar|descartar) y note (motivo, 5-1000 caracteres).
     * @param  Period  $period  Periodo mensual del movimiento.
     * @param  RosterMovementReviewService  $service  Registra la decisión en la bitácora y
     *                                                actualiza el estado del movimiento.
     * @return RedirectResponse Con mensaje de éxito o la advertencia que devuelva el servicio.
     *
     * Efectos: escribe en BD vía el servicio; lanza 403 por alcance.
     */
    public function reviewMovement(Request $request, Period $period, RosterMovementReviewService $service): RedirectResponse
    {
        $validated = $request->validate([
            // Llave de identidad del roster (employee_key): igual al nombre normalizado
            // salvo homónimos separados ("nombre#id").
            'nombre_normalizado' => ['required', 'string', 'max:191'],
            'decision' => ['required', 'in:'.RosterMovementStatus::DECISION_CONFIRMAR.','.RosterMovementStatus::DECISION_DESCARTAR],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'decision.in' => 'La decisión no es válida.',
            'note.required' => 'Escribe el motivo o la evidencia de la decisión.',
            'note.min' => 'El motivo debe tener al menos 5 caracteres.',
        ]);

        if (! $period->isMonthly()) {
            return back()->with('error', 'Solo se concilian movimientos de periodos mensuales.');
        }

        // Fase 6.4 — solo movimientos de personas de sus sucursales.
        $dataScope = app(DataScopeService::class);
        if ($dataScope->isRestricted($request->user())) {
            $rosterBranch = DB::table('period_employee_rosters')->where('period_id', $period->id)
                ->where('employee_key', $validated['nombre_normalizado'])->value('branch_id');
            if (! $rosterBranch) {
                $rosterBranch = DB::table('period_employee_rosters')->where('employee_key', $validated['nombre_normalizado'])
                    ->whereNotNull('branch_id')->orderByDesc('period_id')->value('branch_id');
            }
            if (! $rosterBranch) {
                abort(403, 'No podemos confirmar la sucursal de esta persona, así que solo un usuario con alcance general puede conciliarla.');
            }
            $dataScope->authorizeBranch($request->user(), (int) $rosterBranch);
        }

        $result = $service->review($period, $validated['nombre_normalizado'], $validated['decision'], $validated['note'], $request->user());

        $message = $validated['decision'] === RosterMovementStatus::DECISION_CONFIRMAR
            ? 'Baja confirmada y registrada en la bitácora. La rotación del periodo ya la considera; regenera la radiografía para verla en Excel/PDF.'
            : 'Ausencia descartada como baja y registrada en la bitácora.';

        return back()->with($result['warning'] ? 'warning' : 'success', $result['warning'] ?? $message);
    }

    /**
     * Variante de index() para la ruta de pendientes: agrega `only_pending=true`
     * a la petición y delega en index().
     *
     * @param  Request  $request  Mismos parámetros que index().
     * @return Response La misma página que index().
     */
    public function pending(Request $request): Response
    {
        $request->merge(['only_pending' => true]);

        return $this->index($request);
    }

    /**
     * Ejecuta el cruce automático empleado→sucursal (EmployeeBranchAutoMatchService)
     * para un periodo o, sin period_id, para los que el servicio decida. Las
     * asignaciones manuales se respetan.
     *
     * @param  Request  $request  `period_id` opcional.
     * @param  EmployeeBranchAutoMatchService  $service  Motor del cruce; escribe en employee_branch_assignments.
     * @return RedirectResponse Con conteos: procesados, con match, sin match y manuales respetados.
     *
     * Lanza 403 si el usuario no tiene alcance general (el cruce toca todas las sucursales).
     */
    public function autoMatch(Request $request, EmployeeBranchAutoMatchService $service): RedirectResponse
    {
        // Fase 6.4 — el cruce automático reasigna personas de TODAS las sucursales.
        app(DataScopeService::class)->requireGeneral($request->user(), 'El cruce automático de sucursales');

        $validated = $request->validate([
            'period_id' => ['nullable', 'integer', 'exists:periods,id'],
        ]);

        $result = $service->handle($validated['period_id'] ?? null);

        return back()->with(
            'success',
            "Cruce ejecutado. Procesados: {$result['processed']}, match: {$result['matched']}, sin match: {$result['unmatched']}, manuales respetados: {$result['manual_kept']}."
        );
    }

    /**
     * Asigna a mano la sucursal de una asignación (match_type/source_type =
     * manual, confianza 1, was_manual_reviewed = true) y la propaga a los
     * registros alias del mismo periodo (mismo nombre canónico) que aún no
     * fueron revisados a mano; los alias quedan con was_manual_reviewed = false.
     *
     * @param  Request  $request  branch_id (sucursal destino, obligatoria) y notes opcional.
     * @param  EmployeeBranchAssignment  $assignment  Asignación a corregir.
     * @return RedirectResponse Con mensaje de éxito.
     *
     * Efectos: actualiza varias filas de employee_branch_assignments. Lanza 403
     * si la sucursal actual está fuera del alcance (el destino lo valida el
     * middleware EnsureBranchScope).
     */
    public function manualMatch(Request $request, EmployeeBranchAssignment $assignment): RedirectResponse
    {
        // Fase 6.4 — solo asignaciones sin sucursal o de sus sucursales (el destino lo valida EnsureBranchScope).
        app(DataScopeService::class)->authorizeBranch($request->user(), $assignment->branch_id ? (int) $assignment->branch_id : null);

        $validated = $request->validate([
            'branch_id' => ['required', 'exists:branches,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'branch_id.required' => 'Debes seleccionar una sucursal.',
            'branch_id.exists' => 'La sucursal seleccionada no existe.',
        ]);

        $payload = [
            'branch_id' => (int) $validated['branch_id'],
            'source_type' => SourceType::Manual,
            'source_reference' => null,
            'match_type' => MatchType::Manual,
            'confidence' => 1,
            'was_manual_reviewed' => true,
            'notes' => $validated['notes'] ?? $assignment->notes,
        ];

        $assignment->update($payload);

        // Propagate to alias employees (same period, same canonical name group)
        $empName = $assignment->employee?->full_name ?? '';
        if ($empName && $assignment->period_id) {
            $canonicalizer = app(EmployeeNameCanonicalizer::class);
            $canonicalNorm = $canonicalizer->normalize($empName);

            // Load all assignments for the same period to find aliases
            $periodAssignments = EmployeeBranchAssignment::query()
                ->with('employee:id,full_name')
                ->where('period_id', $assignment->period_id)
                ->where('id', '!=', $assignment->id)
                ->get();

            $allNames = $periodAssignments
                ->map(fn ($a) => $a->employee?->full_name)
                ->filter()->push($empName)->unique()->values()->all();
            $canonicalMap = $canonicalizer->buildCanonicalMap($allNames);

            $aliasPayload = array_merge($payload, ['was_manual_reviewed' => false]);
            foreach ($periodAssignments as $other) {
                $otherNorm = $canonicalizer->normalize($other->employee?->full_name ?? '');
                if (($canonicalMap[$otherNorm] ?? $otherNorm) === $canonicalNorm && $otherNorm !== $canonicalNorm) {
                    // Only update if not already manually reviewed
                    if (! $other->was_manual_reviewed) {
                        $other->update($aliasPayload);
                    }
                }
            }
        }

        return back()->with('success', 'Asignación manual guardada correctamente.');
    }

    /**
     * Edición directa de una asignación: sucursal, tipo de match, confianza,
     * marca de revisión manual y notas. Los campos no enviados conservan su
     * valor; si el match queda como manual (o ya era de origen manual), el
     * source_type se fija en manual. A diferencia de manualMatch(), no propaga a alias.
     *
     * @param  Request  $request  branch_id, match_type, confidence (0-1),
     *                            was_manual_reviewed y notes (todos opcionales).
     * @param  EmployeeBranchAssignment  $assignment  Asignación a editar.
     * @return RedirectResponse Con mensaje de éxito.
     *
     * Efectos: actualiza la fila en BD; lanza 403 si la sucursal actual está fuera del alcance.
     */
    public function update(Request $request, EmployeeBranchAssignment $assignment): RedirectResponse
    {
        app(DataScopeService::class)->authorizeBranch($request->user(), $assignment->branch_id ? (int) $assignment->branch_id : null);

        $validated = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'match_type' => ['nullable', 'in:exact,normalized,manual,unmatched,canonical_same_name,historical,majority,fuzzy'],
            'confidence' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'was_manual_reviewed' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'match_type.in' => 'El tipo de match no es válido.',
            'confidence.numeric' => 'La confianza debe ser numérica.',
            'confidence.min' => 'La confianza mínima es 0.',
            'confidence.max' => 'La confianza máxima es 1.',
        ]);

        $newBranchId = array_key_exists('branch_id', $validated)
            ? $validated['branch_id']
            : $assignment->branch_id;

        $newMatchType = array_key_exists('match_type', $validated)
            ? MatchType::from($validated['match_type'])
            : $assignment->match_type;

        $payload = [
            'branch_id' => $newBranchId,
            'match_type' => $newMatchType,
            'confidence' => $validated['confidence'] ?? $assignment->confidence,
            'was_manual_reviewed' => $validated['was_manual_reviewed'] ?? $assignment->was_manual_reviewed,
            'notes' => array_key_exists('notes', $validated) ? $validated['notes'] : $assignment->notes,
        ];

        if (
            $newMatchType === MatchType::Manual ||
            ($assignment->source_type?->value === SourceType::Manual->value)
        ) {
            $payload['source_type'] = SourceType::Manual;
        }

        $assignment->update($payload);

        return back()->with('success', 'Asignación actualizada correctamente.');
    }

    /**
     * Sucursales que sí se muestran en pantalla: las operativas más CORPORATIVO
     * (y San Juan del Río con y sin acento). Una asignación a una ruta u
     * oficina que no esté aquí se presenta como "sin sucursal".
     */
    private const OPERATIVE_BRANCH_DISPLAY = [
        'ATLACOMULCO', 'ATLIXCO', 'CORDOBA', 'CUERNAVACA', 'HUAMANTLA',
        'IXTLAHUACA', 'MIACATLAN', 'ORIZABA', 'SAN LUIS POTOSI',
        'TENANGO DEL VALLE', 'TLAXCALA', 'TULA',
        'CORPORATIVO', 'SAN JUAN DEL RÍO', 'SAN JUAN DEL RIO',
    ];

    /**
     * Convierte una asignación en la fila que consume la vista: empleado,
     * sucursal visible (solo si es operativa), fuente, tipo de match con
     * etiqueta y explicación, confianza, estado de UI y si requiere atención
     * manual (pendiente/sin match o confianza < 0.85).
     *
     * @param  EmployeeBranchAssignment  $assignment  Asignación con employee, branch y period cargados.
     * @param  string  $context  Etiqueta de contexto que se devuelve tal cual (por defecto "actual").
     * @return array<string, mixed> Fila para la tabla; `aliases` vacío (lo llena index()).
     */
    private function transformAssignment(EmployeeBranchAssignment $assignment, string $context = 'actual'): array
    {
        $employee = $assignment->employee;
        $branch = $assignment->branch;
        $period = $assignment->period;
        $matchType = $assignment->match_type?->value ?? null;
        $sourceType = $assignment->source_type?->value ?? null;
        $uiStatus = $this->resolveUiStatus($assignment);

        // Only show the branch name if it is an operative branch.
        // If it's a route/office (non-operative), treat as "sin sucursal" in the UI.
        $branchNameDisplay = null;
        $branchIdDisplay = null;
        if ($branch) {
            $upperName = strtoupper(trim($branch->name));
            if (in_array($upperName, self::OPERATIVE_BRANCH_DISPLAY, true)) {
                $branchNameDisplay = $branch->name;
                $branchIdDisplay = $assignment->branch_id;
            }
        }

        return [
            'id' => $assignment->id,
            'employee_id' => $assignment->employee_id,
            'branch_id' => $branchIdDisplay,
            'employee_name' => $employee?->full_name ?? 'Sin empleado',
            'normalized_name' => $employee?->normalized_name,
            'position' => $employee?->position,
            'branch_name' => $branchNameDisplay,
            'source_name' => $this->formatSourceType($sourceType),
            'source_reference' => $assignment->source_reference,
            'match_type' => $matchType,
            'match_label' => $this->formatMatchType($matchType),
            'match_explanation' => $this->formatMatchExplanation($matchType),
            'confidence' => $assignment->confidence !== null ? (float) $assignment->confidence : null,
            'was_manual_reviewed' => (bool) $assignment->was_manual_reviewed,
            'ui_status' => $uiStatus,
            'period_label' => $period?->label,
            'updated_at' => optional($assignment->updated_at)->format('d/m/Y H:i'),
            'notes' => $assignment->notes,
            'needs_manual_attention' => in_array($uiStatus, ['pending', 'unmatched'], true)
                || (($assignment->confidence ?? 0) < 0.85),
            'context' => $context,
            'aliases' => [],
        ];
    }

    /**
     * Estado de UI de una asignación: "manual" (con sucursal y match manual),
     * "matched" (con sucursal y match automático: exacto, normalizado,
     * histórico, mayoría, aproximado o mismo nombre), "unmatched" (sin match)
     * o "pending" (cualquier otro caso).
     *
     * @param  EmployeeBranchAssignment  $assignment  Asignación a clasificar.
     * @return string manual|matched|unmatched|pending
     */
    private function resolveUiStatus(EmployeeBranchAssignment $assignment): string
    {
        $matchType = $assignment->match_type?->value;

        if ($assignment->branch_id && $matchType === MatchType::Manual->value) {
            return 'manual';
        }

        if ($assignment->branch_id && in_array($matchType, [
            MatchType::Exact->value,
            MatchType::Normalized->value,
            MatchType::Historical->value,
            MatchType::Majority->value,
            MatchType::Fuzzy->value,
            MatchType::CanonicalSameName->value,
        ], true)) {
            return 'matched';
        }

        if ($matchType === MatchType::Unmatched->value) {
            return 'unmatched';
        }

        return 'pending';
    }

    /**
     * Nombre legible de la fuente de la asignación.
     *
     * @param  string|null  $sourceType  Valor de SourceType (noi, lendus, manual...).
     * @return string NOI, Lendus, Manual o "Cruce operativo" para cualquier otro.
     */
    private function formatSourceType(?string $sourceType): string
    {
        return match ($sourceType) {
            SourceType::Noi->value => 'NOI',
            SourceType::Lendus->value => 'Lendus',
            SourceType::Manual->value => 'Manual',
            default => 'Cruce operativo',
        };
    }

    /**
     * Etiqueta corta en español del tipo de match (Exacto, Normalizado, Manual...).
     *
     * @param  string|null  $matchType  Valor de MatchType.
     * @return string Etiqueta; "Pendiente" si no se reconoce.
     */
    private function formatMatchType(?string $matchType): string
    {
        return match ($matchType) {
            MatchType::Exact->value => 'Exacto',
            MatchType::Normalized->value => 'Normalizado',
            MatchType::Manual->value => 'Manual',
            MatchType::Unmatched->value => 'Sin match',
            MatchType::Historical->value => 'Histórico',
            MatchType::Majority->value => 'Mayoría',
            MatchType::Fuzzy->value => 'Aproximado',
            MatchType::CanonicalSameName->value => 'Mismo nombre',
            default => 'Pendiente',
        };
    }

    /**
     * Explicación en español de cómo se determinó la sucursal, para el usuario.
     *
     * @param  string|null  $matchType  Valor de MatchType.
     * @return string Texto explicativo; "Pendiente de revisión." si no se reconoce.
     */
    private function formatMatchExplanation(?string $matchType): string
    {
        return match ($matchType) {
            MatchType::Exact->value => 'Coincidencia directa.',
            MatchType::Normalized->value => 'Coincidencia normalizada. Conviene revisar acentos, mayúsculas o variaciones menores.',
            MatchType::Manual->value => 'Asignación validada manualmente.',
            MatchType::Unmatched->value => 'No se logró determinar una sucursal con suficiente confianza.',
            MatchType::Historical->value => 'Sucursal heredada de la última asignación confirmada en un periodo anterior.',
            MatchType::Majority->value => 'Sucursal dominante entre varios candidatos (cobranza, colocación, cartera o gastos).',
            MatchType::Fuzzy->value => 'Coincidencia aproximada de nombre. Conviene validar.',
            MatchType::CanonicalSameName->value => 'Sucursal heredada de otro registro con el mismo nombre normalizado.',
            default => 'Pendiente de revisión.',
        };
    }
}
