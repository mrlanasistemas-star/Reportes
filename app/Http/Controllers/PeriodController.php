<?php

namespace App\Http\Controllers;

use App\Models\DataSource;
use App\Models\Period;
use App\Services\PeriodCloseGuardService;
use App\Services\PeriodGenerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PeriodController extends Controller
{
    public function index(PeriodCloseGuardService $guard): Response
    {
        $requiredSourcesCount = DataSource::query()
            ->where('is_active', true)
            ->where('is_required_for_report', true)
            ->count();

        $allPeriods = Period::query()
            ->with(['reportUploads' => fn ($q) => $q->latest()])
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->orderByDesc('sequence')
            ->get();

        $periods = $allPeriods->map(function (Period $period) use ($requiredSourcesCount, $guard, $allPeriods) {
            $closeGuard = $guard->canClose($period);

            // Para periodos base (semanales) contar uploads directos
            // Para compuestos, mostrar 0 / required indicando que no reciben archivos
            $uploadedSourcesCount = 0;
            if ($period->isBase()) {
                $uploadedSourcesCount = $period->reportUploads
                    ->pluck('data_source_id')
                    ->filter()
                    ->unique()
                    ->count();
            }

            // Periodos compuestos: labels y, para mensuales, detalle completo de cada semana
            $componentIds = collect($period->component_period_ids ?? [])
                ->map(fn ($id) => (int) $id)
                ->all();

            $componentLabels = [];
            $componentWeeks  = [];

            if (!empty($componentIds)) {
                $components = $allPeriods->whereIn('id', $componentIds);

                $componentLabels = $components->pluck('label')->values()->all();

                if ($period->isMonthly()) {
                    $componentWeeks = $components
                        ->where('type', 'weekly')
                        ->sortBy(fn ($w) => optional($w->start_date)->format('Y-m-d'))
                        ->map(fn ($w) => [
                            'id'         => $w->id,
                            'sequence'   => $w->sequence,
                            'start_date' => optional($w->start_date)->format('Y-m-d'),
                            'end_date'   => optional($w->end_date)->format('Y-m-d'),
                        ])
                        ->values()
                        ->all();
                }
            }

            return [
                'id'                     => $period->id,
                'name'                   => $period->name,
                'custom_name'            => $period->custom_name,
                'system_label'           => $period->systemLabel(),
                'code'                   => $period->code,
                'type'                   => $period->type,
                'sequence'               => $period->sequence,
                'label'                  => $period->label,
                'year'                   => $period->year,
                'month'                  => $period->month,
                'start_date'             => optional($period->start_date)->format('Y-m-d'),
                'end_date'               => optional($period->end_date)->format('Y-m-d'),
                'is_closed'              => (bool) $period->is_closed,
                'can_receive_uploads'    => $period->canReceiveUploads(),
                'is_compound'            => $period->isCompound(),
                'component_period_ids'   => $period->component_period_ids ?? [],
                'component_labels'       => $componentLabels,
                'component_weeks'        => $componentWeeks,
                'uploaded_sources_count' => $uploadedSourcesCount,
                'required_sources_count' => $period->isBase() ? $requiredSourcesCount : 0,
                'can_close'              => $closeGuard['can_close'],
                'close_issues_count'     => count($closeGuard['issues']),
                'close_issues_preview'   => collect($closeGuard['issues'])->take(2)->values()->all(),
            ];
        })->values();

        // Semanas disponibles = todas las semanales NO asignadas a ningún mes operativo
        $assignedWeekIds = Period::query()
            ->where('type', 'monthly')
            ->whereNotNull('component_period_ids')
            ->pluck('component_period_ids')
            ->flatMap(fn ($ids) => is_array($ids) ? $ids : json_decode($ids ?? '[]', true) ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->all();

        $availableWeeks = Period::query()
            ->where('type', 'weekly')
            ->when(!empty($assignedWeekIds), fn ($q) => $q->whereNotIn('id', $assignedWeekIds))
            ->orderBy('start_date')
            ->get(['id', 'name', 'year', 'month', 'sequence', 'start_date', 'end_date'])
            ->map(fn (Period $w) => [
                'id'         => $w->id,
                'label'      => $w->name,
                'year'       => $w->year,
                'month'      => $w->month,
                'sequence'   => $w->sequence,
                'start_date' => optional($w->start_date)->format('Y-m-d'),
                'end_date'   => optional($w->end_date)->format('Y-m-d'),
            ]);

        return Inertia::render('Periodos/Index', [
            'periods'        => $periods,
            'availableWeeks' => $availableWeeks,
        ]);
    }

    public function store(Request $request, PeriodGenerationService $generator): RedirectResponse
    {
        $validated = $request->validate([
            'type'       => ['required', 'string', 'in:weekly,monthly,bimonthly,quarterly,semiannual,annual'],
            'year'       => ['required', 'integer', 'min:2020', 'max:2100'],
            'month'      => ['required', 'integer', 'min:1', 'max:12'],
            'week_ids'   => ['nullable', 'array'],
            'week_ids.*' => ['integer', 'exists:periods,id'],
        ], [
            'type.required'  => 'El tipo de periodo es obligatorio.',
            'type.in'        => 'El tipo de periodo no es válido.',
            'year.required'  => 'El año es obligatorio.',
            'year.integer'   => 'El año debe ser numérico.',
            'month.required' => 'El mes base es obligatorio.',
            'month.integer'  => 'El mes base debe ser numérico.',
            'month.min'      => 'El mes base no es válido.',
            'month.max'      => 'El mes base no es válido.',
        ]);

        $type    = $validated['type'];
        $year    = (int) $validated['year'];
        $month   = (int) $validated['month'];
        $weekIds = array_map('intval', $validated['week_ids'] ?? []);

        try {
            $periods = $generator->generate($year, $month, $type, $weekIds);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $typeLabels = [
            'weekly'    => 'semanal',
            'monthly'   => 'mensual',
            'bimonthly' => 'bimestral',
            'quarterly' => 'trimestral',
            'semiannual'=> 'semestral',
            'annual'    => 'anual',
        ];
        $typeLabel = $typeLabels[$type] ?? $type;

        return back()->with('success', "Se generaron {$periods->count()} periodo(s) {$typeLabel}(es) correctamente.");
    }

    public function destroy(Period $period, PeriodGenerationService $generator): RedirectResponse
    {
        if (!$period->isMonthly()) {
            return back()->with('error', 'Solo se pueden eliminar meses operativos (tipo mensual).');
        }

        if ($period->reportUploads()->exists()) {
            return back()->with('error', "No se puede eliminar \"{$period->label}\" porque ya tiene archivos cargados. Primero elimina o reinicia la información procesada.");
        }

        if ($period->processRuns()->exists()) {
            return back()->with('error', "No se puede eliminar \"{$period->label}\" porque tiene procesos de carga registrados.");
        }

        $label = $period->label;
        $year  = $period->year;

        $period->delete();

        $generator->syncDerivedPeriods($year);

        return back()->with('success', "El mes operativo \"{$label}\" fue eliminado. Las semanas base siguen disponibles.");
    }

    /**
     * Nombre manual del periodo (23-nov-2026). SOLO cambia la etiqueta visible:
     * id, code, fechas, semanas y todo lo ligado por period_id (ingresos, gastos,
     * cargas, radiografías, Estado de Resultados) quedan exactamente igual.
     * Enviar el nombre vacío devuelve el nombre del sistema.
     */
    public function rename(Request $request, Period $period): RedirectResponse
    {
        $validated = $request->validate([
            'custom_name' => ['nullable', 'string', 'max:120'],
        ], [
            'custom_name.string' => 'El nombre debe ser texto.',
            'custom_name.max'    => 'El nombre no puede tener más de 120 caracteres.',
        ]);

        $name = preg_replace('/\s+/u', ' ', trim((string) ($validated['custom_name'] ?? '')));
        $name = $name === '' ? null : $name;

        if ($name !== null && mb_strtolower($name) === mb_strtolower($period->systemLabel())) {
            $name = null; // igual al del sistema: no hace falta guardarlo como manual
        }

        $before = $period->label;

        if ($name !== null) {
            $duplicate = Period::query()
                ->where('id', '!=', $period->id)
                ->where('type', $period->type)
                ->get(['id', 'name', 'custom_name', 'type', 'year', 'month', 'sequence'])
                ->first(fn (Period $p) => mb_strtolower($p->label) === mb_strtolower($name));

            if ($duplicate) {
                return back()->with('error', "Ya existe otro periodo llamado \"{$duplicate->label}\". Usa un nombre distinto para no confundirlos.");
            }
        }

        // Solo la columna del nombre manual: nunca se tocan id, code ni fechas.
        $period->forceFill(['custom_name' => $name])->save();

        \Illuminate\Support\Facades\Log::info('periodos.renombrar', [
            'period_id' => $period->id,
            'code'      => $period->code,
            'antes'     => $before,
            'despues'   => $period->label,
            'user_id'   => $request->user()?->id,
        ]);

        return back()->with('success', $name === null
            ? "El periodo volvió a su nombre del sistema: \"{$period->label}\"."
            : "El periodo \"{$before}\" ahora se llama \"{$period->label}\". Su información no cambió.");
    }

    public function close(Period $period, PeriodCloseGuardService $guard): RedirectResponse
    {
        $result = $guard->canClose($period);

        if (!$result['can_close']) {
            return back()->with('error', 'No se puede cerrar el periodo: ' . implode(' | ', $result['issues']));
        }

        $period->update(['is_closed' => true]);

        return back()->with('success', "El periodo {$period->label} fue cerrado.");
    }

    public function open(Period $period): RedirectResponse
    {
        $period->update(['is_closed' => false]);

        return back()->with('success', "El periodo {$period->label} fue reabierto.");
    }
}
