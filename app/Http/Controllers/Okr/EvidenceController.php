<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Okr\StoreEvidenceRequest;
use App\Models\OkrEvidence;
use App\Models\OkrObjective;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

    /**
     * Sube una evidencia (archivo) a un Objective, opcionalmente ligada a un KR
     * y a una semana.
     *
     * Guarda el archivo en el disco privado `local` (okr-evidences/{id}) y crea
     * la fila en okr_evidences. Si no se indica semana se usa la semana actual
     * del Objective, salvo que aún no inicie (entonces queda sin semana).
     *
     * @param  StoreEvidenceRequest  $request  Archivo, KR, semana y comentario validados.
     * @param  OkrObjective  $objective  OKR que recibe la evidencia.
     * @return RedirectResponse Regresa con mensaje de éxito.
     *
     * @throws AuthorizationException Si no puede subir evidencias a este OKR.
     * @throws HttpException 422 si el OKR está cerrado/cancelado.
     */
    public function store(StoreEvidenceRequest $request, OkrObjective $objective): RedirectResponse
    {
        $this->authorize('uploadEvidence', $objective);
        abort_if($objective->isReadOnly(), 422, 'Este OKR está cerrado/cancelado — no admite nuevas evidencias.');

        $file = $request->file('file');
        $disk = 'local';
        $path = $file->store('okr-evidences/'.$objective->id, $disk);

        // Nunca se asigna semana 0 automáticamente (punto 9 de la auditoría) —
        // si el Objective todavía no inicia, la evidencia queda sin semana
        // hasta que el usuario indique una explícitamente.
        $currentWeek = $objective->currentWeekNumber();
        $objective->evidences()->create([
            'okr_key_result_id' => $request->input('okr_key_result_id'),
            'week_number' => $request->input('week_number') ?? ($currentWeek >= 1 ? $currentWeek : null),
            'original_name' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'disk' => $disk,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_by' => auth()->id(),
            'comment' => $request->input('comment'),
        ]);

        return back()->with('success', 'Evidencia cargada.');
    }

    /**
     * Descarga una evidencia con su nombre original, previa autorización de
     * `view` sobre su Objective. Respeta el disco guardado en la fila (las
     * evidencias legadas sin disco se leen de `public`).
     *
     * @param  OkrEvidence  $evidence  Evidencia a descargar.
     * @return StreamedResponse Descarga del archivo.
     *
     * @throws AuthorizationException Si no puede ver el OKR.
     * @throws HttpException 404 si el archivo ya no existe.
     */
    public function download(OkrEvidence $evidence): StreamedResponse
    {
        $this->authorize('view', $evidence->objective);

        $disk = $evidence->disk ?: 'public'; // compat legado — evidencias de antes de esta corrección
        abort_unless(Storage::disk($disk)->exists($evidence->stored_path), 404, 'El archivo ya no está disponible.');

        return Storage::disk($disk)->download($evidence->stored_path, $evidence->original_name);
    }
}
