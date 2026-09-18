<?php

namespace App\Http\Controllers;

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\DashboardTrendService;
use App\Services\RadiografiaExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dashboard principal (cierre 17-sep-2026, ronda 4/5) — antes una página estática de
 * accesos rápidos sin ningún dato real. Ahora resume cualquier periodo mensual con
 * radiografía generada (por defecto el más reciente), con selector de periodo que
 * cambia los datos EN VIVO (fetch, sin recargar la página — mismo patrón que
 * Preview.vue::fetchScopedDataset()).
 *
 * Rendimiento (ronda 5, queja real: "el dashboard tarda muchísimo en entrar") — antes
 * la carga inicial construía HASTA 7 snapshots financieros completos (el del periodo +
 * 6 de tendencia) en la MISMA request, siempre, incluso con caché frío. Ahora:
 *   - index()/data() construyen UN SOLO snapshot (el del periodo pedido) — la carga
 *     inicial y cada cambio de filtro son igual de rápidos.
 *   - La tendencia se sirve aparte (trend(), vía DashboardTrendService), cacheada 15
 *     min — es la MISMA para cualquier periodo que se esté viendo (siempre "los
 *     últimos N periodos del sistema"), así que solo se recalcula una vez cada 15
 *     min para TODOS los usuarios, nunca por cada carga de página ni cambio de filtro.
 *   - El frontend la pide de forma asíncrona después de pintar KPIs/gráficas — nunca
 *     bloquea lo que el usuario ve primero.
 *
 * Fuente ÚNICA para todos los números — nunca un segundo cálculo:
 *   - snapshot general vía RadiografiaExportService::buildSnapshot() (el MISMO que
 *     usan Web/Excel/PDF de Reportería).
 *   - gráficas vía RadiografiaExportService::buildExecutiveChartsData() (el MISMO
 *     cálculo que ya usa la página de gráficas ejecutivas del PDF general).
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly RadiografiaExportService $exportService,
        private readonly DashboardTrendService $trendService,
    ) {
    }

    public function index(): Response
    {
        // buildSnapshot() es una operación pesada (agregaciones financieras completas) —
        // mismo ajuste que ya usa RadiografiaExportService::exportPdfWithConfig().
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        $periods = $this->availablePeriods();
        $latestPeriod = $periods->first();

        if (!$latestPeriod) {
            return Inertia::render('Dashboard', ['hasData' => false, 'periods' => []]);
        }

        return Inertia::render('Dashboard', array_merge(
            ['periods' => $periods->map(fn (Period $p) => ['id' => $p->id, 'label' => $p->label])->values()],
            $this->payloadForPeriod($latestPeriod)
        ));
    }

    /** JSON para el selector de periodo en vivo — nunca recarga la página. Un solo snapshot, rápido. */
    public function data(Request $request): JsonResponse
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        $periods = $this->availablePeriods();
        $period = $periods->firstWhere('id', (int) $request->integer('period_id')) ?? $periods->first();

        if (!$period) {
            return response()->json(['hasData' => false], 200, ['Cache-Control' => 'no-store, no-cache, must-revalidate']);
        }

        return response()->json(
            $this->payloadForPeriod($period),
            200,
            ['Cache-Control' => 'no-store, no-cache, must-revalidate']
        );
    }

    /**
     * Tendencia EBITDA/OPEX — los últimos hasta 6 periodos mensuales reales del sistema,
     * SIEMPRE los mismos sin importar qué periodo esté viendo el usuario en los KPIs de
     * arriba. Cacheada 15 min (ver DashboardTrendService): el frontend la pide una sola
     * vez al montar la página, nunca en cada cambio de filtro. Con caché fría (primera
     * vez tras generar un periodo nuevo) esto encadena hasta 6 buildSnapshot() — por
     * eso el mismo margen de tiempo/memoria que index()/data(), y por eso
     * GenerateRadiographyJob dispara WarmDashboardTrendCacheJob al terminar: casi
     * nunca debería tocarle a un usuario pagar ese costo en frío.
     */
    public function trend(): JsonResponse
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        return response()->json(
            ['trend' => $this->trendService->build()],
            200,
            ['Cache-Control' => 'no-store, no-cache, must-revalidate']
        );
    }

    /** Periodos mensuales reales (nunca de prueba/migración) con radiografía generada, más reciente primero. */
    private function availablePeriods()
    {
        $generatedPeriodIds = PeriodSummary::query()->where('status', 'generated')->pluck('period_id');

        // Nunca un periodo "de prueba"/migración con año absurdo (ej. 2099) — ver
        // auditoría 17-sep-2026: "Test Migracion 1406" existe en BD de desarrollo con
        // year=2099 y ordenaría primero por fecha si no se acota al año real.
        return Period::query()
            ->where('type', 'monthly')
            ->where('year', '<=', now()->year)
            ->whereIn('id', $generatedPeriodIds)
            ->orderByDesc('year')->orderByDesc('month')
            ->get();
    }

    private function payloadForPeriod(Period $period): array
    {
        $snapshot = $this->exportService->buildSnapshot($period, ['scope' => 'general']);
        $sum = $snapshot['summary'];

        $activeCollaborators = (int) DB::table('period_employee_rosters')
            ->where('period_id', $period->id)
            ->where('is_active_for_period', true)
            ->count();

        $kpis = [
            'period_label'  => $period->label,
            'period_id'     => $period->id,
            'ebitda'        => round((float) ($sum['ebitda_final'] ?? 0), 2),
            'margen_ebitda' => round((float) ($sum['margen_ebitda'] ?? 0), 2),
            'opex'          => round((float) ($sum['opex_total'] ?? 0), 2),
            'colocacion'    => round((float) ($sum['placement_total'] ?? 0), 2),
            'recuperacion'  => round((float) ($sum['recovery_total'] ?? 0), 2),
            'cartera'       => round((float) ($sum['portfolio_total'] ?? 0), 2),
            'mora_pct'      => round((float) ($sum['mora_index'] ?? 0), 2),
            'colaboradores' => $activeCollaborators,
        ];

        return [
            'hasData' => true,
            'kpis'    => $kpis,
            'charts'  => $this->exportService->buildExecutiveChartsData($snapshot),
        ];
    }
}
