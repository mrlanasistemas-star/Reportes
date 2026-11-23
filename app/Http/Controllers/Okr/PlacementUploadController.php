<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Http\Requests\Okr\StorePlacementUploadRequest;
use App\Models\Employee;
use App\Models\OkrObjective;
use App\Models\OkrPlacementUpload;
use App\Services\Okr\OkrCalendarService;
use App\Services\Okr\OkrEmployeeBranchResolver;
use App\Services\Okr\OkrPlacementImportService;
use App\Services\Okr\OkrPlacementUploadConflictException;
use App\Services\Okr\OkrSnapshotService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
     *
     * Para un OKR individual sin carga propia en esa semana se usa la carga del
     * OKR padre (sucursal) y se filtra a los movimientos del gestor. Los
     * movimientos cuyo `fingerprint` ya apareció en una semana anterior se
     * cuentan como duplicados. No escribe en BD.
     *
     * @param  OkrObjective  $objective  OKR consultado.
     * @param  int  $week  Número de semana (1..duration_weeks).
     * @param  OkrCalendarService  $calendar  Calcula inicio/fin de la semana.
     * @return JsonResponse Semana, metadatos de la carga activa (o null) y filas por gestor.
     *
     * @throws AuthorizationException Si no puede ver el OKR.
     * @throws HttpException 404 si la semana está fuera de rango.
     */
    public function breakdown(OkrObjective $objective, int $week, OkrCalendarService $calendar): JsonResponse
    {
        $this->authorize('view', $objective);
        abort_if($week < 1 || $week > (int) $objective->duration_weeks, 404);

        $sourceObjectiveId = $objective->scope_type === OkrObjective::SCOPE_EMPLOYEE && $objective->parent_id
            && ! $objective->placementUploads()->where('week_number', $week)->where('status', 'active')->exists()
            ? $objective->parent_id : $objective->id;

        $upload = OkrPlacementUpload::query()->where('okr_objective_id', $sourceObjectiveId)
            ->where('week_number', $week)->where('status', OkrPlacementUpload::STATUS_ACTIVE)->with('uploader:id,name')->first();

        $base = [
            'week_number' => $week,
            'week_start' => $calendar->weekStart($objective->start_date, $week)->toDateString(),
            'week_end' => $calendar->weekEnd($objective->start_date, $week)->toDateString(),
        ];
        if (! $upload) {
            return response()->json($base + ['upload' => null, 'rows' => []]);
        }

        $earlier = DB::table('okr_placement_movements as m')
            ->join('okr_placement_uploads as u', 'u.id', '=', 'm.okr_placement_upload_id')
            ->where('u.okr_objective_id', $sourceObjectiveId)->where('u.status', 'active')->where('u.week_number', '<', $week)
            ->pluck('m.fingerprint')->flip();

        $movements = $upload->movements()->get(['employee_id', 'employee_name_raw', 'amount', 'fingerprint', 'attribution_status', 'attribution_note']);
        $children = OkrObjective::query()->where('parent_id', $sourceObjectiveId)->whereNotNull('employee_id')->get(['id', 'employee_id']);
        $resolver = app(OkrEmployeeBranchResolver::class);
        $childByEmployee = [];
        foreach ($children as $child) {
            foreach ($resolver->canonicalIdsFor((int) $child->employee_id) as $id) {
                $childByEmployee[$id] = $child->id;
            }
        }

        $rows = $movements->groupBy(fn ($m) => $m->employee_id ? 'e'.$m->employee_id : 'n'.mb_strtoupper((string) $m->employee_name_raw))
            ->map(function ($group) use ($earlier, $childByEmployee) {
                $first = $group->first();
                $counted = $group->reject(fn ($m) => isset($earlier[$m->fingerprint]));

                return [
                    'employee_id' => $first->employee_id,
                    'name' => $first->employee_id ? (Employee::query()->find($first->employee_id)?->full_name ?? $first->employee_name_raw) : ($first->employee_name_raw ?: 'Sin gestor'),
                    'attributed' => $first->employee_id !== null,
                    // Prioridad 4: pendiente de atribución (no suma a ningún gestor) con su motivo.
                    'pending_attribution' => $first->employee_id === null,
                    'pending_reason' => $first->employee_id === null ? ($group->pluck('attribution_note')->filter()->first() ?? 'Sin gestor identificado.') : null,
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
                'total_amount' => $upload->total_amount, 'rows_count' => $upload->rows_count, 'coverage_status' => $upload->coverage_status, 'declared_complete' => $upload->declared_complete,
                'unattributed_amount' => $upload->unattributed_amount, 'rows_pending_attribution' => $upload->rows_pending_attribution ?? 0, 'rows_duplicated' => $upload->rows_duplicated,
                'rows_other_branch' => $upload->rows_other_branch, 'rows_cancelled' => $upload->rows_cancelled,
                'rows_out_of_objective_range' => $upload->rows_out_of_objective_range, 'rows_outside_week_range' => $upload->rows_outside_week_range,
            ],
            'rows' => $rows,
        ]);
    }

    /**
     * Importa el archivo de colocación de una semana para un OKR.
     *
     * OkrPlacementImportService::import() guarda la carga y sus movimientos
     * (okr_placement_uploads / okr_placement_movements). Después recalcula el
     * OKR y su historial de colocación desde esa semana y, si es de sucursal,
     * el de cada OKR individual hijo ACTIVO.
     *
     * Respuestas: 409 con `existing_upload` si ya hay carga en esa semana y no
     * se confirmó la sustitución; 422 si el OKR es de solo lectura o el archivo
     * no es válido; 200 con el resumen de la carga.
     *
     * @param  StorePlacementUploadRequest  $request  Archivo, semana y banderas validadas.
     * @param  OkrObjective  $objective  OKR que recibe la carga.
     * @param  OkrPlacementImportService  $importer  Importador de colocación.
     * @param  OkrSnapshotService  $snapshotService  Recalcula KR y snapshots.
     * @return JsonResponse Resultado de la carga.
     */
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
                'rows_pending_attribution' => $upload->rows_pending_attribution ?? 0,
                'rows_outside_week_range' => $upload->rows_outside_week_range,
                'rows_duplicated' => $upload->rows_duplicated,
                'rows_other_branch' => $upload->rows_other_branch,
                'rows_cancelled' => $upload->rows_cancelled,
                'rows_out_of_objective_range' => $upload->rows_out_of_objective_range,
                'coverage_status' => $upload->coverage_status,
                'declared_complete' => $upload->declared_complete,
            ],
        ]);
    }
}
