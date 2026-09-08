<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Okr\StoreEvidenceRequest;
use App\Models\OkrEvidence;
use App\Models\OkrObjective;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Módulo OKR — evidencias (sección 30 del pedido original).
 *
 * CORRECCIÓN 09-sep-2026 (punto 8 — evidencias privadas): las evidencias
 * NUEVAS se guardan en el disco 'local' (storage/app/private — nunca
 * expuesto por URL pública), no en 'public'. La descarga sigue siendo
 * EXCLUSIVAMENTE vía download() con autorización. Las evidencias YA
 * existentes en 'public' (antes de esta corrección) se leen tal cual — cada
 * fila guarda su propio `disk`, nunca se asume uno fijo.
 */
class EvidenceController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreEvidenceRequest $request, OkrObjective $objective): RedirectResponse
    {
        $this->authorize('uploadEvidence', $objective);

        $file = $request->file('file');
        $disk = 'local';
        $path = $file->store('okr-evidences/' . $objective->id, $disk);

        // Nunca se asigna semana 0 automáticamente (punto 9 de la auditoría) —
        // si el Objective todavía no inicia, la evidencia queda sin semana
        // hasta que el usuario indique una explícitamente.
        $currentWeek = $objective->currentWeekNumber();
        $objective->evidences()->create([
            'okr_key_result_id' => $request->input('okr_key_result_id'),
            'week_number'       => $request->input('week_number') ?? ($currentWeek >= 1 ? $currentWeek : null),
            'original_name'     => $file->getClientOriginalName(),
            'stored_path'       => $path,
            'disk'              => $disk,
            'mime_type'         => $file->getMimeType(),
            'size_bytes'        => $file->getSize(),
            'uploaded_by'       => auth()->id(),
            'comment'           => $request->input('comment'),
        ]);

        return back()->with('success', 'Evidencia cargada.');
    }

    public function download(OkrEvidence $evidence): StreamedResponse
    {
        $this->authorize('view', $evidence->objective);

        $disk = $evidence->disk ?: 'public'; // compat legado — evidencias de antes de esta corrección
        abort_unless(Storage::disk($disk)->exists($evidence->stored_path), 404, 'El archivo ya no está disponible.');

        return Storage::disk($disk)->download($evidence->stored_path, $evidence->original_name);
    }
}
