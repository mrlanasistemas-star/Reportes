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
        private readonly OkrDocumentIssuer $issuer,
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
            $blockers[] = 'El OKR no está activado — solo se evalúan OKR activados con línea base congelada.';
            $reasons[] = 'not_active';
        }
        if (!$weekClosed) {
            // Texto acordado (19-oct-2026, 4.7) + la fecha concreta de cierre.
            $blockers[] = 'Todavía no termina la semana evaluada. Podrás evaluar su cumplimiento después del cierre (la semana ' . $weekNumber . ' cierra el ' . $weekEnd->format('d/m/Y') . ').';
            $reasons[] = 'week_open';
        }
        if ($objective->commitmentLetter === null) {
            $blockers[] = $subject !== null
                ? "No existe una Carta Compromiso vigente para {$this->displayName($subject->full_name)}. El Warning se emite con base en ella: emítela primero."
                : 'No existe una Carta Compromiso vigente para este OKR. El Warning se emite con base en ella.';
            $reasons[] = 'no_letter';
        }
        $existingFolio = OkrWarning::query()->where('okr_objective_id', $objective->id)->where('week_number', $weekNumber)->where('status', OkrWarning::STATUS_ISSUED)->value('folio');
        if ($existingFolio !== null) {
            $blockers[] = "Documento ya emitido: esta semana ya tiene un Warning vigente (folio {$existingFolio}). Si tenía datos incorrectos, anúlalo con motivo antes de emitir otro.";
            $reasons[] = 'duplicate';
        }

        $week = $this->buildWeekSnapshot($objective, $weekNumber);
        // Sin Key Results no hay indicador contra el cual medir — no es "sin evidencia".
        if ($week['rows'] === []) {
            $blockers[] = 'Falta KR: este OKR no tiene Key Results configurados, así que no hay indicador contra el cual evaluar la semana.';
            $reasons[] = 'no_key_results';
        } elseif (!$week['has_any_data']) {
            // Semana sin información ≠ incumplimiento. Si el KR que falta es
            // Colocación se dice explícitamente (es la carga más común pendiente).
            $missingPlacement = collect($week['rows'])->contains(fn ($r) => !$r['has_data']
                && str_contains(\Illuminate\Support\Str::of($r['kpi_name'] ?? '')->ascii()->upper()->value(), 'COLOCAC'));
            if ($missingPlacement) {
                $blockers[] = 'Falta cargar el reporte semanal de colocación de la semana ' . $weekNumber . '. Sin ese dato no se puede evaluar; la falta de datos no se interpreta como incumplimiento.';
                $reasons[] = 'no_placement';
            } else {
                $blockers[] = 'Sin evidencia suficiente: la semana no tiene información real suficiente — no se convierte la falta de datos en incumplimiento.';
                $reasons[] = 'no_evidence';
            }
        } elseif (!$week['has_breach']) {
            $blockers[] = 'Este colaborador cumplió su meta y no corresponde emitir un Warning: en la semana ' . $weekNumber . ' su resultado está en o por encima de lo esperado.';
            $reasons[] = 'no_breach';
        }

        $status = $blockers === [] ? 'eligible' : (!$weekClosed || (!$week['has_any_data'] && $week['rows'] !== []) ? 'pending' : 'not_eligible');

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
            'employee_code' => ($letterSnapshot['employee_code'] ?? null) ?: \App\Models\Employee::query()->whereKey($check['subject']['id'])->value('employee_code'),
            'immediate_boss' => $letterSnapshot['immediate_boss'] ?? null,
            'responsible_name' => $objective->responsibleUser?->name,
            'letter_folio' => $letter?->folio,
            'letter_date' => $letter?->generated_at?->toDateString(),
            'week_number' => $weekNumber,
            'week_start' => $check['week']['start'],
            'week_end' => $check['week']['end'],
            'rows' => $check['rows'],
        ];

        // 4.9 (19-oct-2026) — concurrencia: candado de aplicación por OKR
        // (atómico entre procesos aunque las tablas sean MyISAM) + bloqueo de la
        // fila del OKR y nueva comprobación DENTRO de la transacción de que la
        // semana no tenga ya un Warning vigente; el índice único
        // okr_warnings_one_current es la red final. El PDF se escribe de forma
        // atómica; si la emisión no se confirma se borran el PDF y la fila
        // provisional (compensación explícita) — nunca dos vigentes de la misma
        // semana, ni "emitido" sin PDF, ni archivos huérfanos.
        $state = new \ArrayObject(['path' => null, 'id' => null]); // objeto: se comparte entre closures (fn() copia los arrays)
        try {
            return $this->issuer->exclusively('okr-documents:' . $objective->id, fn () => DB::transaction(function () use ($objective, $weekNumber, $snapshot, $correctiveActions, $observations, $user, $state) {
                OkrObjective::query()->whereKey($objective->id)->lockForUpdate()->first();
                $this->assertNoCurrentWarning($objective->id, $weekNumber);

                return $this->issueWarning($objective, $weekNumber, $snapshot, $correctiveActions, $observations, $user, $state);
            }));
        } catch (\Throwable $e) {
            $this->issuer->discard('local', $state['path']);
            $this->issuer->forgetUnconfirmed(OkrWarning::class, $state['id']);
            if (OkrDocumentIssuer::isCurrentGuardViolation($e)) {
                $this->assertNoCurrentWarning($objective->id, $weekNumber);
            }
            throw $e;
        }
    }

    /** Nombre legible para mensajes ("RAFAEL PEREZ CHAO" → "Rafael Perez Chao"). */
    private function displayName(?string $name): string
    {
        return $name === null ? '' : mb_convert_case(mb_strtolower(trim($name)), MB_CASE_TITLE, 'UTF-8');
    }

    private function assertNoCurrentWarning(int $objectiveId, int $weekNumber): void
    {
        $folio = OkrWarning::query()->where('okr_objective_id', $objectiveId)->where('week_number', $weekNumber)
            ->where('status', OkrWarning::STATUS_ISSUED)->value('folio');
        if ($folio !== null) {
            throw new RuntimeException("Documento ya emitido: la semana {$weekNumber} ya tiene un Warning vigente (folio {$folio}). No se emitió otro.");
        }
    }

    private function issueWarning(OkrObjective $objective, int $weekNumber, array $snapshot, string $correctiveActions, ?string $observations, User $user, \ArrayObject $state): OkrWarning
    {
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
        $state['id'] = $warning->id;

        $folio = $this->folioGenerator->forId('WR', $warning->id);
        $disk = 'local';
        $path = 'okr-warnings/' . $objective->id . "/{$folio}.pdf";

        $this->issuer->writePdf($disk, $path, fn (string $tmp) => $this->renderer->renderViewToFile('reports.okr-warning-pdf', [
            'folio' => $folio, 'snapshot' => $snapshot,
            'correctiveActions' => $correctiveActions, 'observations' => $observations,
            'generatedAt' => $warning->generated_at, 'generatedByName' => $user->name,
        ], $tmp, ['full_bleed' => true]));
        $state['path'] = $path;

        $warning->update(['folio' => $folio, 'stored_path' => $path, 'disk' => $disk]);
        $this->logger->log('objective', $objective->id, $user->id, 'warning_issued', null, null, $folio, "Semana {$weekNumber} — {$snapshot['collaborator_name']}");

        return $warning->fresh();
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
