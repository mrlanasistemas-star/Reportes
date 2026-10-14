<?php

namespace App\Http\Controllers;

use App\Services\Efectividad\Calculadora\EfectividadArchivoException;
use App\Services\Efectividad\Calculadora\EfectividadCalculadoraService;
use App\Services\Efectividad\Calculadora\EfectividadExportService;
use App\Services\Pdf\PdfRenderException;
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
 */
class EfectividadCalculadoraController extends Controller
{
    public function __construct(
        private readonly EfectividadCalculadoraService $service,
        private readonly EfectividadExportService $export,
    ) {}

    public function index(): Response
    {
        return Inertia::render('EfectividadCobranza/Index', [
            'limites' => [
                'max_archivos' => EfectividadCalculadoraService::MAX_ARCHIVOS,
                'max_mb' => 25,
            ],
        ]);
    }

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

    public function removeFile(Request $request, string $token, string $archivo): JsonResponse
    {
        return $this->responder(fn () => ['resumen' => $this->service->quitarArchivo($token, $request->user()->id, $archivo)]);
    }

    public function clear(Request $request, string $token): JsonResponse
    {
        $this->service->limpiar($token, $request->user()->id);

        return response()->json(['ok' => true]);
    }

    public function calculate(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'modo' => ['required', 'in:DIARIO,SEMANAL,MENSUAL'],
            'valor' => ['required', 'string', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],
        ]);

        return $this->responder(fn () => ['resultado' => $this->service->calcular($token, $request->user()->id, $data['modo'], $data['valor'])]);
    }

    /** Vista filtrada por oficina/promotor: subtotales y gran total recalculados en el servidor (igual que la exportación). */
    public function view(Request $request, string $token): JsonResponse
    {
        $filtros = $request->validate([
            'oficina' => ['nullable', 'string', 'max:150'],
            'promotor' => ['nullable', 'string', 'max:150'],
        ]);

        return $this->responder(fn () => ['filas' => $this->service->filtrar(
            $this->service->resultado($token, $request->user()->id),
            $filtros['oficina'] ?? null,
            $filtros['promotor'] ?? null,
        )['filas']]);
    }

    public function export(Request $request, string $token, string $formato): BinaryFileResponse|JsonResponse
    {
        $filtros = $request->validate([
            'oficina' => ['nullable', 'string', 'max:150'],
            'promotor' => ['nullable', 'string', 'max:150'],
        ]);

        try {
            $r = $this->service->filtrar(
                $this->service->resultado($token, $request->user()->id),
                $filtros['oficina'] ?? null,
                $filtros['promotor'] ?? null,
            );
            $base = 'Efectividad_'.Str::slug(($r['modo'] ?? '').'_'.($r['parametros']['fecha_corte'] ?? ''), '_');
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

    private function responder(callable $fn): JsonResponse
    {
        try {
            return response()->json($fn());
        } catch (EfectividadArchivoException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Ocurrió un error inesperado al procesar tus archivos. Intenta de nuevo.'], 500);
        }
    }
}
