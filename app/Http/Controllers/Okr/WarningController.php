<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Models\OkrObjective;
use App\Models\OkrWarning;
use App\Services\Okr\OkrWarningService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Parte 3/10/11 del cierre (04-oct-2026) — Warning Rojo: el sistema NUNCA
 * sanciona solo (3.1) — esta es la acción EXPLÍCITA de admin/gerencial que
 * el botón "Generar Warning" dispara. Basado en el snapshot de la semana
 * evaluada (ver OkrWarningService), nunca en el dato de hoy.
 */
class WarningController extends Controller
{
    use AuthorizesRequests;

    public function store(Request $request, OkrObjective $objective, OkrWarningService $service): JsonResponse
    {
        $this->authorize('assign', $objective); // 3.1: emitir un Warning es decisión admin/gerencial, igual que asignar

        $data = $request->validate([
            'week_number' => ['required', 'integer', 'min:1', 'max:' . $objective->duration_weeks],
            'corrective_actions' => ['required', 'string', 'min:5', 'max:2000'],
            'observations' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $warning = $service->generate($objective, $data['week_number'], $data['corrective_actions'], $data['observations'] ?? null, $request->user());
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'warning' => [
                'id' => $warning->id, 'folio' => $warning->folio, 'week_number' => $warning->week_number,
                'generated_at' => $warning->generated_at->toDateTimeString(),
                'is_signed' => $warning->isSigned(),
                'download_url' => route('okr.warnings.download', $warning),
            ],
        ]);
    }

    public function download(OkrWarning $warning): StreamedResponse
    {
        $this->authorize('view', $warning->objective);
        abort_unless(Storage::disk($warning->disk)->exists($warning->stored_path), 404, 'El documento ya no está disponible.');

        return Storage::disk($warning->disk)->download($warning->stored_path, "{$warning->folio}.pdf");
    }

    public function uploadSigned(Request $request, OkrWarning $warning): JsonResponse
    {
        $this->authorize('update', $warning->objective);
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf']]);

        $disk = 'local';
        $path = $request->file('file')->store('okr-warnings/' . $warning->okr_objective_id . '/firmados', $disk);

        $warning->update([
            'signed_stored_path' => $path, 'signed_disk' => $disk,
            'signed_uploaded_by' => $request->user()->id, 'signed_uploaded_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }
}
