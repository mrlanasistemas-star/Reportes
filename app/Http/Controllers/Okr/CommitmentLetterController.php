<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OkrCommitmentLetter;
use App\Models\OkrObjective;
use App\Services\Okr\OkrAuditLogger;
use App\Services\Okr\OkrCommitmentLetterService;
use App\Services\Okr\OkrCommitmentLetterIncompleteException;
use App\Services\Okr\OkrEmployeeBranchResolver;
use App\Services\Pdf\PdfRenderException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    public function preview(Request $request, OkrObjective $objective, OkrCommitmentLetterService $service): StreamedResponse|\Illuminate\Http\Response
    {
        $this->authorize('view', $objective);

        try {
            $path = $service->renderPreviewPdf($objective, config('company.document_place'), $this->authorizedOverrides($request));
        } catch (PdfRenderException $e) {
            // La vista previa se abre en un iframe / pestaña: respuesta HTML
            // legible (no una pantalla de error del framework) con referencia.
            $ref = 'CC-PREV-' . now()->format('ymdHis') . '-' . strtoupper(Str::random(4));
            Log::error('Carta Compromiso: fallo generando la vista previa', ['ref' => $ref, 'objective_id' => $objective->id, 'code' => $e->code(), 'error' => $e->getMessage()]);

            return response('<!doctype html><meta charset="utf-8"><body style="font-family:Montserrat,Segoe UI,Arial,sans-serif;padding:24px;color:#7f1d1d;background:#fef2f2">'
                . '<p><strong>No pudimos generar la vista previa.</strong></p><p>Intenta nuevamente. Si continúa, proporciona esta referencia a Sistemas: <strong>' . e($ref) . '</strong>.</p></body>', 500)
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
            $ref = 'CC-PDF-' . now()->format('ymdHis') . '-' . strtoupper(Str::random(4));
            Log::error('Carta Compromiso: fallo generando el PDF definitivo', ['ref' => $ref, 'objective_id' => $objective->id, 'code' => $e->code(), 'error' => $e->getMessage()]);

            return response()->json(['message' => "No pudimos generar el documento (no se emitió ningún folio). Intenta nuevamente. Si continúa, proporciona esta referencia a Sistemas: {$ref}.", 'reference' => $ref], 500);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['letter' => $this->letterPayload($letter)]);
    }

    /** Anulación auditada — el PDF y el folio originales se conservan. */
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
     */
    public function assignAccountable(Request $request, OkrObjective $objective, OkrEmployeeBranchResolver $resolver, OkrAuditLogger $logger): JsonResponse
    {
        $this->authorize('assign', $objective);
        abort_if($objective->scope_type !== OkrObjective::SCOPE_BRANCH, 422, 'Solo un OKR de sucursal tiene titular; un OKR individual ya pertenece a su gestor.');
        abort_if($objective->commitmentLetter !== null, 422, 'Ya existe una carta vigente para este OKR — anúlala antes de cambiar al titular.');

        $data = $request->validate(['employee_id' => ['required', 'integer', 'exists:employees,id'], 'reason' => ['nullable', 'string', 'max:500']]);
        $employeeId = (int) $data['employee_id'];

        if (!$resolver->employeeIsActive($employeeId) || !$resolver->employeeBelongsToBranch($employeeId, (int) $objective->branch_id)) {
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
     */
    public function download(Request $request, OkrCommitmentLetter $letter): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('view', $letter->objective);

        return $this->serveDocument($request, $letter, 'Carta Compromiso');
    }

    public function uploadSigned(Request $request, OkrCommitmentLetter $letter): JsonResponse
    {
        $this->authorize('update', $letter->objective);
        if ($letter->isVoided()) {
            return response()->json(['message' => "La carta {$letter->folio} está anulada: no admite copia firmada. Sube la firma a la carta vigente."], 422);
        }

        return $this->storeSignedCopy($request, $letter, 'okr-commitment-letters/' . $letter->okr_objective_id . '/firmadas', 'Carta Compromiso');
    }

    /** Únicos campos capturables por el emisor; todo lo demás sale del OKR y del directorio laboral. */
    private function authorizedOverrides(Request $request): array
    {
        $data = $request->validate([
            'position' => ['nullable', 'string', 'max:120'],
            'immediate_boss' => ['nullable', 'string', 'max:160'],
            'observations' => ['nullable', 'string', 'max:600'],
        ]);

        return array_intersect_key($data, array_flip(['position', 'immediate_boss', 'observations']));
    }

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
            'view_url' => route('okr.commitment-letters.download', $letter) . '?view=1',
            'signed_view_url' => $letter->isSigned() ? route('okr.commitment-letters.download', $letter) . '?view=1&signed=1' : null,
        ];
    }
}
