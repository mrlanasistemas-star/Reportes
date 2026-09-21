<?php

use App\Http\Controllers\MonthlyReportController;
use App\Models\Period;
use App\Models\PeriodRadiographyExport;
use App\Models\PeriodRadiographyRun;
use App\Services\PeriodDerivedDataCleaner;

uses(Tests\Integration\UsesRealDevDatabase::class);

/**
 * Retoma 21-sep-2026, puntos 9/11/14/15 — persistFilteredRunExport() corre DESPUÉS de
 * que el Excel/PDF filtrado ya existe en disco. Antes de este fix, un error de BD al
 * guardar el historial (PeriodRadiographyRun/PeriodRadiographyExport) se propagaba sin
 * capturar y convertía una descarga que YA HABÍA FUNCIONADO en un 500 — exactamente el
 * tipo de error reportado en el comparativo.
 *
 * Este test fuerza un fallo de BD REAL (violación de FK: period_radiography_runs.
 * period_id → periods.id, ON DELETE CASCADE, definida en la migración) pasando un
 * Period con un id que no existe. Al ser un fallo de INSERT rechazado por el motor,
 * no se escribe ningún dato — sigue siendo solo-lectura contra la BD de desarrollo
 * real. La única aserción es que persistFilteredRunExport() NUNCA deja escapar la
 * excepción (así el controller siempre llega a response()->download()).
 */
beforeEach(function () {
    $this->useRealDevDatabaseOrSkip();
});

it('persistFilteredRunExport() never throws when the history write hits a real DB error (FK violation)', function () {
    $bogusPeriod = new Period(['id' => 999999999]);
    $bogusPeriod->exists = true; // evita que Eloquent intente un INSERT de Period al tocar la relación

    $countRunsBefore    = PeriodRadiographyRun::query()->count();
    $countExportsBefore = PeriodRadiographyExport::query()->count();

    $controller = app(MonthlyReportController::class);
    $cleaner    = app(PeriodDerivedDataCleaner::class);

    $method = new ReflectionMethod($controller, 'persistFilteredRunExport');
    $method->setAccessible(true);

    $thrown = null;
    try {
        $method->invoke(
            $controller,
            $bogusPeriod,
            ['scope' => 'general', 'report_type' => 'simple'],
            'pdf',
            '/tmp/this-file-does-not-need-to-exist-for-this-test.pdf',
            $cleaner,
        );
    } catch (\Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeNull();

    // Confirma que en efecto NO se escribió nada (el INSERT fue rechazado por la FK) —
    // este test sigue siendo de solo lectura sobre la BD de desarrollo real.
    expect(PeriodRadiographyRun::query()->count())->toBe($countRunsBefore);
    expect(PeriodRadiographyExport::query()->count())->toBe($countExportsBefore);
});
