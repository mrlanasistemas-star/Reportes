<?php

namespace App\Http\Controllers;

use App\Models\EstadoResultados\EstadoResultadosCredit;
use App\Models\Period;
use App\Services\Access\DataScopeService;
use App\Services\EstadoResultados\FinancialExpenses\AmortizationTemplate;
use App\Services\EstadoResultados\FinancialExpenses\AmortizationUploadService;
use App\Services\EstadoResultados\FinancialExpenses\FinancialExpenseService;
use App\Services\EstadoResultados\Import\EstadoResultadosImportException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Gastos financieros del Estado de Resultados (intereses LEROM / BBVA desde la tabla de
 * amortización). Afectan SÓLO el consolidado ⇒ se exige alcance general.
 *
 *  GET  /estado-resultados/{period}/gastos-financieros            panel del periodo
 *  POST /estado-resultados/creditos/{credit}/amortizacion          subir / sustituir la tabla (permiso ajustes)
 *  GET  /estado-resultados/creditos/plantilla-amortizacion        plantilla .xlsx de ejemplo
 */
class EstadoResultadosFinancialExpensesController extends Controller
{
    public function __construct(private readonly DataScopeService $dataScope) {}

    public function show(Request $request, Period $period, FinancialExpenseService $service): JsonResponse
    {
        abort_unless($period->type === 'monthly', 422, 'El Estado de Resultados se genera sobre periodos mensuales.');
        $this->requireGeneral($request);

        return response()->json($service->panel($period));
    }

    public function upload(Request $request, string $credit, AmortizationUploadService $service): JsonResponse
    {
        $this->requireGeneral($request);
        $model = EstadoResultadosCredit::query()->where('key', $credit)->where('active', true)->first();
        abort_if($model === null, 404, 'Ese crédito no existe o está inactivo.');
        $data = $request->validate([
            'file' => ['required', 'file'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'file.required' => 'Selecciona el archivo de la tabla de amortización.',
            'file.file' => 'No se recibió el archivo completo. Vuelve a intentarlo.',
        ]);

        try {
            $res = $service->upload($model, $data['file'], $request->user()?->id, $data['notes'] ?? null);
        } catch (EstadoResultadosImportException $e) {
            return response()->json(['message' => $e->getMessage(), 'details' => $e->details(), 'errors' => $e->errors()], $e->status());
        }
        $upload = $res['upload']->load('uploader:id,name');

        return response()->json([
            'message' => $res['idempotent']
                ? 'Esa tabla de amortización ya estaba cargada como vigente para '.$model->name.'. No se cambió nada.'
                : ($res['replaced'] ? 'Tabla de amortización sustituida' : 'Tabla de amortización cargada').' para '.$model->name.': '.$upload->rows_count.' pago(s) del '
                    .$upload->first_payment_date?->format('d/m/Y').' al '.$upload->last_payment_date?->format('d/m/Y').'.',
            'idempotent' => $res['idempotent'],
            'replaced' => $res['replaced'] ? FinancialExpenseService::uploadPayload($res['replaced']) : null,
            'upload' => FinancialExpenseService::uploadPayload($upload),
        ]);
    }

    public function template(Request $request, AmortizationTemplate $template): BinaryFileResponse
    {
        $credit = $request->filled('credito')
            ? EstadoResultadosCredit::query()->where('key', (string) $request->query('credito'))->first()
            : null;
        $name = 'Plantilla_tabla_amortizacion'.($credit ? '_'.strtoupper($credit->key) : '').'.xlsx';

        return response()->download($template->build($credit), $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    private function requireGeneral(Request $request): void
    {
        $user = $request->user();
        abort_if($user === null, 403, 'Inicia sesión para consultar el Estado de Resultados.');
        $this->dataScope->requireGeneral($user, 'Los gastos financieros (sólo existen en el consolidado)');
    }
}
