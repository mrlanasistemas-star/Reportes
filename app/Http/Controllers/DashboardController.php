<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Period;
use App\Models\PeriodSummary;
use App\Services\Access\DataScopeService;
use App\Services\Dashboard\DashboardExcelExporter;
use App\Services\Dashboard\DashboardPdfChartBuilder;
use App\Services\DashboardTrendService;
use App\Services\Pdf\BrowsershotPdfRenderer;
use App\Services\Pdf\PdfRenderException;
use App\Services\RadiografiaExportService;
use App\Services\Reporting\OperativeBranchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
        private readonly OperativeBranchService $operativeBranches,
    ) {
    }

    public function index(Request $request): Response
    {
        // buildSnapshot() es una operación pesada (agregaciones financieras completas) —
        // mismo ajuste que ya usa RadiografiaExportService::exportPdfWithConfig().
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        $periods = $this->availablePeriods();
        $latestPeriod = $periods->first();

        if (!$latestPeriod) {
            return Inertia::render('Dashboard', ['hasData' => false, 'periods' => [], 'branches' => $this->branchOptions()]);
        }

        // Fase 6.4 — alcance por sucursal: restringido sin selección → su primera sucursal.
        $branchId = app(DataScopeService::class)->resolveBranch($request->user(), $request->integer('branch_id') ?: null);

        // Cierre 15-oct-2026 (8.3) — la página abre AL INSTANTE: las cifras (snapshot
        // completo, hasta ~20 s en frío) ya no se calculan aquí, porque eso dejaba al
        // usuario esperando frente a "Entrando…" en el login. Dashboard.vue las pide a
        // dashboard-data al montarse, con estados de carga por tarjeta.
        $branchName = $branchId ? Branch::query()->whereKey($branchId)->value('name') : null;

        return Inertia::render('Dashboard', [
            'hasData' => true,
            'periods' => $periods->map(fn (Period $p) => ['id' => $p->id, 'label' => $p->label])->values(),
            'branches' => $this->branchOptions(),
            'initialPeriodId' => $latestPeriod->id,
            'scope' => ['type' => $branchId ? 'branch' : 'general', 'branch_id' => $branchId, 'branch_name' => $branchName, 'available' => true],
            'kpis' => null,
            'charts' => null,
            'deferred' => true,
        ]);
    }

    /** JSON para el selector de periodo/sucursal en vivo — nunca recarga la página. Un solo snapshot, rápido. */
    public function data(Request $request): JsonResponse
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        $periods = $this->availablePeriods();
        $period = $periods->firstWhere('id', (int) $request->integer('period_id')) ?? $periods->first();

        if (!$period) {
            return response()->json(['hasData' => false], 200, ['Cache-Control' => 'no-store, no-cache, must-revalidate']);
        }

        $branchId = app(DataScopeService::class)->resolveBranch($request->user(), $request->integer('branch_id') ?: null);

        return response()->json(
            $this->payloadForPeriod($period, $branchId),
            200,
            ['Cache-Control' => 'no-store, no-cache, must-revalidate']
        );
    }

    /**
     * Catálogo de sucursales para el filtro del Dashboard (Fase 10 de la
     * auditoría, 15-oct-2026) — SOLO las 13 operativas, misma fuente que ya usa
     * Reportes mensuales/OKR (OperativeBranchService). Nunca una lista inventada
     * aparte.
     */
    private function branchOptions(): array
    {
        $options = Branch::query()
            ->whereIn('name', $this->operativeBranches->names())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])
            ->values()
            ->all();

        // Fase 6.4 — el selector solo ofrece las sucursales del alcance del usuario.
        $user = request()->user();

        return $user ? app(DataScopeService::class)->filterBranchOptions($user, $options) : $options;
    }

    /**
     * Tendencia EBITDA/OPEX — los últimos hasta 6 periodos mensuales reales del sistema
     * (los mismos sin importar qué periodo esté viendo el usuario), para el ALCANCE
     * elegido (sucursal o consolidado). Cacheada por punto (ver DashboardTrendService):
     * el frontend la vuelve a pedir solo al cambiar de sucursal. Con caché fría (primera
     * vez tras generar un periodo nuevo) esto encadena hasta 6 buildSnapshot() — por
     * eso el mismo margen de tiempo/memoria que index()/data(), y por eso
     * GenerateRadiographyJob dispara WarmDashboardTrendCacheJob al terminar: casi
     * nunca debería tocarle a un usuario pagar ese costo en frío.
     */
    public function trend(Request $request): JsonResponse
    {
        // Fase 7.1 — la tendencia sigue el MISMO alcance que los KPI: con sucursal,
        // la de esa sucursal; sin sucursal, el consolidado. Fase 6.4 (convive): un
        // usuario restringido nunca recibe el consolidado — resolveBranch() le asigna
        // su sucursal por defecto o responde 403 si pide una fuera de su alcance.
        $branchId = app(DataScopeService::class)->resolveBranch($request->user(), $request->integer('branch_id') ?: null);

        @ini_set('memory_limit', '512M');
        @set_time_limit(120);

        return response()->json(
            [
                'trend' => $this->trendService->build($branchId),
                'scope' => ['branch_id' => $branchId, 'label' => $this->trendService->scopeLabel($branchId)],
            ],
            200,
            ['Cache-Control' => 'no-store, no-cache, must-revalidate']
        );
    }

    /**
     * PDF del Dashboard (15-oct-2026). Antes el botón "Exportar PDF" apuntaba a
     * la Radiografía completa (/reportes-mensuales/{id}/radiografia.pdf) — otro
     * reporte, de muchas páginas. Ahora genera un documento con EXACTAMENTE los
     * KPI y gráficas que el Dashboard muestra para el periodo/sucursal elegidos,
     * desde el mismo payloadForPeriod() (ningún cálculo nuevo).
     */
    public function exportPdf(Request $request, BrowsershotPdfRenderer $renderer, DashboardPdfChartBuilder $chart)
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(180);

        $periods = $this->availablePeriods();
        $period = $periods->firstWhere('id', (int) $request->integer('period_id'));
        if (!$period) {
            return response('<!doctype html><meta charset="utf-8"><body style="font-family:Segoe UI,Arial,sans-serif;padding:24px">'
                . '<p><strong>No hay radiografía generada para el periodo solicitado.</strong></p><p>Elige un periodo con radiografía generada en el Dashboard e intenta de nuevo.</p></body>', 404)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $branchId = app(DataScopeService::class)->resolveBranch($request->user(), $request->integer('branch_id') ?: null);
        $payload = $this->payloadForPeriod($period, $branchId);
        $branchName = $branchId ? ($payload['scope']['branch_name'] ?? Branch::query()->whereKey($branchId)->value('name')) : null;

        $path = storage_path('app/tmp/dashboard-' . $period->id . '-' . ($branchId ?? 'general') . '-' . uniqid() . '.pdf');
        try {
            $renderer->renderViewToFile('reports.dashboard-pdf', [
                'kpis' => $payload['kpis'],
                'charts' => $payload['charts'],
                'scope' => $payload['scope'],
                // Fase 7.2 — tendencia del MISMO alcance del documento ($branchId ya
                // resuelto/autorizado arriba: un restringido nunca recibe el consolidado).
                'trend' => $payload['kpis'] ? $this->trendService->build($branchId) : [],
                'periodLabel' => $period->label,
                'branchName' => $branchName,
                'generatedAt' => now(),
                'generatedBy' => $request->user()?->name,
                'chart' => $chart,
            ], $path, ['footer_left' => config('pdf.branding.app_name') . ' · Dashboard ' . $period->label . ($branchName ? ' · ' . $branchName : '')]);
        } catch (PdfRenderException $e) {
            $ref = 'DASH-PDF-' . now()->format('ymdHis') . '-' . strtoupper(Str::random(4));
            Log::error('Dashboard: fallo generando el PDF', ['ref' => $ref, 'period_id' => $period->id, 'branch_id' => $branchId, 'code' => $e->code(), 'error' => $e->getMessage()]);

            return response('<!doctype html><meta charset="utf-8"><body style="font-family:Segoe UI,Arial,sans-serif;padding:24px;color:#7f1d1d">'
                . '<p><strong>No pudimos generar el PDF del Dashboard.</strong></p><p>Intenta nuevamente. Si continúa, proporciona esta referencia a Sistemas: <strong>' . e($ref) . '</strong>.</p></body>', 500)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $filename = 'dashboard-' . Str::slug($period->label) . ($branchName ? '-' . Str::slug($branchName) : '-todas-las-sucursales') . '.pdf';

        return response()->download($path, $filename, ['Content-Type' => 'application/pdf'])->deleteFileAfterSend();
    }

    /**
     * Excel del Dashboard (Fase 7.3, 15-oct-2026) — MISMO payloadForPeriod() y MISMA
     * tendencia por alcance que la pantalla y el PDF (ningún cálculo nuevo): hoja de
     * resumen ejecutivo + hoja de desglose, montos en formato moneda MXN y gráficas
     * nativas de Excel (RadiographyStyleHelper, el motor de gráficas que ya usa la
     * Reportería). Antes el botón "Exportar Excel" descargaba la Radiografía completa.
     */
    public function exportExcel(Request $request, DashboardExcelExporter $exporter)
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(180);

        $periods = $this->availablePeriods();
        $period = $periods->firstWhere('id', (int) $request->integer('period_id'));
        if (!$period) {
            return response('<!doctype html><meta charset="utf-8"><body style="font-family:Segoe UI,Arial,sans-serif;padding:24px">'
                . '<p><strong>No hay radiografía generada para el periodo solicitado.</strong></p><p>Elige un periodo con radiografía generada en el Dashboard e intenta de nuevo.</p></body>', 404)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $branchId = app(DataScopeService::class)->resolveBranch($request->user(), $request->integer('branch_id') ?: null);
        $payload = $this->payloadForPeriod($period, $branchId);
        $branchName = $branchId ? ($payload['scope']['branch_name'] ?? Branch::query()->whereKey($branchId)->value('name')) : null;

        try {
            $path = $exporter->export([
                'kpis' => $payload['kpis'],
                'charts' => $payload['charts'],
                'trend' => $payload['kpis'] ? $this->trendService->build($branchId) : [],
                'periodLabel' => $period->label,
                'branchName' => $branchName,
                'generatedAt' => now(),
                'generatedBy' => $request->user()?->name,
            ], storage_path('app/tmp/dashboard-' . $period->id . '-' . ($branchId ?? 'general') . '-' . uniqid() . '.xlsx'));
        } catch (\Throwable $e) {
            $ref = 'DASH-XLSX-' . now()->format('ymdHis') . '-' . strtoupper(Str::random(4));
            Log::error('Dashboard: fallo generando el Excel', ['ref' => $ref, 'period_id' => $period->id, 'branch_id' => $branchId, 'error' => $e->getMessage()]);

            return response('<!doctype html><meta charset="utf-8"><body style="font-family:Segoe UI,Arial,sans-serif;padding:24px;color:#7f1d1d">'
                . '<p><strong>No pudimos generar el Excel del Dashboard.</strong></p><p>Intenta nuevamente. Si continúa, proporciona esta referencia a Sistemas: <strong>' . e($ref) . '</strong>.</p></body>', 500)
                ->header('Content-Type', 'text/html; charset=UTF-8');
        }

        $filename = 'dashboard-' . Str::slug($period->label) . ($branchName ? '-' . Str::slug($branchName) : '-todas-las-sucursales') . '.xlsx';

        return response()->download($path, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
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

    /**
     * Fase 10 de la auditoría (15-oct-2026) — filtro por sucursal: MISMO
     * mecanismo que ya usa Reportes mensuales (scope=branch, ver
     * MonthlyReportController::scopedData()) — nunca un cálculo nuevo ni
     * asignaciones inventadas. Con sucursal seleccionada, `$snapshot['summary']`
     * trae EXACTAMENTE las mismas claves que en alcance general (ver
     * RadiographySnapshotBuilder) — los KPIs de abajo no cambian de forma.
     */
    private function payloadForPeriod(Period $period, ?int $branchId = null): array
    {
        $config = $branchId ? ['scope' => 'branch', 'branch_id' => $branchId] : ['scope' => 'general'];
        $snapshot = $this->exportService->buildSnapshot($period, $config);

        $scope = $snapshot['scope'] ?? ['type' => 'general', 'branch_id' => null, 'branch_name' => null, 'available' => true];

        // Sucursal sin radiografía en este periodo — nunca una pantalla vacía sin
        // explicación (sección 5 de la auditoría): se distingue de "sin datos en
        // absoluto" (hasData=false, cuando no hay NINGÚN periodo generado).
        if ($branchId && !($scope['available'] ?? true)) {
            return [
                'hasData' => true,
                'scopeUnavailable' => true,
                'scope' => $scope,
                'kpis' => null,
                'charts' => null,
            ];
        }

        $sum = $snapshot['summary'];

        $rosterQuery = DB::table('period_employee_rosters')
            ->where('period_id', $period->id)
            ->where('is_active_for_period', true);
        if ($branchId) {
            $rosterQuery->where('branch_id', $branchId);
        }
        $activeCollaborators = (int) $rosterQuery->count();

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
            'scopeUnavailable' => false,
            'scope'   => $scope,
            'kpis'    => $kpis,
            // Comparativo entre sucursales no aplica con una sola sucursal
            // seleccionada — buildExecutiveChartsData() regresa null en ese caso
            // (requiere branch_radiography.branches con más de una fila); el
            // frontend oculta esas gráficas en vez de mostrarlas vacías.
            'charts'  => $this->exportService->buildExecutiveChartsData($snapshot),
        ];
    }
}
