<?php

namespace App\Services\Okr;

use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\OkrProgressSnapshot;
use App\Models\OkrWarning;
use App\Models\User;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Warning Rojo. El sistema NUNCA sanciona solo: detecta un posible
 * incumplimiento y un usuario autorizado decide emitirlo.
 *
 * Corrección 15-oct-2026 — condiciones REALES de elegibilidad (eligibility()):
 *   1. El Warning es de la persona EVALUADA por ESTE OKR: el gestor de un OKR
 *      individual, o el titular formalmente asignado de un OKR de sucursal.
 *      Nunca el responsable administrativo ni el creador; un incumplimiento
 *      individual jamás genera un Warning a nombre del gerente.
 *   2. La semana evaluada ya CERRÓ (hoy > fin de semana). Una semana abierta
 *      queda "Pendiente de evaluación".
 *   3. El OKR está activo (o cerrado) y tiene Carta Compromiso VIGENTE.
 *   4. La semana tiene dato real (no faltante, no "último mes disponible").
 *   5. Hay incumplimiento real contra lo ESPERADO a esa semana (desviación
 *      negativa), no contra la meta final.
 *   6. No existe ya un Warning vigente de esa semana.
 */
class OkrWarningService
{
    /** Calidades de dato que NO sustentan un incumplimiento. */
    private const INSUFFICIENT_QUALITY = [OkrProgressSnapshot::QUALITY_MISSING, OkrProgressSnapshot::QUALITY_LAST_AVAILABLE];

    public function __construct(
        private readonly OkrCalendarService $calendar,
        private readonly OkrDocumentFolioGenerator $folioGenerator,
        private readonly BrowsershotPdfRenderer $renderer,
        private readonly OkrAuditLogger $logger,
    ) {
    }

    /**
     * @return array{rows: array, has_any_data: bool, has_breach: bool}
     */
    public function buildWeekSnapshot(OkrObjective $objective, int $weekNumber): array
    {
        $rows = [];
        $hasAnyData = false;
        $hasBreach = false;

        foreach ($objective->keyResults()->with('kpi')->get() as $kr) {
            $snapshot = OkrProgressSnapshot::query()
                ->where('okr_key_result_id', $kr->id)
                ->where('week_number', $weekNumber)
                ->first();

            $hasData = $snapshot !== null && $snapshot->actual_value !== null
                && !in_array($snapshot->source_quality, self::INSUFFICIENT_QUALITY, true);
            if ($hasData) {
                $hasAnyData = true;
            }

            // Brecha contra lo ESPERADO a esa semana, con la semántica de
            // dirección del KPI (positivo = faltó / se excedió el límite).
            $gap = null;
            if ($hasData && $snapshot->expected_value !== null) {
                $gap = $kr->kpi->direction === OkrKpi::DIRECTION_DECREASE
                    ? (float) $snapshot->actual_value - (float) $snapshot->expected_value
                    : (float) $snapshot->expected_value - (float) $snapshot->actual_value;
            }

            $hasRowBreach = $hasData && $snapshot->deviation_pp !== null && (float) $snapshot->deviation_pp < 0;
            if ($hasRowBreach) {
                $hasBreach = true;
            }

            $rows[] = [
                'kpi_name' => $kr->kpi->name,
                'description' => $kr->description,
                'baseline_value' => $kr->baseline_value,
                'unit' => $kr->kpi->unit,
                'target_value' => (float) $kr->target_value,
                'expected_value' => $hasData ? $snapshot->expected_value : null,
                'actual_value' => $hasData ? $snapshot->actual_value : null,
                'compliance_percentage' => $hasData ? $snapshot->actual_progress_percentage : null,
                'expected_percentage' => $snapshot?->expected_progress_percentage,
                'deviation_pp' => $hasData ? $snapshot->deviation_pp : null,
                'gap' => $gap,
                'source_quality' => $snapshot?->source_quality,
                'has_data' => $hasData,
                'has_breach' => $hasRowBreach,
            ];
        }

        return ['rows' => $rows, 'has_any_data' => $hasAnyData, 'has_breach' => $hasBreach];
    }

    /**
     * Evaluación de elegibilidad de UNA semana — lo que la UI muestra antes de
     * pedir autorización y lo que generate() exige.
     *
     * @return array{status: string, blockers: string[], week: array, subject: ?array, rows: array, letter: ?array}
     */
    public function eligibility(OkrObjective $objective, int $weekNumber): array
    {
        $objective->loadMissing(['employee', 'accountableEmployee', 'branch', 'commitmentLetter']);
        $blockers = [];
        // Motivo CLASIFICADO de cada bloqueo (16-oct-2026) — la UI distingue
        // "OKR no activado", "semana no cerrada", "falta evidencia", "sin
        // incumplimiento", etc., en vez de un error genérico.
        $reasons = [];

        $weekStart = $this->calendar->weekStart($objective->start_date, $weekNumber);
        $weekEnd = $this->calendar->weekEnd($objective->start_date, $weekNumber);
        $weekClosed = Carbon::today()->gt($weekEnd);

        $subject = $objective->scope_type === OkrObjective::SCOPE_EMPLOYEE ? $objective->employee : $objective->accountableEmployee;
        if ($subject === null) {
            $blockers[] = $objective->scope_type === OkrObjective::SCOPE_BRANCH
                ? 'Este OKR de sucursal no tiene un titular formalmente asignado — un Warning solo puede emitirse a la persona evaluada (para gestores, usa su OKR individual).'
                : 'El OKR individual no tiene colaborador asignado.';
            $reasons[] = 'no_subject';
        }
        if ($weekNumber < 1 || $weekNumber > (int) $objective->duration_weeks) {
            $blockers[] = 'La semana indicada está fuera del rango del OKR.';
            $reasons[] = 'out_of_range';
        }
        if (!in_array($objective->lifecycle_status, [OkrObjective::STATUS_ACTIVE, OkrObjective::STATUS_CLOSED], true)) {
            $blockers[] = 'El OKR no está activo — solo se evalúan OKR activados con línea base congelada.';
            $reasons[] = 'not_active';
        }
        if (!$weekClosed) {
            $blockers[] = 'La semana ' . $weekNumber . ' sigue abierta (termina el ' . $weekEnd->format('d/m/Y') . ') — pendiente de evaluación.';
            $reasons[] = 'week_open';
        }
        if ($objective->commitmentLetter === null) {
            $blockers[] = 'No existe una Carta Compromiso vigente para este OKR — el Warning se emite con base en ella.';
            $reasons[] = 'no_letter';
        }
        if (OkrWarning::query()->where('okr_objective_id', $objective->id)->where('week_number', $weekNumber)->where('status', OkrWarning::STATUS_ISSUED)->exists()) {
            $blockers[] = 'Ya existe un Warning vigente para esta semana.';
            $reasons[] = 'duplicate';
        }

        $week = $this->buildWeekSnapshot($objective, $weekNumber);
        if (!$week['has_any_data']) {
            $blockers[] = 'Falta evidencia: la semana no tiene información real suficiente — no se convierte la falta de datos en incumplimiento.';
            $reasons[] = 'no_evidence';
        } elseif (!$week['has_breach']) {
            $blockers[] = 'No existe incumplimiento en la semana seleccionada: el resultado está en o por encima de lo esperado a esa semana.';
            $reasons[] = 'no_breach';
        }

        $status = $blockers === [] ? 'eligible' : (!$weekClosed || !$week['has_any_data'] ? 'pending' : 'not_eligible');

        return [
            'status' => $status,
            // Hay incumplimiento real aunque otro motivo (p. ej. sin carta) impida emitir.
            'has_breach' => $week['has_breach'],
            'blockers' => $blockers,
            'reasons' => $reasons,
            'week' => ['number' => $weekNumber, 'start' => $weekStart->toDateString(), 'end' => $weekEnd->toDateString(), 'closed' => $weekClosed],
            'subject' => $subject ? ['id' => $subject->id, 'full_name' => $subject->full_name] : null,
            'rows' => $week['rows'],
            'letter' => $objective->commitmentLetter ? ['folio' => $objective->commitmentLetter->folio, 'date' => $objective->commitmentLetter->generated_at?->toDateString()] : null,
        ];
    }

    /**
     * @throws RuntimeException si la semana no cumple TODAS las condiciones.
     */
    public function generate(OkrObjective $objective, int $weekNumber, string $correctiveActions, ?string $observations, User $user): OkrWarning
    {
        $check = $this->eligibility($objective, $weekNumber);
        if ($check['status'] !== 'eligible') {
            throw new RuntimeException(implode(' ', $check['blockers']));
        }

        $objective->loadMissing(['branch', 'employee', 'accountableEmployee', 'responsibleUser', 'commitmentLetter']);
        $letter = $objective->commitmentLetter;
        $letterSnapshot = $letter?->snapshot ?? [];

        $snapshot = [
            'objective_title' => $objective->title,
            'scope_type' => $objective->scope_type,
            'subject_employee_id' => $check['subject']['id'],
            'collaborator_name' => $check['subject']['full_name'],
            'branch_name' => $objective->branch?->name,
            // Puesto y jefe inmediato: los REVISADOS en la carta vigente de esta persona.
            'position' => $letterSnapshot['position'] ?? null,
            'immediate_boss' => $letterSnapshot['immediate_boss'] ?? null,
            'responsible_name' => $objective->responsibleUser?->name,
            'letter_folio' => $letter?->folio,
            'letter_date' => $letter?->generated_at?->toDateString(),
            'week_number' => $weekNumber,
            'week_start' => $check['week']['start'],
            'week_end' => $check['week']['end'],
            'rows' => $check['rows'],
        ];

        return DB::transaction(function () use ($objective, $weekNumber, $snapshot, $correctiveActions, $observations, $user) {
            $warning = OkrWarning::query()->create([
                'folio' => 'PENDIENTE-' . uniqid(),
                'okr_objective_id' => $objective->id,
                'subject_employee_id' => $snapshot['subject_employee_id'],
                'status' => OkrWarning::STATUS_ISSUED,
                'week_number' => $weekNumber,
                'week_start' => $snapshot['week_start'], 'week_end' => $snapshot['week_end'],
                'snapshot' => $snapshot,
                'corrective_actions' => $correctiveActions,
                'observations' => $observations,
                'generated_by' => $user->id,
                'generated_at' => now(),
                'stored_path' => '', 'disk' => 'local',
            ]);

            $folio = $this->folioGenerator->forId('WR', $warning->id);
            $disk = 'local';
            $path = 'okr-warnings/' . $objective->id . "/{$folio}.pdf";

            $this->renderer->renderViewToFile('reports.okr-warning-pdf', [
                'folio' => $folio, 'snapshot' => $snapshot,
                'correctiveActions' => $correctiveActions, 'observations' => $observations,
                'generatedAt' => $warning->generated_at, 'generatedByName' => $user->name,
            ], Storage::disk($disk)->path($path), ['full_bleed' => true]);

            $warning->update(['folio' => $folio, 'stored_path' => $path, 'disk' => $disk]);
            $this->logger->log('objective', $objective->id, $user->id, 'warning_issued', null, null, $folio, "Semana {$weekNumber} — {$snapshot['collaborator_name']}");

            return $warning->fresh();
        });
    }

    /** Anulación auditada — PDF y folio se conservan, nunca se modifica en silencio. */
    public function void(OkrWarning $warning, User $user, string $reason): OkrWarning
    {
        if ($warning->isVoided()) {
            throw new RuntimeException('Este Warning ya está anulado.');
        }

        DB::transaction(function () use ($warning, $user, $reason) {
            $warning->update(['status' => OkrWarning::STATUS_VOIDED, 'voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => $reason]);
            $this->logger->log('objective', $warning->okr_objective_id, $user->id, 'warning_voided', null, $warning->folio, null,
                ($warning->isSigned() ? '[Tenía copia firmada] ' : '') . $reason);
        });

        return $warning->fresh();
    }
}
