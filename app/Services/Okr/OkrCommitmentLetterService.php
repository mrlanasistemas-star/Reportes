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

    public function buildSnapshot(OkrObjective $objective, string $place, ?string $position): array
    {
        $objective->loadMissing(['branch', 'employee', 'responsibleUser', 'keyResults.kpi']);

        return [
            'place' => $place,
            'position' => $position,
            'objective_title' => $objective->title,
            'scope_type' => $objective->scope_type,
            'branch_name' => $objective->branch?->name,
            'employee_name' => $objective->employee?->full_name,
            'responsible_name' => $objective->responsibleUser?->name,
            'start_date' => $objective->start_date->toDateString(),
            'end_date' => $objective->end_date->toDateString(),
            'duration_weeks' => $objective->duration_weeks,
            'key_results' => $objective->keyResults->map(fn ($kr) => [
                'kpi_name' => $kr->kpi->name,
                'unit' => $kr->kpi->unit,
                'baseline_value' => $kr->baseline_value,
                'target_value' => $kr->target_value,
                'weight' => $kr->weight,
            ])->all(),
        ];
    }

    /** DRAFT — previsualización READ-ONLY con los datos de HOY, nunca se persiste nada (2.1). */
    public function renderPreviewPdf(OkrObjective $objective, string $place, ?string $position): string
    {
        $snapshot = $this->buildSnapshot($objective, $place, $position);
        $path = storage_path('app/tmp/okr-carta-preview-' . $objective->id . '-' . uniqid() . '.pdf');

        $this->renderer->renderViewToFile('reports.okr-commitment-letter-pdf', [
            'folio' => 'VISTA PREVIA — SIN EMITIR', 'snapshot' => $snapshot,
            'generatedAt' => now(), 'generatedByName' => null, 'isPreview' => true,
        ], $path, ['footer_left' => 'MR LANA · Carta Compromiso (vista previa)']);

        return $path;
    }

    /**
     * @throws RuntimeException si el Objective nunca se ha activado.
     */
    public function generate(OkrObjective $objective, User $user, string $place, ?string $position): OkrCommitmentLetter
    {
        $existing = $objective->commitmentLetter;
        if ($existing) {
            return $existing; // snapshot INMUTABLE — nunca se regenera (2.4)
        }

        if ($objective->activated_at === null) {
            throw new RuntimeException('Este Objective todavía no se ha activado — la Carta Compromiso definitiva representa el Objective ACTIVADO.');
        }

        $snapshot = $this->buildSnapshot($objective, $place, $position);

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
            ], $absolutePath, ['footer_left' => 'MR LANA · Carta Compromiso']);

            $letter->update(['folio' => $folio, 'stored_path' => $path, 'disk' => $disk]);

            return $letter->fresh();
        });
    }
}
