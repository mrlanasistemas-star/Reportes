<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Period;
use App\Services\Access\ReportVisibilityService;
use App\Services\HardData\HardDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Módulo «Datos duros» (/datos-duros): cifras finales por colaborador y periodo
 * con búsqueda y filtros instantáneos, sin abrir la radiografía completa.
 *
 * - GET /datos-duros                         página (solo lista de periodos; abre al instante)
 * - GET /datos-duros/periodos/{period}       filas del periodo (JSON, alcance del usuario)
 * - GET /datos-duros/colaboradores/{employee}/historial   tendencia del colaborador
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

    private function authorizeView(Request $request): void
    {
        abort_unless($this->visibility->canViewAny($request->user()), 403, 'Tu rol no tiene acceso a la consulta de reportes.');
    }
}
