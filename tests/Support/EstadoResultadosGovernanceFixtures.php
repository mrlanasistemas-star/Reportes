<?php

namespace Tests\Support;

use App\Models\Period;
use App\Models\User;
use App\Services\Access\ActionPermissionService;
use App\Services\Access\ModuleAccessService;
use App\Services\EstadoResultados\AdjustmentService;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Support\Facades\DB;
use Tests\Support\EstadoResultadosFixtures as F;

/**
 * Fixtures sintéticas de GOBIERNO del Estado de Resultados (aprobación, versiones,
 * permisos). Archivo propio para no chocar con EstadoResultadosFixtures.
 */
final class EstadoResultadosGovernanceFixtures
{
    public static function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'access_enabled_at' => now()]);
    }

    /** Usuario gerencial con el módulo ER y SÓLO las acciones indicadas. */
    public static function userWith(array $actions): User
    {
        app(ModuleAccessService::class)->save(['gerencial' => ['dashboard', 'estado_resultados']]);
        app(ActionPermissionService::class)->save(['gerencial' => ['estado_resultados' => $actions]]);

        return User::factory()->create(['role' => 'gerencial', 'access_enabled_at' => now(), 'branch_scope' => 'all']);
    }

    public static function noi(Period $period, string $amount = '1000.00'): void
    {
        DB::table('fact_noi_movements')->insert([
            'period_id' => $period->id, 'concept' => 'SUELDO', 'amount' => $amount,
            'raw_row_hash' => uniqid('noi_', true), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function import(Period $period, string $path, string $slot = 'administrativa')
    {
        return app(EstadoResultadosUploadService::class)->importPath($period, $path, basename($path), $slot, null);
    }

    public static function confirmAdjustments(Period $period, string $scope = 'CONSOLIDADO'): void
    {
        foreach (array_keys(AdjustmentService::CONCEPTS) as $concept) {
            app(AdjustmentService::class)->save($period, $scope, $concept, AdjustmentService::CONFIRMADO_CERO, null, 'Confirmado en prueba', null);
        }
    }

    /**
     * Periodo COMPLETO y sin pendientes ⇒ «Listo para revisión»: ERP (corporativo), NOI,
     * las dos cuentas cargadas y procesadas, ajustes del consolidado confirmados.
     * Incluye un pago de nómina corporativa (para probar la redacción).
     */
    public static function readyPeriod(): Period
    {
        $period = F::period();
        F::erp($period, 'CORPORATIVO', 'REQ-C1', '7000.00', '2026-09-05', 'Luz', 'LUZ CORPORATIVO');
        self::noi($period);
        self::import($period, F::adminXlsx([
            ['2026-09-10', 214032.81, null, 'S/R', 'NOM QUINCENA 17', 'CORPORATIVO', 'EMPLEADO CONFIDENCIAL'],
        ]));
        self::import($period, F::excedentesXlsx([
            ['2026-09-01', null, 112000.00, 'RETIRO EXCEDENTE', 'ORIZABA', null],
        ]), 'excedentes');
        app(EstadoResultadosProcessor::class)->process($period);
        self::confirmAdjustments($period);
        self::radiographySummary($period);

        return $period;
    }

    /**
     * Consolidado de Radiografía vigente (period_summaries) — la aprobación lo exige (lista blanca,
     * fase 2.1). Fila mínima SINTÉTICA, sólo para pruebas.
     */
    public static function radiographySummary(Period $period): void
    {
        DB::table('period_summaries')->updateOrInsert(
            ['period_id' => $period->id],
            ['status' => 'generated', 'version' => 1, 'generated_at' => now(), 'invalidated_at' => null, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /** Sustituye Browsershot: el "PDF" generado es el HTML de la vista (permite inspeccionarlo). */
    public static function fakePdfRenderer(): void
    {
        app()->instance(BrowsershotPdfRenderer::class, new class extends BrowsershotPdfRenderer
        {
            public function renderHtmlToFile(string $html, string $outputPath, array $options = []): void
            {
                file_put_contents($outputPath, $html);
            }
        });
    }
}
