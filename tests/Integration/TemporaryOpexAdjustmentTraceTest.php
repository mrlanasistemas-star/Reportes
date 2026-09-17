<?php

use App\Models\Employee;
use App\Models\Period;
use App\Models\User;
use App\Services\RadiografiaExportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(Tests\Integration\UsesRealDevDatabase::class);

/**
 * PARTE A1/A2 del cierre 17-sep-2026 (ronda 2) — traza REAL, sin confiar en
 * comentarios del código, del reporte de producción: "Web ajustada, Excel/PDF
 * sin ajuste" para BERENICE JUAREZ TEMOXTLE, Agosto 2026. Ese periodo exacto no
 * existe en la BD de desarrollo (solo hasta Junio 2026) — se usa el mismo
 * empleado real (Berenice) sobre el periodo real más reciente disponible, para
 * probar el MISMO camino de código con datos reales, no un fixture inventado.
 *
 * Reproduce EXACTAMENTE la URL que construye Preview.vue::buildActiveScopeUrl()
 * ('xlsx'/'pdf') cuando hay un appliedManualAdjustment de scope=employee — vía
 * una request HTTP real, no una llamada directa al servicio.
 */
beforeEach(function () {
    $this->useRealDevDatabaseOrSkip();

    $this->actingUser = User::query()->first();
    if (!$this->actingUser) {
        $this->markTestSkipped('No hay ningún usuario en la BD de desarrollo para autenticar la request.');
    }

    $this->berenice = Employee::query()->where('full_name', 'LIKE', '%BERENICE%JUAREZ%TEMOXTLE%')->first();
    if (!$this->berenice) {
        $this->markTestSkipped('Berenice Juarez Temoxtle no existe en la BD de desarrollo.');
    }

    $this->period = Period::find(21) ?? Period::query()->where('type', 'monthly')
        ->whereIn('id', \App\Models\PeriodSummary::query()->where('status', 'generated')->pluck('period_id'))
        ->orderByDesc('id')->first();
    if (!$this->period) {
        $this->markTestSkipped('No hay periodos mensuales con radiografía generada.');
    }
});

it('TRACES the real bug report: header Excel button (buildActiveScopeUrl xlsx) DOES carry the applied adjustment and DOES change OPEX/EBITDA in the downloaded file', function () {
    $exportService = app(RadiografiaExportService::class);

    // 1) Web oficial (sin ajuste) — línea base.
    $webOfficial = $exportService->buildSnapshot($this->period, ['scope' => 'employee', 'employee_id' => $this->berenice->id]);
    $opexOfficial = round((float) $webOfficial['summary']['opex_total'], 2);
    $ebitdaOfficial = round((float) $webOfficial['summary']['ebitda_final'], 2);

    // 2) URL EXACTA que Preview.vue::buildActiveScopeUrl('xlsx') construye para
    // scope=employee con un ajuste aplicado — misma forma que
    // buildTemporaryAdjustmentParams() + hasActiveScope (report_type=simple, scope,
    // employee_id, manual_mode, manual_employee_id, manual_amount_per_employee,
    // manual_notes — modelo unificado, cierre 17-sep-2026 ronda 2).
    $url = route('reportes-mensuales.export-filtered-radiography', $this->period->id) . '?' . http_build_query([
        'report_type' => 'simple',
        'scope' => 'employee',
        'employee_id' => $this->berenice->id,
        'manual_mode' => 'employee',
        'manual_employee_id' => $this->berenice->id,
        'manual_amount_per_employee' => '5000',
        'manual_notes' => 'Prueba ajuste temporal',
    ]);

    $response = $this->actingAs($this->actingUser)->get($url);
    $response->assertOk();

    $tmpPath = storage_path('app/radiografias/__trace_' . uniqid() . '.xlsx');
    file_put_contents($tmpPath, $response->streamedContent());
    $spreadsheet = IOFactory::load($tmpPath);
    $resumen = $spreadsheet->getSheetByName('RESUMEN');
    expect($resumen)->not->toBeNull();

    $excelValues = [];
    for ($r = 1; $r <= $resumen->getHighestRow(); $r++) {
        $label = $resumen->getCell("A{$r}")->getValue();
        $value = $resumen->getCell("B{$r}")->getValue();
        if ($label) {
            $excelValues[mb_strtoupper(trim((string) $label))] = $value;
        }
    }

    @unlink($tmpPath);

    $excelOpex = (float) ($excelValues['TOTAL OPEX GESTOR'] ?? -999999);
    $excelEbitda = (float) ($excelValues['EBITDA'] ?? -999999);

    // EVIDENCIA REAL — esto es lo que el usuario necesita confirmado con hechos,
    // no con comentarios: ¿el Excel refleja el ajuste, o sigue en los valores
    // oficiales?
    dump([
        'opex_oficial' => $opexOfficial,
        'opex_excel_con_ajuste' => $excelOpex,
        'ebitda_oficial' => $ebitdaOfficial,
        'ebitda_excel_con_ajuste' => $excelEbitda,
        'diferencia_esperada' => 5000.0,
        'diferencia_real' => round($excelOpex - $opexOfficial, 2),
    ]);

    expect(round($excelOpex - $opexOfficial, 2))->toBe(5000.0);
    expect(round($ebitdaOfficial - $excelEbitda, 2))->toBe(5000.0);
});
