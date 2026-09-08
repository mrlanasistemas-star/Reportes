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
 * Módulo OKR (08-sep-2026) — evidencias (sección 30 del pedido). Reutiliza el
 * disco de almacenamiento YA usado por ReportUploadController (Storage::disk
 * 'public') — nunca una integración externa nueva. MIME/tamaño validados en
 * StoreEvidenceRequest (backend, nunca solo el <input accept="">).
 */
class EvidenceController extends Controller
{
    use AuthorizesRequests;

    public function store(StoreEvidenceRequest $request, OkrObjective $objective): RedirectResponse
    {
        $file = $request->file('file');
        $path = $file->store('okr-evidences/' . $objective->id, 'public');

        $objective->evidences()->create([
            'okr_key_result_id' => $request->input('okr_key_result_id'),
            'week_number'       => $request->input('week_number', $objective->currentWeekNumber()),
            'original_name'     => $file->getClientOriginalName(),
            'stored_path'       => $path,
            'mime_type'         => $file->getMimeType(),
            'size_bytes'        => $file->getSize(),
            'uploaded_by'       => auth()->id(),
            'comment'           => $request->input('comment'),
        ]);

        return back()->with('success', 'Evidencia cargada.');
    }

    public function download(OkrEvidence $evidence): StreamedResponse
    {
        $this->authorize('okr.view');
        abort_unless(Storage::disk('public')->exists($evidence->stored_path), 404, 'El archivo ya no está disponible.');

        return Storage::disk('public')->download($evidence->stored_path, $evidence->original_name);
    }
}
