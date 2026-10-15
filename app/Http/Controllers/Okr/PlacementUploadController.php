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

    /**
     * "Ver desglose" de una semana (15-oct-2026): cuánto colocó cada gestor en
     * el archivo de la sucursal (contado una sola vez — sin movimientos que ya
     * venían en semanas anteriores), qué quedó sin atribución y qué se excluyó.
     */
    public function breakdown(OkrObjective $objective, int $week, \App\Services\Okr\OkrCalendarService $calendar): JsonResponse
    {
        $this->authorize('view', $objective);
        abort_if($week < 1 || $week > (int) $objective->duration_weeks, 404);

        $sourceObjectiveId = $objective->scope_type === OkrObjective::SCOPE_EMPLOYEE && $objective->parent_id
            && !$objective->placementUploads()->where('week_number', $week)->where('status', 'active')->exists()
            ? $objective->parent_id : $objective->id;

        $upload = \App\Models\OkrPlacementUpload::query()->where('okr_objective_id', $sourceObjectiveId)
            ->where('week_number', $week)->where('status', \App\Models\OkrPlacementUpload::STATUS_ACTIVE)->with('uploader:id,name')->first();

        $base = [
            'week_number' => $week,
            'week_start' => $calendar->weekStart($objective->start_date, $week)->toDateString(),
            'week_end' => $calendar->weekEnd($objective->start_date, $week)->toDateString(),
        ];
        if (!$upload) {
            return response()->json($base + ['upload' => null, 'rows' => []]);
        }

        $earlier = \Illuminate\Support\Facades\DB::table('okr_placement_movements as m')
            ->join('okr_placement_uploads as u', 'u.id', '=', 'm.okr_placement_upload_id')
            ->where('u.okr_objective_id', $sourceObjectiveId)->where('u.status', 'active')->where('u.week_number', '<', $week)
            ->pluck('m.fingerprint')->flip();

        $movements = $upload->movements()->get(['employee_id', 'employee_name_raw', 'amount', 'fingerprint']);
        $children = OkrObjective::query()->where('parent_id', $sourceObjectiveId)->whereNotNull('employee_id')->get(['id', 'employee_id']);
        $resolver = app(\App\Services\Okr\OkrEmployeeBranchResolver::class);
        $childByEmployee = [];
        foreach ($children as $child) {
            foreach ($resolver->canonicalIdsFor((int) $child->employee_id) as $id) {
                $childByEmployee[$id] = $child->id;
            }
        }

        $rows = $movements->groupBy(fn ($m) => $m->employee_id ? 'e' . $m->employee_id : 'n' . mb_strtoupper((string) $m->employee_name_raw))
            ->map(function ($group) use ($earlier, $childByEmployee) {
                $first = $group->first();
                $counted = $group->reject(fn ($m) => isset($earlier[$m->fingerprint]));

                return [
                    'employee_id' => $first->employee_id,
                    'name' => $first->employee_id ? (\App\Models\Employee::query()->find($first->employee_id)?->full_name ?? $first->employee_name_raw) : ($first->employee_name_raw ?: 'Sin gestor'),
                    'attributed' => $first->employee_id !== null,
                    'child_objective_id' => $first->employee_id ? ($childByEmployee[$first->employee_id] ?? null) : null,
                    'movements' => $counted->count(),
                    'duplicates' => $group->count() - $counted->count(),
                    'amount' => round((float) $counted->sum('amount'), 2),
                ];
            })
            ->when($objective->scope_type === OkrObjective::SCOPE_EMPLOYEE, fn ($c) => $c->filter(fn ($r) => in_array($r['employee_id'], $resolver->canonicalIdsFor((int) $objective->employee_id), true)))
            ->sortByDesc('amount')->values();

        return response()->json($base + [
            'upload' => [
                'id' => $upload->id, 'original_filename' => $upload->original_filename, 'uploaded_at' => $upload->created_at?->toDateTimeString(),
                'uploaded_by' => $upload->uploader?->name, 'from_branch_file' => $sourceObjectiveId !== $objective->id,
                'total_amount' => $upload->total_amount, 'rows_count' => $upload->rows_count, 'coverage_status' => $upload->coverage_status,
                'unattributed_amount' => $upload->unattributed_amount, 'rows_duplicated' => $upload->rows_duplicated,
                'rows_other_branch' => $upload->rows_other_branch, 'rows_cancelled' => $upload->rows_cancelled,
                'rows_out_of_objective_range' => $upload->rows_out_of_objective_range, 'rows_outside_week_range' => $upload->rows_outside_week_range,
            ],
            'rows' => $rows,
        ]);
    }

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
                $request->boolean('complete_report'),
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

        // Corrección 14-oct-2026: el MISMO archivo alimenta a la sucursal y a
        // cada gestor con OKR individual — antes solo se recalculaba la
        // sucursal y los individuales quedaban desactualizados. Se reprocesan
        // las semanas >= la cargada (sucursal + hijos ACTIVOS); un OKR cerrado
        // nunca se toca.
        $weekNumber = $request->integer('week_number');
        $snapshotService->evaluatePlacementHistory($objective->fresh(), $weekNumber);
        if ($objective->scope_type === OkrObjective::SCOPE_BRANCH) {
            foreach ($objective->children()->where('lifecycle_status', OkrObjective::STATUS_ACTIVE)->get() as $child) {
                $snapshotService->evaluatePlacementHistory($child, $weekNumber);
            }
        }

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
                'rows_duplicated' => $upload->rows_duplicated,
                'rows_other_branch' => $upload->rows_other_branch,
                'rows_cancelled' => $upload->rows_cancelled,
                'rows_out_of_objective_range' => $upload->rows_out_of_objective_range,
                'coverage_status' => $upload->coverage_status,
            ],
        ]);
    }
}
