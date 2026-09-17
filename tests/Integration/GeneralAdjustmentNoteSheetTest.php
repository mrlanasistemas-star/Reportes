<?php

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\RadiografiaExportService;
use PhpOffice\PhpSpreadsheet\IOFactory;

uses(Tests\Integration\UsesRealDevDatabase::class);

/**
 * A18 del cierre 17-sep-2026 (ronda 2) — la nota del gasto manual debe viajar
 * también al Excel/PDF GENERAL (antes solo aparecía en el individual de
 * colaborador). Verifica la hoja "Gasto Manual" agregada a
 * RadiographyWorkbookBuilder::buildFromSnapshot() — aislada, nunca aparece sin
 * ajuste activo, nunca rompe el resto del libro. Renombrada de "Ajuste Temporal"
 * a "Gasto Manual" en la ronda 3 (17-sep-2026) — sin comentarios/jerga extra,
 * a petición explícita del usuario.
 */
beforeEach(function () {
    $this->useRealDevDatabaseOrSkip();

    $this->period = Period::find(21) ?? Period::query()->where('type', 'monthly')
        ->whereIn('id', PeriodSummary::query()->where('status', 'generated')->pluck('period_id'))
        ->orderByDesc('id')->first();
    if (!$this->period) {
        $this->markTestSkipped('No hay periodos mensuales con radiografía generada.');
    }
});

it('adds a "Gasto Manual" sheet to the general Excel ONLY when an all_each_employee adjustment is active, with the note visible', function () {
    $exportService = app(RadiografiaExportService::class);

    $pathSin = $exportService->exportWithConfig($this->period, ['scope' => 'general', 'report_type' => 'simple']);
    $spreadsheetSin = IOFactory::load($pathSin);
    expect($spreadsheetSin->getSheetByName('Gasto Manual'))->toBeNull();
    @unlink($pathSin);

    $adjustment = ['mode' => 'all_each_employee', 'amount_per_employee' => 50.0, 'notes' => 'Nota de prueba A18'];
    $pathCon = $exportService->exportWithConfig($this->period, ['scope' => 'general', 'report_type' => 'simple', 'manual_adjustment' => $adjustment]);
    $spreadsheetCon = IOFactory::load($pathCon);
    $sheet = $spreadsheetCon->getSheetByName('Gasto Manual');
    expect($sheet)->not->toBeNull();

    $found = false;
    for ($r = 1; $r <= $sheet->getHighestRow(); $r++) {
        if ($sheet->getCell("A{$r}")->getValue() === 'Notas') {
            expect($sheet->getCell("B{$r}")->getValue())->toBe('Nota de prueba A18');
            $found = true;
        }
    }
    expect($found)->toBeTrue('No se encontró la fila "Notas" en la hoja "Ajuste Temporal".');

    // GLOBAL sigue siendo la hoja activa/visible al abrir — la nueva hoja no lo reemplaza.
    expect($spreadsheetCon->getActiveSheetIndex())->toBe(0);
    expect($spreadsheetCon->getSheet(0)->getTitle())->toBe('GLOBAL');

    @unlink($pathCon);
});
