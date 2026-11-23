<?php

namespace App\Http\Controllers;

use App\Services\Efectividad\Calculadora\EfectividadArchivoException;
use App\Services\Efectividad\Calculadora\EfectividadCalculadoraService;
use App\Services\Efectividad\Calculadora\EfectividadExportService;
use App\Services\Pdf\PdfRenderException;
use App\Support\TechnicalErrorReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Calculadora de Efectividad de Cobranza — flujo único, sin base de datos.
 * Todas las acciones operan sobre un espacio efímero identificado por un token
 * ligado al usuario autenticado (otro usuario recibe "sesión expirada").
 *
 * Flujo de la pantalla: subir archivos de Excel → calcular (modo + fecha/periodo) → ver/filtrar
 * por sucursal, oficina o promotor → exportar (PDF, imagen o Excel). El cálculo lo hace
 * EfectividadCalculadoraService; las exportaciones EfectividadExportService.
 */
class EfectividadCalculadoraController extends Controller
{
    /**
     * @param  EfectividadCalculadoraService  $service  Espacio efímero (archivos, cálculo, filtros).
     * @param  EfectividadExportService  $export  Genera PDF, PNG y XLSX del resultado.
     */
    public function __construct(
        private readonly EfectividadCalculadoraService $service,
        private readonly EfectividadExportService $export,
    ) {}

    /**
     * Renderiza la pantalla Inertia `EfectividadCobranza/Index` con los límites de carga
     * (máximo de archivos y 25 MB por archivo).
     */
    public function index(): Response
    {
        return Inertia::render('EfectividadCobranza/Index', [
            'limites' => [
                'max_archivos' => EfectividadCalculadoraService::MAX_ARCHIVOS,
                'max_mb' => 25,
            ],
        ]);
    }

    /**
     * Agrega archivos de Excel (.xlsx/.xlsm, máx. 25 MB c/u) al espacio efímero del usuario.
     *
     * Si no se manda `token` el servicio crea un espacio nuevo. No escribe en BD; los archivos
     * viven en el almacenamiento temporal del servicio.
     *
     * @param  Request  $request  `token` opcional (40 caracteres) y `archivos[]`.
     * @return JsonResponse Respuesta del servicio (token y resumen); 422 por archivo inválido; 500 con referencia técnica.
     */
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['nullable', 'string', 'size:40'],
            'archivos' => ['required', 'array', 'min:1', 'max:'.EfectividadCalculadoraService::MAX_ARCHIVOS],
            'archivos.*' => ['file', 'max:25600', 'mimes:xlsx,xlsm', 'mimetypes:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel.sheet.macroEnabled.12,application/zip,application/octet-stream'],
        ], [
            'archivos.*.mimes' => 'Solo se aceptan archivos de Excel (.xlsx).',
            'archivos.*.mimetypes' => 'Solo se aceptan archivos de Excel (.xlsx).',
            'archivos.*.max' => 'Cada archivo puede pesar como máximo 25 MB.',
        ]);

        return $this->responder(fn () => $this->service->agregarArchivos($request->input('token'), $request->user()->id, $request->file('archivos')));
    }

    /**
     * Quita un archivo del espacio efímero y devuelve el resumen actualizado.
     *
     * @param  string  $token  Espacio del usuario.
     * @param  string  $archivo  Identificador del archivo dentro del espacio.
     */
    public function removeFile(Request $request, string $token, string $archivo): JsonResponse
    {
        return $this->responder(fn () => ['resumen' => $this->service->quitarArchivo($token, $request->user()->id, $archivo)]);
    }

    /**
     * Vacía por completo el espacio efímero del usuario (archivos y resultado).
     *
     * @param  string  $token  Espacio a limpiar.
     */
    public function clear(Request $request, string $token): JsonResponse
    {
        $this->service->limpiar($token, $request->user()->id);

        return response()->json(['ok' => true]);
    }

    /**
     * Calcula la efectividad de cobranza con los archivos del espacio.
     *
     * @param  Request  $request  `modo` (DIARIO | ACUMULADO | SEMANAL | MENSUAL) y `valor`
     *                            (fecha AAAA-MM-DD o mes AAAA-MM).
     * @param  string  $token  Espacio del usuario.
     * @return JsonResponse { resultado }; el servicio guarda el resultado en el espacio para vista/exportes.
     */
    public function calculate(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'modo' => ['required', 'in:DIARIO,ACUMULADO,SEMANAL,MENSUAL'],
            'valor' => ['required', 'string', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],
        ]);

        return $this->responder(fn () => ['resultado' => $this->service->calcular($token, $request->user()->id, $data['modo'], $data['valor'])]);
    }

    /**
     * Vista filtrada por oficina/promotor: subtotales y gran total recalculados en el servidor (igual que la exportación).
     *
     * @param  Request  $request  Filtros opcionales: sucursal, oficina, promotor.
     * @param  string  $token  Espacio con un resultado ya calculado.
     * @return JsonResponse { filas }.
     */
    public function view(Request $request, string $token): JsonResponse
    {
        $filtros = $request->validate([
            'sucursal' => ['nullable', 'string', 'max:150'],
            'oficina' => ['nullable', 'string', 'max:150'],
            'promotor' => ['nullable', 'string', 'max:150'],
        ]);

        return $this->responder(fn () => ['filas' => $this->service->filtrar(
            $this->service->resultado($token, $request->user()->id),
            $filtros['sucursal'] ?? null, $filtros['oficina'] ?? null,
            $filtros['promotor'] ?? null,
        )['filas']]);
    }

    /**
     * Exporta el resultado filtrado a PDF (`formato` = pdf) o, con cualquier otro valor, a imagen
     * (la extensión la decide el servicio). El archivo temporal se borra tras enviarse.
     *
     * @param  string  $token  Espacio con resultado calculado.
     * @param  string  $formato  pdf | (otro → imagen).
     * @return BinaryFileResponse|JsonResponse Descarga; 422 si el espacio no es válido; 500 si Chrome/Browsershot no está disponible.
     */
    public function export(Request $request, string $token, string $formato): BinaryFileResponse|JsonResponse
    {
        try {
            $r = $this->resultadoFiltrado($request, $token);
            $base = $this->nombreBase($r);
            if ($formato === 'pdf') {
                return response()->download($this->export->pdf($r), $base.'.pdf', ['Content-Type' => 'application/pdf'])->deleteFileAfterSend();
            }
            $png = $this->export->png($r);

            return response()->download($png['ruta'], $base.'.'.$png['tipo'])->deleteFileAfterSend();
        } catch (EfectividadArchivoException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (PdfRenderException) {
            return response()->json(['message' => 'No se pudo generar el archivo (Chrome/Browsershot no disponible). Intenta de nuevo o avisa a sistemas.'], 500);
        }
    }

    /**
     * Exportación a Excel (.xlsx) — mismo resultado y formato que el PDF/imagen. Ruta y
     * permiso propios (`efectividad,exportar_excel`) para designar por rol quién ve el botón.
     */
    public function exportExcel(Request $request, string $token): BinaryFileResponse|JsonResponse
    {
        try {
            $r = $this->resultadoFiltrado($request, $token);

            return response()->download(
                $this->export->xlsx($r),
                $this->nombreBase($r).'.xlsx',
                ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            )->deleteFileAfterSend();
        } catch (EfectividadArchivoException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Aplica los filtros de la vista (sucursal/oficina/promotor) sobre el resultado calculado.
     *
     * @return array Resultado filtrado con subtotales recalculados.
     *
     * @throws EfectividadArchivoException Si el espacio expiró o no tiene resultado.
     */
    private function resultadoFiltrado(Request $request, string $token): array
    {
        $filtros = $request->validate([
            'sucursal' => ['nullable', 'string', 'max:150'],
            'oficina' => ['nullable', 'string', 'max:150'],
            'promotor' => ['nullable', 'string', 'max:150'],
        ]);

        return $this->service->filtrar(
            $this->service->resultado($token, $request->user()->id),
            $filtros['sucursal'] ?? null, $filtros['oficina'] ?? null,
            $filtros['promotor'] ?? null,
        );
    }

    /**
     * Nombre base de los archivos exportados: Efectividad_{modo}_{fecha_corte} (slug).
     */
    private function nombreBase(array $r): string
    {
        return 'Efectividad_'.Str::slug(($r['modo'] ?? '').'_'.($r['parametros']['fecha_corte'] ?? ''), '_');
    }

    /**
     * Ejecuta una acción y la responde como JSON. EfectividadArchivoException → 422 con su mensaje;
     * cualquier otro error → se reporta al log con una referencia técnica y se responde 500 con
     * un mensaje genérico y esa referencia (sin detalles internos).
     */
    private function responder(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (EfectividadArchivoException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            $ref = TechnicalErrorReference::forCurrentRequest();
            report($e); // el log lleva error_ref = $ref

            return response()->json(['message' => TechnicalErrorReference::message($ref), 'reference' => $ref], 500);
        }
    }
}
