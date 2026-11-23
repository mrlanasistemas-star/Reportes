<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Models\OkrObjective;
use App\Models\OkrWarning;
use App\Services\Okr\OkrWarningService;
use App\Services\Pdf\PdfRenderException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Warning Rojo — el sistema NUNCA sanciona solo: muestra la elegibilidad
 * REAL de una semana (eligibility) y un usuario admin/gerencial decide
 * emitirlo (store). Se emite a la persona evaluada por ESTE OKR, sobre una
 * semana cerrada con dato real e incumplimiento contra lo esperado.
 */
class WarningController extends Controller
{
    use AuthorizesRequests;
    use Concerns\ServesOkrDocuments;

    /**
     * Elegibilidad de cada semana transcurrida (o de una sola con ?week=).
     *
     * Si el OKR está en borrador, cancelado o aún no inicia, responde 200 con
     * `notice` explicativo y sin semanas. Si no, evalúa cada semana con
     * OkrWarningService::eligibility() e indica si el usuario puede emitir
     * (`assign`). Un fallo técnico responde 500 con referencia `WR-ELIG-...`.
     * No escribe en BD.
     *
     * @param  Request  $request  `week` opcional.
     * @param  OkrObjective  $objective  OKR evaluado.
     * @param  OkrWarningService  $service  Calcula la elegibilidad por semana.
     * @return JsonResponse `{ weeks, notice, can_issue, authorization_notice }`.
     *
     * @throws AuthorizationException Si no puede ver el OKR.
     */
    public function eligibility(Request $request, OkrObjective $objective, OkrWarningService $service): JsonResponse
    {
        $this->authorize('view', $objective);

        // 16-oct-2026: una situación NO elegible abre y se explica; solo un fallo
        // técnico real responde como error (con referencia para soporte).
        $notice = match ($objective->lifecycle_status) {
            OkrObjective::STATUS_DRAFT => ['code' => 'not_active', 'message' => 'Este OKR todavía no está activado: los Warnings solo se evalúan sobre OKR activos con línea base congelada.'],
            OkrObjective::STATUS_CANCELLED => ['code' => 'cancelled', 'message' => 'Este OKR está cancelado — no se evalúan incumplimientos.'],
            default => ! $objective->hasStarted()
                ? ['code' => 'not_started', 'message' => 'El OKR aún no inicia ('.$objective->start_date->format('d/m/Y').') — no hay semanas que evaluar.']
                : null,
        };

        if ($notice !== null) {
            return response()->json(['weeks' => [], 'notice' => $notice], 200, ['Cache-Control' => 'no-store']);
        }

        $weeks = $request->filled('week')
            ? [$request->integer('week')]
            : range(1, max(1, min((int) $objective->duration_weeks, max(1, $objective->currentWeekNumber()))));

        // Bug real corregido (15-oct-2026): el catch decía `Throwable` sin
        // `use Throwable;` — dentro de este namespace se resolvía como
        // App\Http\Controllers\Okr\Throwable (inexistente), así que NUNCA
        // atrapaba nada y el usuario veía un 500 genérico sin referencia.
        try {
            $rows = array_map(fn ($w) => $service->eligibility($objective, $w), $weeks);
        } catch (Throwable $e) {
            return $this->technicalError('WR-ELIG', $e, $objective->id);
        }

        // "Falta autorización": quien solo puede consultar ve la evaluación,
        // pero se le dice por qué no puede emitir (no es un error).
        $canIssue = $request->user()?->can('assign', $objective) ?? false;

        return response()->json([
            'weeks' => $rows,
            'notice' => null,
            'can_issue' => $canIssue,
            'authorization_notice' => $canIssue ? null : 'Puedes consultar la evaluación, pero emitir un Warning requiere permiso de administrador o gerencia.',
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Fallo técnico REAL (no una condición de negocio): mensaje acordado con
     * el usuario + referencia única registrada en el log para que Sistemas
     * encuentre el detalle exacto.
     *
     * @param  string  $prefix  Prefijo de la referencia (WR-ELIG, WR-PDF, WR-EMIT).
     * @param  Throwable  $e  Excepción original (se registra en log con archivo:línea).
     * @param  int  $objectiveId  OKR afectado.
     * @param  string  $what  Acción fallida, para el mensaje al usuario.
     * @return JsonResponse 500 con `message` y `reference`.
     */
    private function technicalError(string $prefix, Throwable $e, int $objectiveId, string $what = 'consultar la información'): JsonResponse
    {
        $ref = $prefix.'-'.now()->format('ymdHis').'-'.strtoupper(Str::random(4));
        Log::error('OKR Warning: fallo técnico', [
            'ref' => $ref, 'objective_id' => $objectiveId,
            'exception' => get_class($e), 'error' => $e->getMessage(),
            'at' => $e->getFile().':'.$e->getLine(),
        ]);

        return response()->json([
            'message' => "No pudimos {$what}. Intenta nuevamente. Si continúa, proporciona esta referencia a Sistemas: {$ref}.",
            'reference' => $ref,
        ], 500);
    }

    /**
     * Emite un Warning Rojo para una semana, con acciones correctivas y
     * confirmación explícita (`authorized`). OkrWarningService::generate()
     * crea el folio, el PDF y la fila en okr_warnings.
     *
     * Errores: 500 con referencia si falla el PDF u otra excepción técnica; 422
     * si una regla de negocio lo impide (RuntimeException, p. ej. semana no elegible).
     *
     * @param  Request  $request  `week_number`, `corrective_actions`, `observations`, `authorized`.
     * @param  OkrObjective  $objective  OKR al que se emite.
     * @param  OkrWarningService  $service  Servicio de Warnings.
     * @return JsonResponse `{ warning }` con el payload emitido.
     *
     * @throws AuthorizationException Si no tiene `assign` sobre el OKR.
     * @throws ValidationException Si los datos no son válidos.
     */
    public function store(Request $request, OkrObjective $objective, OkrWarningService $service): JsonResponse
    {
        $this->authorize('assign', $objective); // emitir un Warning es decisión admin/gerencial, igual que asignar

        $data = $request->validate([
            'week_number' => ['required', 'integer', 'min:1', 'max:'.$objective->duration_weeks],
            'corrective_actions' => ['required', 'string', 'min:5', 'max:2000'],
            'observations' => ['nullable', 'string', 'max:2000'],
            'authorized' => ['accepted'],
        ], ['authorized.accepted' => 'Confirma que revisaste los datos y autorizas la emisión del Warning.']);

        try {
            $warning = $service->generate($objective, $data['week_number'], $data['corrective_actions'], $data['observations'] ?? null, $request->user());
        } catch (PdfRenderException $e) {
            return $this->technicalError('WR-PDF', $e, $objective->id, 'generar el documento del Warning (no se emitió ningún folio)');
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return $this->technicalError('WR-EMIT', $e, $objective->id, 'emitir el Warning (no se emitió ningún folio)');
        }

        return response()->json(['warning' => $this->payload($warning)]);
    }

    /**
     * Anula un Warning con motivo (10–500 caracteres); folio y PDF se conservan.
     *
     * @param  Request  $request  Campo `reason`.
     * @param  OkrWarning  $warning  Warning a anular.
     * @param  OkrWarningService  $service  Marca la anulación en BD.
     * @return JsonResponse `{ warning }` actualizado o 422 con el mensaje de negocio.
     *
     * @throws AuthorizationException Si no tiene `assign` sobre el OKR.
     */
    public function void(Request $request, OkrWarning $warning, OkrWarningService $service): JsonResponse
    {
        $this->authorize('assign', $warning->objective);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        try {
            $warning = $service->void($warning, $request->user(), $data['reason']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['warning' => $this->payload($warning)]);
    }

    /**
     * ?view=1 → inline; ?signed=1 → copia firmada; faltante → mensaje específico.
     *
     * @param  Request  $request  Flags `view` y `signed`.
     * @param  OkrWarning  $warning  Warning a entregar.
     * @return Response PDF o 404 explicativo.
     *
     * @throws AuthorizationException Si no puede ver el OKR.
     */
    public function download(Request $request, OkrWarning $warning): Response
    {
        $this->authorize('view', $warning->objective);

        return $this->serveDocument($request, $warning, 'Warning');
    }

    /**
     * Sube la copia firmada de un Warning no anulado (ver
     * ServesOkrDocuments::storeSignedCopy()).
     *
     * @param  Request  $request  Archivo `file` y flag `replace`.
     * @param  OkrWarning  $warning  Warning vigente.
     * @return JsonResponse Resultado de la carga, 409 si requiere confirmar o 422 si está anulado.
     *
     * @throws AuthorizationException Si no tiene `update` sobre el OKR.
     */
    public function uploadSigned(Request $request, OkrWarning $warning): JsonResponse
    {
        $this->authorize('update', $warning->objective);
        if ($warning->isVoided()) {
            return response()->json(['message' => "El Warning {$warning->folio} está anulado: no admite copia firmada."], 422);
        }

        return $this->storeSignedCopy($request, $warning, 'okr-warnings/'.$warning->okr_objective_id.'/firmados', 'Warning');
    }

    /**
     * Arreglo que la UI usa para listar un Warning: folio, semana, estado,
     * colaborador (del snapshot), emisión, firma, anulación y URLs.
     *
     * @param  OkrWarning  $warning  Warning a serializar.
     * @return array<string, mixed>
     */
    private function payload(OkrWarning $warning): array
    {
        return [
            'id' => $warning->id, 'folio' => $warning->folio, 'week_number' => $warning->week_number, 'status' => $warning->status,
            'collaborator' => $warning->snapshot['collaborator_name'] ?? $warning->snapshot['employee_name'] ?? null,
            'generated_at' => $warning->generated_at->toDateTimeString(),
            'is_signed' => $warning->isSigned(),
            'file_available' => $warning->hasStoredFile(),
            'voided_at' => $warning->voided_at?->toDateTimeString(), 'void_reason' => $warning->void_reason,
            'download_url' => route('okr.warnings.download', $warning),
            'view_url' => route('okr.warnings.download', $warning).'?view=1',
            'signed_view_url' => $warning->isSigned() ? route('okr.warnings.download', $warning).'?view=1&signed=1' : null,
        ];
    }
}
