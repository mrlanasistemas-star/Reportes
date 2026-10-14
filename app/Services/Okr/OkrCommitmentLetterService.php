<?php

namespace App\Services\Okr;

use App\Models\OkrCommitmentLetter;
use App\Models\OkrObjective;
use App\Models\User;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Parte 2 del cierre (04-oct-2026) — Carta Compromiso automática desde el
 * Objective/KR, NUNCA un documento inventado aparte. Reglas:
 *   - DRAFT: solo previsualización (preview()), nunca se guarda nada.
 *   - Requiere que el Objective YA se haya activado (activated_at) — "la
 *     carta definitiva representa el Objective ACTIVADO" (2.1).
 *   - UNA sola carta por Objective, snapshot INMUTABLE (2.4): si se vuelve a
 *     pedir, se devuelve la YA EMITIDA — nunca se regenera ni se pisa aunque
 *     la meta cambie después.
 *   - scope=employee usa la META INDIVIDUAL del KR de ESE Objective, nunca
 *     la de la sucursal (2.2) — scope=branch usa la del Objective de
 *     sucursal, y cada hijo individual tiene SU PROPIA carta (2.3), porque
 *     cada uno es un Objective distinto con su propio snapshot.
 */
class OkrCommitmentLetterService
{
    public function __construct(
        private readonly OkrCalendarService $calendar,
        private readonly OkrDocumentFolioGenerator $folioGenerator,
        private readonly BrowsershotPdfRenderer $renderer,
    ) {
    }

    /**
     * Campos AUTORIZADOS que el emisor puede capturar/corregir antes de emitir
     * (14-oct-2026): puesto, jefe inmediato y observaciones. Todo lo demás
     * (colaborador, sucursal, fechas, objetivo, KPI, metas) sale SIEMPRE del
     * Objective — nunca se captura a mano.
     *
     * @param  array{position?: ?string, immediate_boss?: ?string, observations?: ?string}  $overrides
     */
    public function buildSnapshot(OkrObjective $objective, string $place, ?string $position, array $overrides = []): array
    {
        $objective->loadMissing(['branch', 'employee', 'responsibleUser.employee', 'keyResults.kpi']);
        $isEmployee = $objective->scope_type === OkrObjective::SCOPE_EMPLOYEE;

        // Colaborador: el gestor del OKR individual; en un OKR de sucursal, el
        // responsable titular de ese OKR (nunca un nombre inventado).
        $collaborator = $isEmployee ? $objective->employee?->full_name : $objective->responsibleUser?->name;
        $defaultPosition = $isEmployee ? ($position ?? $objective->employee?->position) : $objective->responsibleUser?->employee?->position;
        // Jefe inmediato por defecto: el responsable de seguimiento del OKR
        // individual. Para el de sucursal no hay un dato confiable — se exige
        // capturarlo (campo autorizado) antes de emitir.
        $defaultBoss = $isEmployee ? $objective->responsibleUser?->name : null;
        $clean = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'place' => $place,
            'position' => $clean($overrides['position'] ?? null) ?? $defaultPosition,
            'objective_title' => $objective->title,
            'scope_type' => $objective->scope_type,
            'branch_name' => $objective->branch?->name,
            'collaborator_name' => $collaborator,
            'employee_name' => $objective->employee?->full_name,
            'immediate_boss' => $clean($overrides['immediate_boss'] ?? null) ?? $defaultBoss,
            'responsible_name' => $objective->responsibleUser?->name,
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
                'target_value' => $kr->target_value,
                'weight' => $kr->weight,
            ])->all(),
        ];
    }

    /**
     * Datos indispensables que faltan para emitir la carta definitiva — se
     * muestran ANTES de generar (revisión); nunca se emite con huecos.
     *
     * @return array<string, string> campo => etiqueta
     */
    public function missingFields(array $snapshot): array
    {
        $required = [
            'collaborator_name' => 'Nombre del colaborador',
            'position' => 'Puesto',
            'branch_name' => 'Sucursal / Área',
            'immediate_boss' => 'Jefe inmediato',
            'start_date' => 'Fecha de inicio',
            'end_date' => 'Fecha de término',
            'objective_title' => 'Objetivo principal',
        ];

        $missing = [];
        foreach ($required as $key => $label) {
            if (!is_string($snapshot[$key] ?? null) || trim($snapshot[$key]) === '') {
                $missing[$key] = $label;
            }
        }
        if (empty($snapshot['key_results'])) {
            $missing['key_results'] = 'KPI y meta comprometida';
        }

        return $missing;
    }

    /** DRAFT — previsualización READ-ONLY con los datos de HOY, nunca se persiste nada (2.1). */
    public function renderPreviewPdf(OkrObjective $objective, string $place, ?string $position, array $overrides = []): string
    {
        $snapshot = $this->buildSnapshot($objective, $place, $position, $overrides);
        $path = storage_path('app/tmp/okr-carta-preview-' . $objective->id . '-' . uniqid() . '.pdf');

        $this->renderer->renderViewToFile('reports.okr-commitment-letter-pdf', [
            'folio' => 'VISTA PREVIA — SIN EMITIR', 'snapshot' => $snapshot,
            'generatedAt' => now(), 'generatedByName' => null, 'isPreview' => true,
        ], $path, ['full_bleed' => true]);

        return $path;
    }

    /**
     * @throws RuntimeException si el Objective nunca se ha activado.
     */
    public function generate(OkrObjective $objective, User $user, string $place, ?string $position, array $overrides = []): OkrCommitmentLetter
    {
        $existing = $objective->commitmentLetter;
        if ($existing) {
            return $existing; // snapshot INMUTABLE — nunca se regenera (2.4)
        }

        if ($objective->activated_at === null) {
            throw new RuntimeException('Este Objective todavía no se ha activado — la Carta Compromiso definitiva representa el Objective ACTIVADO.');
        }

        $snapshot = $this->buildSnapshot($objective, $place, $position, $overrides);
        $missing = $this->missingFields($snapshot);
        if ($missing !== []) {
            throw new RuntimeException('Faltan datos indispensables para emitir la Carta Compromiso: ' . implode(', ', $missing) . '. Captúralos en la revisión antes de generar.');
        }

        return DB::transaction(function () use ($objective, $user, $place, $snapshot) {
            $letter = OkrCommitmentLetter::query()->create([
                'folio' => 'PENDIENTE', // se fija abajo, ya con id real
                'okr_objective_id' => $objective->id,
                'snapshot' => $snapshot,
                'place' => $place,
                'generated_by' => $user->id,
                'generated_at' => now(),
                'stored_path' => '', 'disk' => 'local',
            ]);

            $folio = $this->folioGenerator->forId('CC', $letter->id);
            $disk = 'local';
            $path = 'okr-commitment-letters/' . $objective->id . "/{$folio}.pdf";
            $absolutePath = Storage::disk($disk)->path($path);

            $this->renderer->renderViewToFile('reports.okr-commitment-letter-pdf', [
                'folio' => $folio, 'snapshot' => $snapshot,
                'generatedAt' => $letter->generated_at, 'generatedByName' => $user->name, 'isPreview' => false,
            ], $absolutePath, ['full_bleed' => true]);

            $letter->update(['folio' => $folio, 'stored_path' => $path, 'disk' => $disk]);

            return $letter->fresh();
        });
    }
}
