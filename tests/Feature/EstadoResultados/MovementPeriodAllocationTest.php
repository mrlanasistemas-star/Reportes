<?php

use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\Period;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\EstadoResultados\Import\MovementPeriodAllocator;
use App\Services\EstadoResultados\Support\Treatment as T;
use Tests\Support\EstadoResultadosFixtures as F;

/**
 * 17-nov-2026: los estados de cuenta se suben por mes CALENDARIO, pero los periodos son de
 * 4 semanas. Cada movimiento se suma en el periodo de SU fecha, venga del archivo que venga,
 * y lo repetido entre archivos cuenta una sola vez.
 */
function paImport(Period $period, string $path, string $slot = 'administrativa')
{
    return app(EstadoResultadosUploadService::class)->importPath($period, $path, basename($path), $slot, null);
}

function paBuild(Period $period): array
{
    app(EstadoResultadosProcessor::class)->process($period);

    return app(EstadoResultadosDatasetBuilder::class)->build($period, 'CONSOLIDADO');
}

function paInPeriod(array $d): array
{
    return collect($d['movements'])->where('source', 'banco')->where('in_period', true)->pluck('concept')->sort()->values()->all();
}

beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
    F::fakeRadiography();
});

it('acepta archivos de mes calendario y cada movimiento entra al periodo operativo de su fecha', function () {
    $abril = F::period('2026-03-30', '2026-04-26');
    $mayo = F::period('2026-04-27', '2026-05-24');

    // Archivo de MARZO (calendario) subido en Abril: sólo el 30 y 31 de marzo son de Abril.
    paImport($abril, F::adminXlsx([
        ['2026-03-10', 10.00, null, 'S/R', 'MARZO SIN PERIODO', 'CORPORATIVO'],
        ['2026-03-31', 20.00, null, 'S/R', 'MARZO 31', 'CORPORATIVO'],
    ]), 'administrativa');
    // Archivo de ABRIL (calendario) subido en Abril (excedentes): del 27 al 30 se van a Mayo.
    paImport($abril, F::excedentesXlsx([
        ['2026-04-02', 30.00, null, 'ABRIL 2', 'CORDOBA', null],
        ['2026-04-28', 40.00, null, 'ABRIL 28', 'CORDOBA', null],
    ]), 'excedentes');
    // Archivo de MAYO (calendario) subido en Mayo: del 25 al 31 no tienen periodo aún.
    $upMayo = paImport($mayo, F::excedentesXlsx([
        ['2026-05-03', 50.00, null, 'MAYO 3', 'CORDOBA', null],
        ['2026-05-26', 60.00, null, 'MAYO 26', 'CORDOBA', null],
    ]), 'excedentes');

    expect(paInPeriod(paBuild($abril)))->toBe(['ABRIL 2', 'MARZO 31'])
        ->and(paInPeriod(paBuild($mayo)))->toBe(['ABRIL 28', 'MAYO 3'])
        ->and(implode(' ', $upMayo->fresh()->warnings))->toContain('sin periodo mensual');

    // Al crear el periodo Junio, el 26 de mayo entra solo (sin volver a subir nada).
    $junio = F::period('2026-05-25', '2026-06-21');
    expect(paInPeriod(paBuild($junio)))->toBe(['MAYO 26']);
});

it('el mismo movimiento en dos archivos se cuenta una sola vez', function () {
    $abril = F::period('2026-03-30', '2026-04-26');
    $mayo = F::period('2026-04-27', '2026-05-24');
    $row = ['2026-04-28', 40.00, null, 'S/R', 'PAGO REPETIDO', 'CORPORATIVO'];

    paImport($abril, F::adminXlsx([$row, ['2026-04-10', 5.00, null, 'S/R', 'OTRO ABRIL', 'CORPORATIVO']]));
    $second = paImport($mayo, F::adminXlsx([$row]));

    $d = paBuild($mayo);
    $copies = collect($d['movements'])->where('concept', 'PAGO REPETIDO');
    expect($copies->where('in_period', true)->count())->toBe(1)
        ->and(EstadoResultadosMovement::query()->where('concept', 'PAGO REPETIDO')->count())->toBe(2)
        ->and(EstadoResultadosMovement::query()->where('concept', 'PAGO REPETIDO')->where('placement', MovementPeriodAllocator::DUPLICADO)->count())->toBe(1)
        ->and(EstadoResultadosMovement::query()->where('concept', 'PAGO REPETIDO')->where('in_period', true)->value('upload_id'))->toBe($second->id);

    $dup = collect(paBuild($abril)['movements'])->first(fn ($m) => $m['concept'] === 'PAGO REPETIDO');
    expect($dup['treatment'])->toBe(T::FUERA_PERIODO)
        ->and($dup['dimensions']['recognition']['out_of_period'])->toBe('duplicado_otro_archivo');
});

it('reprocesar varias veces no mueve ni duplica nada', function () {
    $abril = F::period('2026-03-30', '2026-04-26');
    $mayo = F::period('2026-04-27', '2026-05-24');
    paImport($abril, F::adminXlsx([['2026-04-28', 40.00, null, 'S/R', 'ABRIL 28', 'CORPORATIVO'], ['2026-04-02', 1.00, null, 'S/R', 'ABRIL 2', 'CORPORATIVO']]));

    $first = paInPeriod(paBuild($mayo));
    expect(app(MovementPeriodAllocator::class)->sync()['changed'])->toBe(0)
        ->and(paInPeriod(paBuild($mayo)))->toBe($first)
        ->and(paInPeriod(paBuild($abril)))->toBe(['ABRIL 2']);
});

it('los periodos que ya estaban en producción (legado) no cambian: ni se mueve lo suyo ni reciben de otros archivos', function () {
    $abril = F::period('2026-03-30', '2026-04-26');
    $mayo = F::period('2026-04-27', '2026-05-24');
    // Carga "vieja" de Abril con un movimiento del 28 (fuera de periodo con la regla anterior).
    paImport($abril, F::adminXlsx([['2026-04-02', 1.00, null, 'S/R', 'ABRIL 2', 'CORPORATIVO'], ['2026-04-28', 40.00, null, 'S/R', 'ABRIL 28 VIEJO', 'CORPORATIVO']]));
    // Simula el estado de producción antes del cambio (migración 2026_11_17_000002).
    EstadoResultadosMovement::query()->where('concept', 'ABRIL 28 VIEJO')->update(['period_id' => $abril->id, 'in_period' => false]);
    EstadoResultadosMovement::query()->update(['placement' => MovementPeriodAllocator::LEGADO]);
    $before = EstadoResultadosMovement::query()->orderBy('id')->get(['id', 'period_id', 'in_period'])->toArray();

    // Archivo nuevo de MAYO subido en Mayo que trae un día de Abril (legado): no entra a Abril.
    $up = paImport($mayo, F::adminXlsx([['2026-04-20', 7.00, null, 'S/R', 'DIA DE ABRIL EN ARCHIVO MAYO', 'CORPORATIVO'], ['2026-05-03', 9.00, null, 'S/R', 'MAYO 3', 'CORPORATIVO']]));

    expect(EstadoResultadosMovement::query()->whereIn('id', array_column($before, 'id'))->orderBy('id')->get(['id', 'period_id', 'in_period'])->toArray())->toBe($before)
        ->and(paInPeriod(paBuild($abril)))->toBe(['ABRIL 2'])
        ->and(paInPeriod(paBuild($mayo)))->toBe(['MAYO 3'])
        ->and(implode(' ', $up->fresh()->warnings))->toContain('ya estaba cargado antes')
        ->and(EstadoResultadosMovement::query()->whereIn('id', array_column($before, 'id'))->orderBy('id')->get(['id', 'period_id', 'in_period'])->toArray())->toBe($before);
});
