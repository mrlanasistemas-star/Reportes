<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Models\OkrCommitmentLetter;
use App\Models\OkrObjective;
use App\Services\Okr\OkrCommitmentLetterService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Parte 2/11 del cierre (04-oct-2026) — Carta Compromiso: preview (DRAFT,
 * nunca se guarda), generate (ACTIVE, UNA sola vez, snapshot inmutable) y
 * subida del documento firmado como evidencia (nunca sustituye el snapshot
 * original, ver 2.4).
 */
class CommitmentLetterController extends Controller
{
    use AuthorizesRequests;

    public function preview(Request $request, OkrObjective $objective, OkrCommitmentLetterService $service): StreamedResponse
    {
        $this->authorize('view', $objective);

        // 5/9: el colaborador NUNCA captura nombre/sucursal/puesto/lugar — el
        // sistema ya los conoce (lugar = config corporativa, puesto = dato
        // persistente del Employee). La vista previa usa las MISMAS fuentes
        // que la emisión oficial, nunca un formulario de captura.
        $path = $service->renderPreviewPdf($objective, config('company.document_place'), $objective->employee?->position);

        return response()->streamDownload(function () use ($path) {
            echo File::get($path);
            @unlink($path);
        }, 'carta-compromiso-vista-previa.pdf', ['Content-Type' => 'application/pdf']);
    }

    public function generate(Request $request, OkrObjective $objective, OkrCommitmentLetterService $service): JsonResponse
    {
        // 16: emisión OFICIAL es decisión admin/gerencial — igual que asignar
        // (WarningController::store usa el mismo criterio) — nunca el propio
        // colaborador responsable, aunque tenga 'update' sobre su Objective.
        $this->authorize('assign', $objective);

        try {
            $letter = $service->generate($objective, $request->user(), config('company.document_place'), $objective->employee?->position);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'letter' => [
                'id' => $letter->id, 'folio' => $letter->folio,
                'generated_at' => $letter->generated_at->toDateTimeString(),
                'is_signed' => $letter->isSigned(),
                'download_url' => route('okr.commitment-letters.download', $letter),
            ],
        ]);
    }

    public function download(OkrCommitmentLetter $letter): StreamedResponse
    {
        $this->authorize('view', $letter->objective);
        abort_unless(Storage::disk($letter->disk)->exists($letter->stored_path), 404, 'El documento ya no está disponible.');

        return Storage::disk($letter->disk)->download($letter->stored_path, "{$letter->folio}.pdf");
    }

    public function uploadSigned(Request $request, OkrCommitmentLetter $letter): JsonResponse
    {
        $this->authorize('update', $letter->objective);
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf']]);

        $disk = 'local';
        $path = $request->file('file')->store('okr-commitment-letters/' . $letter->okr_objective_id . '/firmadas', $disk);

        $letter->update([
            'signed_stored_path' => $path, 'signed_disk' => $disk,
            'signed_uploaded_by' => $request->user()->id, 'signed_uploaded_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }
}
