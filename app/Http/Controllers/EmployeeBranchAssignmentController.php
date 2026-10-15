<?php

namespace App\Http\Controllers;

use App\Enums\MatchType;
use App\Enums\SourceType;
use App\Models\Branch;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Services\BranchResolverService;
use App\Services\EmployeeBranchAutoMatchService;
use App\Services\EmployeeNameCanonicalizer;
use App\Services\Access\ActionPermissionService;
use App\Services\Roster\RosterMovementReviewService;
use App\Services\Roster\RosterMovementsPresenter;
use App\Services\Roster\RosterMovementStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class EmployeeBranchAssignmentController extends Controller
{
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
            ->get(['id', 'name', 'code', 'type', 'year', 'month', 'sequence', 'start_date', 'end_date']);

        $selectedPeriod = $periods->firstWhere('id', (int) $request->integer('period_id'))
            ?? $periods->first();

        $assignments = collect();

        // Only real assignable branches (operational + CORPORATIVO, no routes)
        $resolver       = app(BranchResolverService::class);
        $realBranchNames = $resolver->realAssignableBranches();
        $branches = Branch::query()
            ->whereIn(DB::raw('UPPER(name)'), $realBranchNames)
            ->orderBy('name')
            ->get(['id', 'name']);

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
                    'period:id,name,code,type,year,month,sequence,start_date,end_date',
                ])
                ->where('period_id', $selectedPeriod->id)
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
            $seen        = [];
            $assignments = collect();

            foreach ($rawAssignments as $assignment) {
                $empName = $assignment->employee?->full_name ?? '';
                $norm    = $canonicalizer->normalize($empName);
                $canonical = $canonicalMap[$norm] ?? $norm;

                // Skip if we already emitted this canonical
                if (isset($seen[$canonical])) continue;
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
                        'employee_id'   => $a->employee_id,
                        'employee_name' => $a->employee?->full_name ?? '',
                        'branch_name'   => $a->branch?->name,
                        'branch_id'     => $a->branch_id,
                        'match_type'    => $a->match_type?->value,
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
                $labor = $presenter->forPeriod($selectedPeriod, $periods);
                $laborHistory = $presenter->history($periods);
            }

            $hires = collect($labor['movements']['altas'] ?? [])->merge($labor['movements']['reingresos'] ?? [])->values();
            $leavers = collect($labor['movements']['bajas'] ?? [])->values();

            $incidences = $assignments
                ->filter(function (array $item) {
                    return in_array($item['ui_status'], ['pending', 'unmatched'], true)
                        || $item['needs_manual_attention']
                        || !$item['branch_id'];
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
     */
    public function reviewMovement(Request $request, Period $period, RosterMovementReviewService $service): RedirectResponse
    {
        $validated = $request->validate([
            'nombre_normalizado' => ['required', 'string', 'max:191'],
            'decision' => ['required', 'in:' . RosterMovementStatus::DECISION_CONFIRMAR . ',' . RosterMovementStatus::DECISION_DESCARTAR],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
        ], [
            'decision.in' => 'La decisión no es válida.',
            'note.required' => 'Escribe el motivo o la evidencia de la decisión.',
            'note.min' => 'El motivo debe tener al menos 5 caracteres.',
        ]);

        if (!$period->isMonthly()) {
            return back()->with('error', 'Solo se concilian movimientos de periodos mensuales.');
        }

        $result = $service->review($period, $validated['nombre_normalizado'], $validated['decision'], $validated['note'], $request->user());

        $message = $validated['decision'] === RosterMovementStatus::DECISION_CONFIRMAR
            ? 'Baja confirmada y registrada en la bitácora. La rotación del periodo ya la considera; regenera la radiografía para verla en Excel/PDF.'
            : 'Ausencia descartada como baja y registrada en la bitácora.';

        return back()->with($result['warning'] ? 'warning' : 'success', $result['warning'] ?? $message);
    }

    public function pending(Request $request): Response
    {
        $request->merge(['only_pending' => true]);

        return $this->index($request);
    }

    public function autoMatch(Request $request, EmployeeBranchAutoMatchService $service): RedirectResponse
    {
        $validated = $request->validate([
            'period_id' => ['nullable', 'integer', 'exists:periods,id'],
        ]);

        $result = $service->handle($validated['period_id'] ?? null);

        return back()->with(
            'success',
            "Cruce ejecutado. Procesados: {$result['processed']}, match: {$result['matched']}, sin match: {$result['unmatched']}, manuales respetados: {$result['manual_kept']}."
        );
    }

    public function manualMatch(Request $request, EmployeeBranchAssignment $assignment): RedirectResponse
    {
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
                    if (!$other->was_manual_reviewed) {
                        $other->update($aliasPayload);
                    }
                }
            }
        }

        return back()->with('success', 'Asignación manual guardada correctamente.');
    }

    public function update(Request $request, EmployeeBranchAssignment $assignment): RedirectResponse
    {
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

    // Only display operative branches — routes/offices are treated as "sin sucursal" in the UI.
    private const OPERATIVE_BRANCH_DISPLAY = [
        'ATLACOMULCO', 'ATLIXCO', 'CORDOBA', 'CUERNAVACA', 'HUAMANTLA',
        'IXTLAHUACA', 'MIACATLAN', 'ORIZABA', 'SAN LUIS POTOSI',
        'TENANGO DEL VALLE', 'TLAXCALA', 'TULA',
        'CORPORATIVO', 'SAN JUAN DEL RÍO', 'SAN JUAN DEL RIO',
    ];

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
        $branchIdDisplay   = null;
        if ($branch) {
            $upperName = strtoupper(trim($branch->name));
            if (in_array($upperName, self::OPERATIVE_BRANCH_DISPLAY, true)) {
                $branchNameDisplay = $branch->name;
                $branchIdDisplay   = $assignment->branch_id;
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

    private function formatSourceType(?string $sourceType): string
    {
        return match ($sourceType) {
            SourceType::Noi->value => 'NOI',
            SourceType::Lendus->value => 'Lendus',
            SourceType::Manual->value => 'Manual',
            default => 'Cruce operativo',
        };
    }

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
