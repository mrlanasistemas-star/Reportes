<?php

namespace App\Http\Controllers\Okr\Concerns;

use App\Models\OkrCommitmentLetter;
use App\Models\OkrWarning;
use App\Services\Okr\OkrAuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Visor / descarga / copia firmada de documentos OKR (Fase 5 + 4.8, 19-oct-2026).
 *
 *   ?view=1    → mismo PDF emitido con disposición inline (visor integrado o pestaña nueva).
 *   ?signed=1  → la copia FIRMADA que se subió (inline con view=1).
 *   sin flags  → descarga del PDF emitido.
 *
 * Si falta el archivo se responde con un mensaje ESPECÍFICO (qué documento,
 * qué falta y qué hacer) — HTML legible dentro del visor/pestaña, JSON para
 * fetch — nunca una pantalla de error del framework.
 */
trait ServesOkrDocuments
{
    protected function serveDocument(Request $request, OkrCommitmentLetter|OkrWarning $document, string $label): Response
    {
        $signed = $request->boolean('signed');
        $disk = $signed ? ($document->signed_disk ?: 'local') : ($document->disk ?: 'local');
        $path = $signed ? $document->signed_stored_path : $document->stored_path;

        if ($signed && $path === null) {
            return $this->missingDocument($request, "La {$label} {$document->folio} todavía no tiene copia firmada cargada.", 'Usa "Subir firmada" para cargar el PDF escaneado con las firmas.');
        }
        if (!is_string($path) || $path === '' || !Storage::disk($disk)->exists($path)) {
            return $this->missingDocument($request,
                $signed ? "No encontramos el archivo de la copia firmada de {$document->folio}." : "No encontramos el PDF de {$document->folio} en el almacenamiento.",
                'El registro y su folio se conservan. Proporciona el folio a Sistemas para restaurar el archivo; no vuelvas a emitir el documento.');
        }

        $filename = $document->folio . ($signed ? '-firmada' : '') . '.pdf';

        return $request->boolean('view')
            ? Storage::disk($disk)->response($path, $filename, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'private, no-store'])
            : Storage::disk($disk)->download($path, $filename, ['Content-Type' => 'application/pdf']);
    }

    protected function missingDocument(Request $request, string $message, string $hint): Response
    {
        if ($request->expectsJson()) {
            return new JsonResponse(['message' => "{$message} {$hint}"], 404);
        }

        return response('<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Documento no disponible</title>'
            . '<body style="margin:0;font-family:Montserrat,\'Segoe UI\',Arial,sans-serif;background:#f8fafc;color:#0f172a;display:flex;min-height:100vh;align-items:center;justify-content:center">'
            . '<div style="max-width:520px;margin:24px;padding:24px 28px;border:1px solid #fecaca;border-radius:16px;background:#fff;box-shadow:0 10px 30px rgba(15,23,42,.08)">'
            . '<p style="margin:0 0 6px;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#b91c1c">Documento no disponible</p>'
            . '<p style="margin:0 0 10px;font-size:15px;font-weight:700">' . e($message) . '</p>'
            . '<p style="margin:0;font-size:13px;color:#475569">' . e($hint) . '</p></div></body></html>', 404)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * Copia firmada (4.8): PDF real (≤10 MB). Si ya existe una, sustituirla exige
     * confirmación explícita (`replace=1`) y la anterior queda registrada en la
     * bitácora — nunca se pierde en silencio.
     */
    protected function storeSignedCopy(Request $request, OkrCommitmentLetter|OkrWarning $document, string $directory, string $label): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf'], 'replace' => ['nullable', 'boolean']], [
            'file.required' => 'Selecciona el PDF firmado.',
            'file.mimes' => 'La copia firmada debe ser un archivo PDF.',
            'file.max' => 'El PDF firmado no debe pesar más de 10 MB.',
        ]);

        $file = $request->file('file');
        $head = (string) @file_get_contents($file->getRealPath(), false, null, 0, 4);
        if ($head !== '%PDF') {
            return new JsonResponse(['message' => 'El archivo no es un PDF válido. Escanea el documento firmado y guárdalo como PDF.', 'errors' => ['file' => ['El archivo no es un PDF válido.']]], 422);
        }

        if ($document->signed_stored_path !== null && !$request->boolean('replace')) {
            return new JsonResponse([
                'message' => "La {$label} {$document->folio} ya tiene una copia firmada (cargada el " . $document->signed_uploaded_at?->format('d/m/Y H:i') . '). Confirma si deseas sustituirla.',
                'action' => 'confirm_replace',
            ], 409);
        }

        $disk = 'local';
        $previous = $document->signed_stored_path;
        $path = $file->storeAs($directory, $document->folio . '-firmada-' . now()->format('YmdHis') . '.pdf', $disk);

        $document->update([
            'signed_stored_path' => $path, 'signed_disk' => $disk,
            'signed_uploaded_by' => $request->user()->id, 'signed_uploaded_at' => now(),
        ]);

        app(OkrAuditLogger::class)->log('objective', $document->okr_objective_id, $request->user()->id,
            $previous ? 'signed_copy_replaced' : 'signed_copy_uploaded', 'signed_stored_path', $previous, $path,
            "{$document->folio}: copia firmada " . ($previous ? 'sustituida (la anterior se conserva)' : 'cargada') . " — {$file->getClientOriginalName()}");

        return new JsonResponse(['success' => true, 'folio' => $document->folio, 'replaced' => $previous !== null]);
    }
}
