<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Okr\StorePlacementUploadRequest;
use App\Models\OkrObjective;
use App\Services\Okr\OkrPlacementImportService;
use App\Services\Okr\OkrPlacementUploadConflictException;
use App\Services\Okr\OkrSnapshotService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

/**
 * Parte 6/7 del cierre (04-oct-2026) — carga semanal de colocación por
 * Objective. Cada semana se preserva como su propia fila (6.1); un segundo
 * intento sobre la MISMA semana exige `confirm_replace=true` explícito
 * (6.7) — nunca duplica silenciosamente.
 *
 * Responde JSON (no Inertia) — el mismo patrón que employees-lookup/
 * baseline-preview (ver ObjectiveController): este repo no comparte flash
 * de sesión a Inertia globalmente, y el flujo "ya existe, ¿reemplazar?"
 * necesita una respuesta estructurada que el frontend pueda decidir sin
 * dar una vuelta completa de redirect.
 */
class PlacementUploadController extends Controller
{
    use AuthorizesRequests;

    public function store(StorePlacementUploadRequest $request, OkrObjective $objective, OkrPlacementImportService $importer, OkrSnapshotService $snapshotService): JsonResponse
    {
        if ($objective->isReadOnly()) {
            return response()->json(['message' => 'Este OKR está cerrado/cancelado — no admite nuevas cargas de colocación.'], 422);
        }

        try {
            $upload = $importer->import(
                $objective,
                $request->integer('week_number'),
                $request->file('file'),
                $request->user(),
                $request->boolean('confirm_replace'),
            );
        } catch (OkrPlacementUploadConflictException $e) {
            return response()->json([
                'conflict' => true,
                'week_number' => $request->integer('week_number'),
                'existing_upload' => [
                    'id' => $e->existingUpload->id,
                    'original_filename' => $e->existingUpload->original_filename,
                    'total_amount' => $e->existingUpload->total_amount,
                    'uploaded_at' => $e->existingUpload->created_at->toDateTimeString(),
                ],
            ], 409);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Recalcula de inmediato — la UI debe reflejar la colocación recién
        // cargada sin esperar al scheduler (okr:refresh).
        $snapshotService->evaluateObjective($objective->fresh());

        return response()->json([
            'success' => true,
            'replaced' => $upload->replaced_upload_id !== null,
            'upload' => [
                'week_number' => $upload->week_number,
                'total_amount' => $upload->total_amount,
                'original_filename' => $upload->original_filename,
                'rows_count' => $upload->rows_count,
                'unattributed_amount' => $upload->unattributed_amount,
                'rows_outside_week_range' => $upload->rows_outside_week_range,
            ],
        ]);
    }
}
