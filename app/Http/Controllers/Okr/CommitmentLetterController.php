<?php

namespace App\Http\Controllers\Okr;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\OkrCommitmentLetter;
use App\Models\OkrObjective;
use App\Services\Okr\OkrAuditLogger;
use App\Services\Okr\OkrCommitmentLetterService;
use App\Services\Okr\OkrEmployeeBranchResolver;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
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

    public function preview(Request $request, OkrObjective $objective, OkrCommitmentLetterService $service): StreamedResponse
    {
        $this->authorize('view', $objective);

        $path = $service->renderPreviewPdf($objective, config('company.document_place'), $this->authorizedOverrides($request));

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

    public function download(Request $request, OkrCommitmentLetter $letter): StreamedResponse
    {
        $this->authorize('view', $letter->objective);
        abort_unless(Storage::disk($letter->disk)->exists($letter->stored_path), 404, 'El documento ya no está disponible.');

        $filename = "{$letter->folio}.pdf";

        // ?view=1 (15-oct-2026): mismo archivo ya emitido, pero con disposición
        // "inline" para previsualizarlo en el sistema o en otra pestaña sin
        // forzar la descarga — la descarga normal no cambia.
        return $request->boolean('view')
            ? Storage::disk($letter->disk)->response($letter->stored_path, $filename)
            : Storage::disk($letter->disk)->download($letter->stored_path, $filename);
    }

    public function uploadSigned(Request $request, OkrCommitmentLetter $letter): JsonResponse
    {
        $this->authorize('update', $letter->objective);
        abort_if($letter->isVoided(), 422, 'Esta carta está anulada — no admite copia firmada.');
        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf']]);

        $disk = 'local';
        $path = $request->file('file')->store('okr-commitment-letters/' . $letter->okr_objective_id . '/firmadas', $disk);

        $letter->update([
            'signed_stored_path' => $path, 'signed_disk' => $disk,
            'signed_uploaded_by' => $request->user()->id, 'signed_uploaded_at' => now(),
        ]);

        return response()->json(['success' => true]);
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
            'voided_at' => $letter->voided_at?->toDateTimeString(), 'void_reason' => $letter->void_reason, 'voided_by' => $letter->voidedByUser?->name,
            'download_url' => route('okr.commitment-letters.download', $letter),
            'view_url' => route('okr.commitment-letters.download', $letter) . '?view=1',
        ];
    }
}
