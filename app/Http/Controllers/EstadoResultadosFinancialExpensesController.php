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
use Symfony\Component\HttpKernel\Exception\HttpException;

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
    /**
     * @param  DataScopeService  $dataScope  Valida que el usuario tenga alcance general (todas las sucursales).
     */
    public function __construct(private readonly DataScopeService $dataScope) {}

    /**
     * Panel de gastos financieros del periodo: créditos, tabla vigente e intereses que caen en el mes.
     *
     * @param  Period  $period  Debe ser mensual (422 si no).
     * @param  FinancialExpenseService  $service  Arma el panel.
     * @return JsonResponse Panel en JSON; 403 si el usuario no tiene alcance general.
     */
    public function show(Request $request, Period $period, FinancialExpenseService $service): JsonResponse
    {
        abort_unless($period->type === 'monthly', 422, 'El Estado de Resultados se genera sobre periodos mensuales.');
        $this->requireGeneral($request);

        return response()->json($service->panel($period));
    }

    /**
     * Sube o sustituye la tabla de amortización de un crédito activo.
     *
     * Efectos: AmortizationUploadService guarda el archivo y escribe en BD la carga y sus renglones;
     * la carga vigente anterior queda sustituida. Si el mismo archivo ya era el vigente responde
     * idempotente sin cambiar nada.
     *
     * @param  Request  $request  `file` (obligatorio) y `notes` (opcional, máx. 2000).
     * @param  string  $credit  Llave del crédito (`key`, p.ej. lerom | bbva); 404 si no existe o está inactivo.
     * @param  AmortizationUploadService  $service  Lee y persiste la tabla.
     * @return JsonResponse Mensaje en español, bandera `idempotent`, carga `replaced` (o null) y la nueva `upload`;
     *                      en error de lectura, el mensaje/detalles/errores con el código que indique la excepción.
     */
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

    /**
     * Descarga una plantilla .xlsx de ejemplo para la tabla de amortización.
     *
     * No exige alcance general. Genera un archivo temporal que se borra después de enviarse.
     *
     * @param  Request  $request  `credito` opcional (llave) para personalizar la plantilla y el nombre del archivo.
     * @param  AmortizationTemplate  $template  Generador del Excel.
     */
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

    /**
     * Exige sesión iniciada y alcance general; los gastos financieros sólo existen en el consolidado.
     *
     * @throws HttpException 403 si no hay usuario o su alcance es restringido.
     */
    private function requireGeneral(Request $request): void
    {
        $user = $request->user();
        abort_if($user === null, 403, 'Inicia sesión para consultar el Estado de Resultados.');
        $this->dataScope->requireGeneral($user, 'Los gastos financieros (sólo existen en el consolidado)');
    }
}
