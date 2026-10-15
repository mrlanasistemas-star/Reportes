<?php

namespace App\Services\Okr;

use App\Models\Employee;
use App\Models\OkrCommitmentLetter;
use App\Models\OkrObjective;
use App\Models\User;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Carta Compromiso — generada desde el Objective/KR, nunca un documento aparte.
 *
 * Corrección 15-oct-2026 — el colaborador de la carta es SIEMPRE la persona
 * evaluada, nunca el usuario que creó el OKR ni su responsable administrativo:
 *   - OKR individual → el gestor (employee_id) con SUS KR y metas.
 *   - OKR de sucursal → el TITULAR asignado (accountable_employee_id, p. ej.
 *     el gerente) — si no hay titular, la carta no puede emitirse.
 * Puesto = directorio laboral vigente (OkrPersonnelResolver); solo si no existe
 * se permite capturarlo. Jefe inmediato = sugerencia con fuente que un usuario
 * autorizado DEBE confirmar o corregir antes de emitir.
 *
 * Una carta emitida es inmutable; si tenía datos incorrectos se ANULA con
 * motivo (void) y se emite otra con folio NUEVO que la sustituye — el PDF y el
 * folio originales se conservan.
 */
class OkrCommitmentLetterService
{
    public const BOSS_MISSING_MESSAGE = 'No es posible emitir la Carta Compromiso porque falta confirmar el jefe inmediato.';

    public function __construct(
        private readonly OkrCalendarService $calendar,
        private readonly OkrDocumentFolioGenerator $folioGenerator,
        private readonly BrowsershotPdfRenderer $renderer,
        private readonly OkrPersonnelResolver $personnel,
        private readonly OkrAuditLogger $logger,
        private readonly OkrDocumentIssuer $issuer,
    ) {
    }

    public function subjectFor(OkrObjective $objective): ?Employee
    {
        $objective->loadMissing(['employee', 'accountableEmployee']);

        return $objective->scope_type === OkrObjective::SCOPE_EMPLOYEE ? $objective->employee : $objective->accountableEmployee;
    }

    /**
     * Datos laborales del sujeto + sugerencias, para la pantalla de revisión.
     *
     * @return array{subject: ?array, profile: array, boss_suggestion: ?array, accountable_suggestion: ?array}
     */
    public function reviewContext(OkrObjective $objective): array
    {
        $subject = $this->subjectFor($objective);
        $profile = $subject ? $this->personnel->profileFor($subject->id) : ['position' => null, 'source' => null, 'status' => null];
        $boss = $subject && $objective->branch_id ? $this->personnel->suggestImmediateBoss($subject->id, (int) $objective->branch_id) : null;
        $accountableSuggestion = $objective->scope_type === OkrObjective::SCOPE_BRANCH && $objective->branch_id && !$objective->accountable_employee_id
            ? $this->personnel->suggestBranchManager((int) $objective->branch_id)
            : null;

        return [
            'subject' => $subject ? ['id' => $subject->id, 'full_name' => $subject->full_name] : null,
            'profile' => $profile,
            'boss_suggestion' => $boss,
            'accountable_suggestion' => $accountableSuggestion,
        ];
    }

    /**
     * @param  array{position?: ?string, immediate_boss?: ?string, observations?: ?string}  $overrides
     *   position: solo se usa si el directorio vigente NO tiene puesto.
     *   immediate_boss: confirmación/corrección del jefe inmediato (obligatoria para emitir).
     */
    public function buildSnapshot(OkrObjective $objective, string $place, array $overrides = []): array
    {
        $objective->loadMissing(['branch', 'employee', 'accountableEmployee', 'responsibleUser', 'keyResults.kpi']);
        $subject = $this->subjectFor($objective);
        $profile = $subject ? $this->personnel->profileFor($subject->id) : ['position' => null, 'source' => null];
        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        $position = $profile['position'] ?? null;
        $positionSource = $position ? $profile['source'] : null;
        if ($position === null && ($typed = $clean($overrides['position'] ?? null))) {
            $position = $typed;
            $positionSource = 'Capturado en la revisión (sin puesto en el directorio vigente)';
        }

        $boss = $clean($overrides['immediate_boss'] ?? null);
        $bossIssue = $boss !== null ? $this->bossIssue($objective, $subject, $boss) : null;
        if ($bossIssue !== null) {
            $boss = null; // nunca se imprime ni se emite un jefe sin respaldo
        }

        return [
            'place' => $place,
            'scope_type' => $objective->scope_type,
            'subject_employee_id' => $subject?->id,
            'collaborator_name' => $subject?->full_name,
            // Número de empleado (15-oct-2026, cierre de errores OKR) — requerido
            // en la revisión y en el documento; identificador estable aunque el
            // nombre o la sucursal cambien de representación.
            // 16-oct-2026: el número de empleado es el del directorio Lendus
            // (p. ej. ATLX12513); la clave NOI se conserva aparte.
            'employee_code' => ($profile['code'] ?? null) ?: $subject?->employee_code,
            'noi_code' => $subject?->employee_code,
            'position' => $position,
            'position_source' => $positionSource,
            'branch_name' => $objective->branch?->name,
            'immediate_boss' => $boss,
            'immediate_boss_source' => $boss ? 'Confirmado por el emisor en la revisión' : null,
            'immediate_boss_issue' => $bossIssue,
            // Responsable ADMINISTRATIVO del seguimiento — dato informativo,
            // nunca el colaborador ni el firmante por defecto.
            'responsible_name' => $objective->responsibleUser?->name,
            'objective_title' => $objective->title,
            'observations' => $clean($overrides['observations'] ?? null),
            'start_date' => $objective->start_date->toDateString(),
            'end_date' => $objective->end_date->toDateString(),
            'duration_weeks' => $objective->duration_weeks,
            // KR de ESTE Objective: la carta del gestor A trae SU meta, la del
            // gestor B la suya y la de sucursal la de sucursal.
            'key_results' => $objective->keyResults->map(fn ($kr) => [
                'kpi_name' => $kr->kpi->name,
                'description' => $kr->description,
                'unit' => $kr->kpi->unit,
                'baseline_value' => $kr->baseline_value,
                'baseline_source' => $kr->baseline_source,
                'baseline_locked' => $kr->baseline_locked_at !== null,
                'baseline_label' => match (true) {
                    $kr->baseline_source === OkrBaselineGuard::SOURCE_MANUAL_INDIVIDUAL => 'Línea base manual del gestor' . ($kr->baseline_locked_at ? ' (congelada)' : ''),
                    $kr->baseline_source === 'manual_authorized' => 'Línea base manual autorizada' . ($kr->baseline_locked_at ? ' (congelada)' : ''),
                    default => 'Línea base' . ($kr->baseline_locked_at ? ' congelada (Reportería)' : ''),
                },
                'increment_value' => $kr->baseline_value !== null ? round((float) $kr->target_value - (float) $kr->baseline_value, 2) : null,
                'target_value' => $kr->target_value,
                'weight' => $kr->weight,
            ])->all(),
        ];
    }

    /**
     * Jefe inmediato capturado SIN relación jerárquica respaldada (15-oct-2026).
     * Se rechaza: (a) el propio colaborador evaluado; (b) el responsable
     * administrativo o el creador del OKR (usuarios del sistema, típicamente el
     * admin), salvo que esa misma persona sea el jefe sugerido por el
     * directorio laboral vigente (subgerente/gerente activo de la sucursal).
     */
    private function bossIssue(OkrObjective $objective, ?Employee $subject, string $boss): ?string
    {
        $norm = fn (?string $v) => $v === null ? '' : \Illuminate\Support\Str::of($v)->ascii()->upper()->squish()->value();
        $bossNorm = $norm($boss);

        if ($subject && $bossNorm === $norm($subject->full_name)) {
            return 'no puede ser el mismo colaborador evaluado';
        }

        $objective->loadMissing(['responsibleUser', 'creator']);
        $systemUsers = array_filter([$objective->responsibleUser?->name, $objective->creator?->name]);
        foreach ($systemUsers as $userName) {
            if ($bossNorm !== $norm($userName)) {
                continue;
            }
            $suggested = $subject && $objective->branch_id
                ? ($this->personnel->suggestImmediateBoss($subject->id, (int) $objective->branch_id)['name'] ?? null)
                : null;
            if ($suggested === null || $norm($suggested) !== $bossNorm) {
                return 'el nombre capturado es el usuario que creó o administra el OKR y el directorio laboral no lo respalda como jefe de este colaborador';
            }
        }

        return null;
    }

    /**
     * Datos indispensables faltantes — se muestran ANTES de emitir; nunca se
     * emite con huecos.
     *
     * @return array<string, string> campo => explicación
     */
    public function missingFields(OkrObjective $objective, array $snapshot): array
    {
        $missing = [];
        if (empty($snapshot['collaborator_name'])) {
            $missing['collaborator_name'] = $objective->scope_type === OkrObjective::SCOPE_BRANCH
                ? 'Titular del OKR de sucursal (quién acepta la responsabilidad general)'
                : 'Colaborador evaluado';
        }
        $labels = [
            'position' => 'Puesto',
            'branch_name' => 'Sucursal / Área',
            'immediate_boss' => 'Jefe inmediato confirmado',
            'start_date' => 'Fecha de inicio',
            'end_date' => 'Fecha de término',
            'objective_title' => 'Objetivo principal',
        ];
        foreach ($labels as $key => $label) {
            if (!is_string($snapshot[$key] ?? null) || trim($snapshot[$key]) === '') {
                $missing[$key] = $label;
            }
        }
        if (!empty($snapshot['immediate_boss_issue'])) {
            $missing['immediate_boss'] = 'Jefe inmediato confirmado (' . $snapshot['immediate_boss_issue'] . ')';
        }
        if (empty($snapshot['key_results'])) {
            $missing['key_results'] = 'Key Results con meta (este OKR no tiene KR configurados)';
        }

        return $missing;
    }

    /** DRAFT/revisión — previsualización READ-ONLY, nunca se persiste nada. */
    public function renderPreviewPdf(OkrObjective $objective, string $place, array $overrides = []): string
    {
        $snapshot = $this->buildSnapshot($objective, $place, $overrides);
        $path = storage_path('app/tmp/okr-carta-preview-' . $objective->id . '-' . uniqid() . '.pdf');

        $this->renderer->renderViewToFile('reports.okr-commitment-letter-pdf', [
            'folio' => 'VISTA PREVIA — SIN EMITIR', 'snapshot' => $snapshot,
            'generatedAt' => now(), 'generatedByName' => null, 'isPreview' => true,
        ], $path, ['full_bleed' => true]);

        return $path;
    }

    /**
     * @throws RuntimeException si el Objective no está activado o faltan datos.
     */
    public function generate(OkrObjective $objective, User $user, string $place, array $overrides = []): OkrCommitmentLetter
    {
        $existing = $objective->commitmentLetter;
        if ($existing) {
            return $existing; // carta vigente INMUTABLE — nunca se regenera (2.4)
        }

        if ($objective->activated_at === null) {
            throw new RuntimeException('Este OKR todavía no se ha activado — la Carta Compromiso definitiva representa el OKR ACTIVADO con su línea base congelada.');
        }

        $snapshot = $this->buildSnapshot($objective, $place, $overrides);
        $missing = $this->missingFields($objective, $snapshot);
        if ($missing !== []) {
            // Jefe inmediato sin confirmar: mensaje acordado con el usuario
            // (15-oct-2026) + acción "Completar información" en la UI.
            if (isset($missing['immediate_boss'])) {
                $others = array_diff_key($missing, ['immediate_boss' => true]);
                throw new OkrCommitmentLetterIncompleteException(
                    self::BOSS_MISSING_MESSAGE . ($others !== [] ? ' También falta: ' . implode(', ', $others) . '.' : ''),
                    $missing,
                );
            }
            throw new OkrCommitmentLetterIncompleteException('Faltan datos indispensables para emitir la Carta Compromiso: ' . implode(', ', $missing) . '.', $missing);
        }

        $replaces = $objective->commitmentLetters()->where('status', OkrCommitmentLetter::STATUS_VOIDED)->latest('id')->value('id');

        // 4.9 (19-oct-2026) — concurrencia: doble clic o dos administradores
        // emitiendo a la vez. (1) Candado de aplicación por OKR (Cache::lock,
        // atómico entre procesos aunque las tablas sean MyISAM sin bloqueos de
        // fila) + bloqueo de la fila del OKR y nueva búsqueda de la carta
        // vigente DENTRO de la transacción; (2) el índice único
        // okr_letters_one_current es la red final; (3) el PDF se escribe de
        // forma atómica y, si la emisión no se confirma, se borran el PDF y la
        // fila provisional (compensación explícita: MyISAM no revierte
        // transacciones) — nunca dos vigentes, ni "emitida" sin PDF, ni huérfanos.
        $state = new \ArrayObject(['path' => null, 'id' => null]); // objeto: se comparte entre closures (fn() copia los arrays)
        try {
            return $this->issuer->exclusively('okr-documents:' . $objective->id, fn () => DB::transaction(function () use ($objective, $user, $place, $snapshot, $replaces, $state) {
                OkrObjective::query()->whereKey($objective->id)->lockForUpdate()->first();
                $current = OkrCommitmentLetter::query()->where('okr_objective_id', $objective->id)
                    ->where('status', OkrCommitmentLetter::STATUS_ISSUED)->latest('id')->first();
                if ($current) {
                    return $current; // otra solicitud ya la emitió — se devuelve la MISMA carta
                }

                return $this->issueLetter($objective, $user, $place, $snapshot, $replaces, $state);
            }));
        } catch (\Throwable $e) {
            $this->issuer->discard('local', $state['path']);
            $this->issuer->forgetUnconfirmed(OkrCommitmentLetter::class, $state['id']);
            if (OkrDocumentIssuer::isCurrentGuardViolation($e)) {
                $current = OkrCommitmentLetter::query()->where('okr_objective_id', $objective->id)
                    ->where('status', OkrCommitmentLetter::STATUS_ISSUED)->latest('id')->first();
                if ($current) {
                    return $current;
                }
            }
            throw $e;
        }
    }

    private function issueLetter(OkrObjective $objective, User $user, string $place, array $snapshot, ?int $replaces, \ArrayObject $state): OkrCommitmentLetter
    {
        $letter = OkrCommitmentLetter::query()->create([
            'folio' => 'PENDIENTE-' . uniqid(), // se fija abajo, ya con id real
            'okr_objective_id' => $objective->id,
            'subject_employee_id' => $snapshot['subject_employee_id'],
            'status' => OkrCommitmentLetter::STATUS_ISSUED,
            'replaces_letter_id' => $replaces,
            'snapshot' => $snapshot,
            'place' => $place,
            'generated_by' => $user->id,
            'generated_at' => now(),
            'stored_path' => '', 'disk' => 'local',
        ]);
        $state['id'] = $letter->id;

        $folio = $this->folioGenerator->forId('CC', $letter->id);
        $disk = 'local';
        $path = 'okr-commitment-letters/' . $objective->id . "/{$folio}.pdf";

        $this->issuer->writePdf($disk, $path, fn (string $tmp) => $this->renderer->renderViewToFile('reports.okr-commitment-letter-pdf', [
            'folio' => $folio, 'snapshot' => $snapshot,
            'generatedAt' => $letter->generated_at, 'generatedByName' => $user->name, 'isPreview' => false,
        ], $tmp, ['full_bleed' => true]));
        $state['path'] = $path;

        $letter->update(['folio' => $folio, 'stored_path' => $path, 'disk' => $disk]);
        $this->logger->log('objective', $objective->id, $user->id, 'commitment_letter_issued', null, null, $folio,
            $replaces ? "Sustituye a la carta anulada #{$replaces}." : null);

        return $letter->fresh();
    }

    /**
     * Anulación auditada (15-oct-2026): el PDF y el folio se conservan; la carta
     * deja de ser vigente y puede emitirse otra con folio nuevo.
     */
    public function void(OkrCommitmentLetter $letter, User $user, string $reason): OkrCommitmentLetter
    {
        if ($letter->isVoided()) {
            throw new RuntimeException('Esta carta ya está anulada.');
        }

        DB::transaction(function () use ($letter, $user, $reason) {
            $letter->update(['status' => OkrCommitmentLetter::STATUS_VOIDED, 'voided_at' => now(), 'voided_by' => $user->id, 'void_reason' => $reason]);
            $this->logger->log('objective', $letter->okr_objective_id, $user->id, 'commitment_letter_voided', null, $letter->folio, null,
                ($letter->isSigned() ? '[Tenía copia firmada] ' : '') . $reason);
        });

        return $letter->fresh();
    }
}
