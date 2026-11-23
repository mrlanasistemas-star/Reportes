<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OkrCommitmentLetter;
use App\Models\OkrObjective;
use App\Services\Okr\OkrAuditLogger;
use App\Services\Okr\OkrCommitmentLetterIncompleteException;
use App\Services\Okr\OkrCommitmentLetterService;
use App\Services\Okr\OkrEmployeeBranchResolver;
use App\Services\Pdf\PdfRenderException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Carta Compromiso: revisión previa, vista previa (nunca se guarda), emisión
 * (una vigente por OKR, snapshot inmutable), anulación auditada y copia
 * firmada. 15-oct-2026: el colaborador de la carta es la persona EVALUADA
 * (gestor del OKR individual o titular del OKR de sucursal), nunca el usuario
 * que creó el OKR ni el responsable administrativo.
 */
class CommitmentLetterController extends Controller
{
    use AuthorizesRequests;
    use Concerns\ServesOkrDocuments;

    /**
     * Revisión previa de la Carta Compromiso (JSON, sin caché): snapshot que se
     * imprimiría con los overrides recibidos, campos faltantes, contexto de
     * revisión, si puede emitirse ya (OKR activado, sin carta vigente, datos
     * completos y habilidad `assign`) y el historial de cartas del OKR.
     *
     * No escribe en BD.
     *
     * @param  Request  $request  Overrides opcionales (puesto, jefe inmediato, observaciones).
     * @param  OkrObjective  $objective  OKR evaluado.
     * @param  OkrCommitmentLetterService  $service  Arma snapshot y validaciones de la carta.
     * @return JsonResponse Datos de revisión.
     *
     * @throws AuthorizationException Si no puede ver el OKR.
     */
    public function review(Request $request, OkrObjective $objective, OkrCommitmentLetterService $service): JsonResponse
    {
        $this->authorize('view', $objective);

        $snapshot = $service->buildSnapshot($objective, config('company.document_place'), $this->authorizedOverrides($request));
        $missing = $service->missingFields($objective, $snapshot);

        return response()->json([
            'snapshot' => $snapshot,
            'missing' => $missing,
            'context' => $service->reviewContext($objective),
            'is_activated' => $objective->activated_at !== null,
            'has_current_letter' => $objective->commitmentLetter !== null,
            'can_generate' => $missing === [] && $objective->activated_at !== null && $objective->commitmentLetter === null
                && ($request->user()?->can('assign', $objective) ?? false),
            'letters' => $objective->commitmentLetters()->with(['voidedByUser:id,name', 'generatedByUser:id,name'])->latest('id')->get()->map(fn ($l) => $this->letterPayload($l)),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    /**
     * Vista previa en PDF de la carta (nunca se guarda ni genera folio).
     *
     * Renderiza un PDF temporal, lo transmite (inline o attachment con
     * `?download=1`) y borra el temporal al terminar. Si el motor PDF falla,
     * registra el error en log con una referencia `CC-PREV-...` y responde una
     * página HTML 500 legible.
     *
     * @param  Request  $request  Overrides y flag `download`.
     * @param  OkrObjective  $objective  OKR evaluado.
     * @param  OkrCommitmentLetterService  $service  Renderiza la vista previa.
     * @return StreamedResponse|Response PDF o HTML de error.
     *
     * @throws AuthorizationException Si no puede ver el OKR.
     */
    public function preview(Request $request, OkrObjective $objective, OkrCommitmentLetterService $service): StreamedResponse|Response
    {
        $this->authorize('view', $objective);

        try {
            $path = $service->renderPreviewPdf($objective, config('company.document_place'), $this->authorizedOverrides($request));
        } catch (PdfRenderException $e) {
            // La vista previa se abre en un iframe / pestaña: respuesta HTML
            // legible (no una pantalla de error del framework) con referencia.
            $ref = 'CC-PREV-'.now()->format('ymdHis').'-'.strtoupper(Str::random(4));
            Log::error('Carta Compromiso: fallo generando la vista previa', ['ref' => $ref, 'objective_id' => $objective->id, 'code' => $e->code(), 'error' => $e->getMessage()]);

            return response('<!doctype html><meta charset="utf-8"><body style="font-family:Montserrat,Segoe UI,Arial,sans-serif;padding:24px;color:#7f1d1d;background:#fef2f2">'
                .'<p><strong>No pudimos generar la vista previa.</strong></p><p>Intenta nuevamente. Si continúa, proporciona esta referencia a Sistemas: <strong>'.e($ref).'</strong>.</p></body>', 500)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        // Previsualización en el sistema y "abrir en otra pestaña" (15-oct-2026,
        // sección 6 de la auditoría) necesitan Content-Disposition: inline para
        // que el navegador renderice el PDF en vez de forzar la descarga; el
        // botón "Descargar PDF" pide explícitamente ?download=1. OJO:
        // streamDownload() SIEMPRE sobreescribe el header Content-Disposition
        // con su propio 4º argumento — pasarlo como header no tiene efecto.
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return response()->streamDownload(function () use ($path) {
            echo File::get($path);
            @unlink($path);
        }, 'carta-compromiso-vista-previa.pdf', ['Content-Type' => 'application/pdf'], $disposition);
    }

    /**
     * Emite la Carta Compromiso OFICIAL (folio + PDF + snapshot inmutable) vía
     * OkrCommitmentLetterService::generate(), que escribe en BD y almacenamiento.
     *
     * Errores: 422 con `action=complete_information` si faltan datos; 500 con
     * referencia `CC-PDF-...` (y log) si falla el motor PDF (sin folio emitido);
     * 422 para otras reglas de negocio (RuntimeException).
     *
     * @param  Request  $request  Overrides autorizados.
     * @param  OkrObjective  $objective  OKR al que se emite la carta.
     * @param  OkrCommitmentLetterService  $service  Servicio de emisión.
     * @return JsonResponse `{ letter }` con el payload de la carta emitida.
     *
     * @throws AuthorizationException Si no tiene `assign` sobre el OKR.
     */
    public function generate(Request $request, OkrObjective $objective, OkrCommitmentLetterService $service): JsonResponse
    {
        // Emisión OFICIAL = decisión admin/gerencial (mismo criterio que asignar).
        $this->authorize('assign', $objective);

        try {
            $letter = $service->generate($objective, $request->user(), config('company.document_place'), $this->authorizedOverrides($request));
        } catch (OkrCommitmentLetterIncompleteException $e) {
            // Datos faltantes (p. ej. jefe inmediato sin confirmar): la UI
            // ofrece "Completar información" y resalta los campos.
            return response()->json(['message' => $e->getMessage(), 'action' => 'complete_information', 'action_label' => 'Completar información', 'missing' => $e->missing], 422);
        } catch (PdfRenderException $e) {
            // Fallo técnico real del motor PDF: la transacción ya se revirtió
            // (sin folio ni documento definitivo); referencia para Sistemas.
            $ref = 'CC-PDF-'.now()->format('ymdHis').'-'.strtoupper(Str::random(4));
            Log::error('Carta Compromiso: fallo generando el PDF definitivo', ['ref' => $ref, 'objective_id' => $objective->id, 'code' => $e->code(), 'error' => $e->getMessage()]);

            return response()->json(['message' => "No pudimos generar el documento (no se emitió ningún folio). Intenta nuevamente. Si continúa, proporciona esta referencia a Sistemas: {$ref}.", 'reference' => $ref], 500);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['letter' => $this->letterPayload($letter)]);
    }

    /**
     * Anulación auditada — el PDF y el folio originales se conservan.
     *
     * Exige motivo (10–500 caracteres) y delega en
     * OkrCommitmentLetterService::void(), que marca la carta como anulada en BD.
     *
     * @param  Request  $request  Campo `reason`.
     * @param  OkrCommitmentLetter  $letter  Carta a anular.
     * @param  OkrCommitmentLetterService  $service  Servicio de cartas.
     * @return JsonResponse `{ letter }` actualizado o 422 con el mensaje de negocio.
     *
     * @throws AuthorizationException Si no tiene `assign` sobre el OKR.
     * @throws ValidationException Si falta el motivo.
     */
    public function void(Request $request, OkrCommitmentLetter $letter, OkrCommitmentLetterService $service): JsonResponse
    {
        $this->authorize('assign', $letter->objective);
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        try {
            $letter = $service->void($letter, $request->user(), $data['reason']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['letter' => $this->letterPayload($letter)]);
    }

    /**
     * Titular de un OKR de SUCURSAL — la persona que acepta la responsabilidad
     * general (p. ej. el gerente). Debe ser colaborador vigente de esa sucursal.
     *
     * Solo para OKR de sucursal sin carta vigente. Actualiza
     * `accountable_employee_id` en okr_objectives y registra el cambio (nombres
     * anterior/nuevo y motivo) en la bitácora OKR.
     *
     * @param  Request  $request  Campos `employee_id` y `reason` (opcional).
     * @param  OkrObjective  $objective  OKR de sucursal.
     * @param  OkrEmployeeBranchResolver  $resolver  Verifica vigencia y pertenencia a la sucursal.
     * @param  OkrAuditLogger  $logger  Bitácora OKR.
     * @return JsonResponse `{ success: true }` o 422 si el colaborador no es válido.
     *
     * @throws AuthorizationException Si no tiene `assign` sobre el OKR.
     * @throws HttpException 422 si no es OKR de sucursal o ya hay carta vigente.
     */
    public function assignAccountable(Request $request, OkrObjective $objective, OkrEmployeeBranchResolver $resolver, OkrAuditLogger $logger): JsonResponse
    {
        $this->authorize('assign', $objective);
        abort_if($objective->scope_type !== OkrObjective::SCOPE_BRANCH, 422, 'Solo un OKR de sucursal tiene titular; un OKR individual ya pertenece a su gestor.');
        abort_if($objective->commitmentLetter !== null, 422, 'Ya existe una carta vigente para este OKR — anúlala antes de cambiar al titular.');

        $data = $request->validate(['employee_id' => ['required', 'integer', 'exists:employees,id'], 'reason' => ['nullable', 'string', 'max:500']]);
        $employeeId = (int) $data['employee_id'];

        if (! $resolver->employeeIsActive($employeeId) || ! $resolver->employeeBelongsToBranch($employeeId, (int) $objective->branch_id)) {
            return response()->json(['message' => 'El titular debe ser un colaborador vigente de la sucursal del OKR.'], 422);
        }

        $old = $objective->accountable_employee_id;
        $objective->update(['accountable_employee_id' => $employeeId]);
        $logger->logFieldChange('objective', $objective->id, $request->user()->id, 'accountable_employee_id',
            $old ? Employee::query()->find($old)?->full_name : null, Employee::query()->find($employeeId)?->full_name, $data['reason'] ?? 'Titular del OKR de sucursal asignado en la revisión de la Carta Compromiso.');

        return response()->json(['success' => true]);
    }

    /**
     * ?view=1 → inline (visor integrado / pestaña); ?signed=1 → copia firmada.
     * Archivo faltante → mensaje específico (ver ServesOkrDocuments).
     *
     * @param  Request  $request  Flags `view` y `signed`.
     * @param  OkrCommitmentLetter  $letter  Carta a entregar.
     * @return \Symfony\Component\HttpFoundation\Response PDF o 404 explicativo.
     *
     * @throws AuthorizationException Si no puede ver el OKR.
     */
    public function download(Request $request, OkrCommitmentLetter $letter): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('view', $letter->objective);

        return $this->serveDocument($request, $letter, 'Carta Compromiso');
    }

    /**
     * Sube la copia firmada (PDF escaneado) de una carta no anulada; la lógica
     * de guardado, confirmación de sustitución y bitácora está en
     * ServesOkrDocuments::storeSignedCopy().
     *
     * @param  Request  $request  Archivo `file` y flag `replace`.
     * @param  OkrCommitmentLetter  $letter  Carta vigente.
     * @return JsonResponse Resultado de la carga, 409 si requiere confirmar o 422 si la carta está anulada.
     *
     * @throws AuthorizationException Si no tiene `update` sobre el OKR.
     */
    public function uploadSigned(Request $request, OkrCommitmentLetter $letter): JsonResponse
    {
        $this->authorize('update', $letter->objective);
        if ($letter->isVoided()) {
            return response()->json(['message' => "La carta {$letter->folio} está anulada: no admite copia firmada. Sube la firma a la carta vigente."], 422);
        }

        return $this->storeSignedCopy($request, $letter, 'okr-commitment-letters/'.$letter->okr_objective_id.'/firmadas', 'Carta Compromiso');
    }

    /**
     * Únicos campos capturables por el emisor; todo lo demás sale del OKR y del
     * directorio laboral.
     *
     * @param  Request  $request  Petición con `position`, `immediate_boss` y `observations` opcionales.
     * @return array<string, string|null> Solo las claves permitidas que vinieron.
     *
     * @throws ValidationException Si exceden la longitud máxima.
     */
    private function authorizedOverrides(Request $request): array
    {
        $data = $request->validate([
            'position' => ['nullable', 'string', 'max:120'],
            'immediate_boss' => ['nullable', 'string', 'max:160'],
            'observations' => ['nullable', 'string', 'max:600'],
        ]);

        return array_intersect_key($data, array_flip(['position', 'immediate_boss', 'observations']));
    }

    /**
     * Arreglo que la UI usa para listar una carta: folio, estado, colaborador
     * (del snapshot), emisión, firma, anulación y URLs de descarga/visor.
     *
     * @param  OkrCommitmentLetter  $letter  Carta a serializar.
     * @return array<string, mixed>
     */
    private function letterPayload(OkrCommitmentLetter $letter): array
    {
        return [
            'id' => $letter->id, 'folio' => $letter->folio, 'status' => $letter->status,
            'collaborator' => $letter->snapshot['collaborator_name'] ?? $letter->snapshot['employee_name'] ?? null,
            'generated_at' => $letter->generated_at->toDateTimeString(),
            'generated_by' => $letter->generatedByUser?->name,
            'is_signed' => $letter->isSigned(),
            'file_available' => $letter->hasStoredFile(),
            'signed_uploaded_at' => $letter->signed_uploaded_at?->toDateTimeString(),
            'voided_at' => $letter->voided_at?->toDateTimeString(), 'void_reason' => $letter->void_reason, 'voided_by' => $letter->voidedByUser?->name,
            'download_url' => route('okr.commitment-letters.download', $letter),
            'view_url' => route('okr.commitment-letters.download', $letter).'?view=1',
            'signed_view_url' => $letter->isSigned() ? route('okr.commitment-letters.download', $letter).'?view=1&signed=1' : null,
        ];
    }
}
