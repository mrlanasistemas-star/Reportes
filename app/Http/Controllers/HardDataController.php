<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Period;
use App\Services\Access\ReportVisibilityService;
use App\Services\HardData\HardDataExcelExporter;
use App\Services\HardData\HardDataPdfExporter;
use App\Services\HardData\HardDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Módulo «Datos duros» (/datos-duros): cifras finales por colaborador y periodo
 * con búsqueda y filtros instantáneos, sin abrir la radiografía completa.
 *
 * - GET /datos-duros                         página (solo lista de periodos; abre al instante)
 * - GET /datos-duros/periodos/{period}       filas del periodo (JSON, alcance del usuario)
 * - GET /datos-duros/colaboradores/{employee}/historial   tendencia del colaborador
 * - GET /datos-duros/periodos/{period}/exportar/{excel|pdf}  descarga (todos, lo filtrado o uno)
 *
 * Mismo módulo de acceso que Reportes mensuales (module:reportes) y mismo
 * alcance por sucursal (DataScopeService) que el resto del sistema.
 */
class HardDataController extends Controller
{
    public function __construct(
        private readonly HardDataService $service,
        private readonly ReportVisibilityService $visibility,
    ) {}

    /** Página del módulo: periodos disponibles y periodo inicial (el más reciente). */
    public function index(Request $request): Response
    {
        $this->authorizeView($request);
        $periods = $this->service->periods();
        $requested = $request->integer('periodo') ?: null;
        $initial = collect($periods)->firstWhere('id', $requested) ?? collect($periods)->firstWhere('type', 'monthly') ?? ($periods[0] ?? null);

        return Inertia::render('DatosDuros/Index', [
            'periods' => $periods,
            'initialPeriodId' => $initial['id'] ?? null,
        ]);
    }

    /** Filas de datos duros de un periodo (JSON). */
    public function data(Request $request, Period $period): JsonResponse
    {
        $this->authorizeView($request);
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        abort_unless(collect($this->service->periods())->contains('id', $period->id), 404, 'Este periodo no tiene una radiografía vigente.');

        return response()->json($this->service->dataset($period, $request->user()), 200, ['Cache-Control' => 'private, no-store']);
    }

    /** Tendencia de un colaborador por periodos del mismo tipo. */
    public function history(Request $request, Employee $employee): JsonResponse
    {
        $this->authorizeView($request);
        @ini_set('memory_limit', '512M');
        @set_time_limit(180);

        $type = in_array($request->query('tipo'), ['monthly', 'weekly', 'bimonthly', 'quarterly'], true) ? $request->query('tipo') : 'monthly';

        return response()->json([
            'employee_id' => $employee->id,
            'history' => $this->service->history($employee->id, $request->user(), $type),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * Descarga Excel o PDF del periodo con las MISMAS filas de la pantalla.
     *
     * Query: `colaborador` = id (ficha de una persona) o `ids` = lista separada
     * por comas en el orden de la pantalla (lo filtrado). Sin ninguno = todos.
     * Los ids fuera del alcance del usuario simplemente no aparecen (el dataset
     * ya viene filtrado por DataScopeService).
     */
    public function export(Request $request, Period $period, string $format, HardDataExcelExporter $excel, HardDataPdfExporter $pdf)
    {
        $this->authorizeView($request);
        @ini_set('memory_limit', '768M');
        @set_time_limit(240);
        abort_unless(collect($this->service->periods())->contains('id', $period->id), 404, 'Este periodo no tiene una radiografía vigente.');

        $dataset = $this->service->dataset($period, $request->user());
        $byId = collect($dataset['rows'])->keyBy('id');
        $single = null;

        if ($request->filled('colaborador')) {
            $single = $byId->get($request->integer('colaborador'));
            abort_if($single === null, 404, 'El colaborador no está disponible en este periodo.');
            $dataset['rows'] = [$single];
        } elseif ($request->filled('ids')) {
            $dataset['rows'] = collect(explode(',', (string) $request->query('ids')))
                ->map(fn ($id) => $byId->get((int) $id))->filter()->unique('id')->values()->all();
        } else {
            $dataset['rows'] = collect($dataset['rows'])->sortByDesc('ebitda')->values()->all();
        }

        $slug = Str::slug($dataset['period']['name']).'_'.($single ? Str::slug($single['name']) : (count($dataset['rows']) === $byId->count() ? 'todos' : count($dataset['rows']).'-colaboradores'));
        $directory = storage_path('app/private/datos-duros');
        File::ensureDirectoryExists($directory);
        $path = $directory.'/'.Str::uuid().'.'.($format === 'pdf' ? 'pdf' : 'xlsx');

        try {
            if ($format === 'pdf') {
                $history = $single ? $this->service->history((int) $single['id'], $request->user(), $dataset['period']['type'] ?: 'monthly', 8) : [];
                $pdf->export($dataset, $path, $single, $history);
            } else {
                $book = $excel->build($dataset);
                IOFactory::createWriter($book, 'Xlsx')->save($path);
                $book->disconnectWorksheets();
            }
        } catch (\Throwable $e) {
            report($e);

            return response('No se pudo generar el archivo: '.$e->getMessage(), 500);
        }

        return response()->download($path, "datos-duros_{$slug}.".($format === 'pdf' ? 'pdf' : 'xlsx'), [
            'Cache-Control' => 'private, no-store',
        ])->deleteFileAfterSend();
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($this->visibility->canViewAny($request->user()), 403, 'Tu rol no tiene acceso a la consulta de reportes.');
    }
}
