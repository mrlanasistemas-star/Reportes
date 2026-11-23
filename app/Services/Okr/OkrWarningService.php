<?php

namespace App\Services\Okr;

use App\Models\Employee;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\OkrProgressSnapshot;
use App\Models\OkrWarning;
use App\Models\User;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

    /**
     * @param  OkrCalendarService  $calendar  Inicio/fin de cada semana del OKR.
     * @param  OkrDocumentFolioGenerator  $folioGenerator  Folio WR-AÑO-000000.
     * @param  BrowsershotPdfRenderer  $renderer  Motor que renderiza la vista Blade a PDF.
     * @param  OkrAuditLogger  $logger  Auditoría de emisión/anulación.
     * @param  OkrDocumentIssuer  $issuer  Escritura atómica del PDF, candado y compensación.
     */
    public function __construct(
        private readonly OkrCalendarService $calendar,
        private readonly OkrDocumentFolioGenerator $folioGenerator,
        private readonly BrowsershotPdfRenderer $renderer,
        private readonly OkrAuditLogger $logger,
        private readonly OkrDocumentIssuer $issuer,
    ) {}

    /**
     * Arma la foto de una semana del OKR a partir de los snapshots ya
     * guardados de cada KR: valores real/esperado, cumplimiento, desviación y
     * brecha contra lo esperado (según dirección del KPI). Solo cuenta como
     * dato el snapshot con valor real y calidad suficiente (no faltante ni
     * "último disponible"); hay incumplimiento si la desviación es negativa.
     *
     * Efecto: solo lecturas (KR, KPI y okr_progress_snapshots; una consulta por KR).
     *
     * @param  OkrObjective  $objective  Objective evaluado.
     * @param  int  $weekNumber  Semana a evaluar.
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
                && ! in_array($snapshot->source_quality, self::INSUFFICIENT_QUALITY, true);
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
     * Aplica las 6 condiciones del docblock de la clase y acumula bloqueos con
     * mensaje en español y motivo clasificado (no_subject, out_of_range,
     * not_active, week_open, no_letter, duplicate, no_key_results,
     * no_placement, no_evidence, no_breach). Estado: 'eligible' sin bloqueos;
     * 'pending' si la semana no ha cerrado o no hay datos todavía; si no,
     * 'not_eligible'.
     *
     * Efecto: solo lecturas.
     *
     * @param  OkrObjective  $objective  Objective evaluado.
     * @param  int  $weekNumber  Semana a evaluar (1-based).
     * @return array{status: string, has_breach: bool, blockers: string[], reasons: string[], week: array, subject: ?array, rows: array, letter: ?array}
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
        if (! in_array($objective->lifecycle_status, [OkrObjective::STATUS_ACTIVE, OkrObjective::STATUS_CLOSED], true)) {
            $blockers[] = 'El OKR no está activado — solo se evalúan OKR activados con línea base congelada.';
            $reasons[] = 'not_active';
        }
        if (! $weekClosed) {
            // Texto acordado (19-oct-2026, 4.7) + la fecha concreta de cierre.
            $blockers[] = 'Todavía no termina la semana evaluada. Podrás evaluar su cumplimiento después del cierre (la semana '.$weekNumber.' cierra el '.$weekEnd->format('d/m/Y').').';
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
        } elseif (! $week['has_any_data']) {
            // Semana sin información ≠ incumplimiento. Si el KR que falta es
            // Colocación se dice explícitamente (es la carga más común pendiente).
            $missingPlacement = collect($week['rows'])->contains(fn ($r) => ! $r['has_data']
                && str_contains(Str::of($r['kpi_name'] ?? '')->ascii()->upper()->value(), 'COLOCAC'));
            if ($missingPlacement) {
                $blockers[] = 'Falta cargar el reporte semanal de colocación de la semana '.$weekNumber.'. Sin ese dato no se puede evaluar; la falta de datos no se interpreta como incumplimiento.';
                $reasons[] = 'no_placement';
            } else {
                $blockers[] = 'Sin evidencia suficiente: la semana no tiene información real suficiente — no se convierte la falta de datos en incumplimiento.';
                $reasons[] = 'no_evidence';
            }
        } elseif (! $week['has_breach']) {
            $blockers[] = 'Este colaborador cumplió su meta y no corresponde emitir un Warning: en la semana '.$weekNumber.' su resultado está en o por encima de lo esperado.';
            $reasons[] = 'no_breach';
        }

        $status = $blockers === [] ? 'eligible' : (! $weekClosed || (! $week['has_any_data'] && $week['rows'] !== []) ? 'pending' : 'not_eligible');

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
     * Emite el Warning Rojo de una semana (decisión de un usuario autorizado).
     * Revalida la elegibilidad, congela un snapshot (datos de la semana, puesto
     * y jefe inmediato de la Carta Compromiso vigente) y emite bajo candado
     * exclusivo por OKR + transacción con nueva verificación de duplicado.
     * Ante cualquier fallo borra el PDF escrito y la fila provisional.
     *
     * Efecto: INSERT/UPDATE en okr_warnings, PDF en disco 'local'
     * (okr-warnings/{objective}/{folio}.pdf), auditoría 'warning_issued'.
     *
     * @param  OkrObjective  $objective  Objective evaluado.
     * @param  int  $weekNumber  Semana del incumplimiento.
     * @param  string  $correctiveActions  Acciones correctivas acordadas (texto del documento).
     * @param  string|null  $observations  Observaciones adicionales.
     * @param  User  $user  Usuario que emite.
     * @return OkrWarning Warning emitido con folio definitivo.
     *
     * @throws RuntimeException si la semana no cumple TODAS las condiciones o ya hay un Warning vigente.
     * @throws \Throwable Fallos técnicos del PDF/BD se relanzan tras compensar.
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
            'employee_code' => ($letterSnapshot['employee_code'] ?? null) ?: Employee::query()->whereKey($check['subject']['id'])->value('employee_code'),
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
            return $this->issuer->exclusively('okr-documents:'.$objective->id, fn () => DB::transaction(function () use ($objective, $weekNumber, $snapshot, $correctiveActions, $observations, $user, $state) {
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

    /**
     * Nombre legible para mensajes ("RAFAEL PEREZ CHAO" → "Rafael Perez Chao").
     *
     * @param  string|null  $name  Nombre tal como está en BD.
     * @return string Nombre en formato Título ('' si es null).
     */
    private function displayName(?string $name): string
    {
        return $name === null ? '' : mb_convert_case(mb_strtolower(trim($name)), MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Verifica que la semana no tenga ya un Warning vigente (status emitido).
     *
     * @param  int  $objectiveId  Id del Objective.
     * @param  int  $weekNumber  Semana.
     *
     * @throws RuntimeException Si ya existe, con su folio en el mensaje.
     */
    private function assertNoCurrentWarning(int $objectiveId, int $weekNumber): void
    {
        $folio = OkrWarning::query()->where('okr_objective_id', $objectiveId)->where('week_number', $weekNumber)
            ->where('status', OkrWarning::STATUS_ISSUED)->value('folio');
        if ($folio !== null) {
            throw new RuntimeException("Documento ya emitido: la semana {$weekNumber} ya tiene un Warning vigente (folio {$folio}). No se emitió otro.");
        }
    }

    /**
     * Inserta la fila con folio provisional "PENDIENTE-…", calcula el folio
     * definitivo con el id, escribe el PDF de forma atómica y actualiza la fila
     * con folio y ruta. Registra en $state el id y la ruta para que generate()
     * pueda compensar si algo falla.
     *
     * Efecto: INSERT + UPDATE en okr_warnings, escritura de PDF, auditoría.
     *
     * @param  OkrObjective  $objective  Objective evaluado.
     * @param  int  $weekNumber  Semana.
     * @param  array  $snapshot  Datos congelados del documento.
     * @param  string  $correctiveActions  Acciones correctivas.
     * @param  string|null  $observations  Observaciones.
     * @param  User  $user  Usuario emisor.
     * @param  \ArrayObject  $state  Estado compartido ['path', 'id'] para la compensación.
     * @return OkrWarning Warning recargado.
     */
    private function issueWarning(OkrObjective $objective, int $weekNumber, array $snapshot, string $correctiveActions, ?string $observations, User $user, \ArrayObject $state): OkrWarning
    {
        $warning = OkrWarning::query()->create([
            'folio' => 'PENDIENTE-'.uniqid(),
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
        $path = 'okr-warnings/'.$objective->id."/{$folio}.pdf";

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

    /**
     * Anulación auditada — PDF y folio se conservan, nunca se modifica en silencio.
     * Si tenía copia firmada se indica en el motivo auditado.
     *
     * Efecto: UPDATE de okr_warnings (status anulado, voided_at/by, motivo) y
     * auditoría 'warning_voided' en una transacción.
     *
     * @param  OkrWarning  $warning  Warning a anular.
     * @param  User  $user  Usuario que anula.
     * @param  string  $reason  Motivo obligatorio de la anulación.
     * @return OkrWarning Warning recargado.
     *
     * @throws RuntimeException Si ya estaba anulado.
     */
    public function void(OkrWarning $warning, User $user, string $reason): OkrWarning
    {
        if ($warning->isVoided()) {
            throw new RuntimeException('Este Warning ya está anulado.');
        }

        DB::transaction(function () use ($warning, $user, $reason) {
            $warning->update(['status' => OkrWarning::STATUS_VOIDED, 'voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => $reason]);
            $this->logger->log('objective', $warning->okr_objective_id, $user->id, 'warning_voided', null, $warning->folio, null,
                ($warning->isSigned() ? '[Tenía copia firmada] ' : '').$reason);
        });

        return $warning->fresh();
    }
}
