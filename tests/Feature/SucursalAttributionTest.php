<?php

use App\Models\Branch;
use App\Models\Sucursal;
use App\Services\Catalog\BranchRouteCatalog;
use Database\Seeders\SucursalSeeder;

/**
 * Atribución estable ruta/sucursal (modelo de datos). El catálogo oficial
 * (BranchRouteCatalog) es la única fuente: las 11 sucursales de OKR se siembran
 * con codigo/region fijos y cada branch recibe su sucursal_id materializado,
 * nunca por parecido. Los branches que no son una de las 11 quedan en NULL.
 */
function makeBranch(string $name, bool $active = true): Branch
{
    return Branch::query()->create([
        'code' => mb_substr(str_replace(' ', '_', $name), 0, 40).'_'.random_int(100, 999),
        'name' => $name,
        'normalized_name' => mb_strtolower($name),
        'is_active' => $active,
    ]);
}

it('siembra exactamente las 11 sucursales con region Q1/Q3 correcta y codigo estable y único', function () {
    (new SucursalSeeder)->run();

    expect(Sucursal::query()->count())->toBe(11);

    $q1 = ['CUERNAVACA', 'MIACATLAN', 'ATLACOMULCO', 'IXTLAHUACA', 'SAN_LUIS_POTOSI'];
    $q3 = ['ATLIXCO', 'CORDOBA', 'HUAMANTLA', 'ORIZABA', 'TLAXCALA', 'TULA'];

    foreach ($q1 as $codigo) {
        expect(Sucursal::query()->where('codigo', $codigo)->value('region'))->toBe('Q1');
    }
    foreach ($q3 as $codigo) {
        expect(Sucursal::query()->where('codigo', $codigo)->value('region'))->toBe('Q3');
    }

    // Ninguna en Q2; los codigos cubren exactamente las 11 de OKR.
    expect(Sucursal::query()->where('region', 'Q2')->count())->toBe(0);
    expect(count($q1) + count($q3))->toBe(11);

    // codigo es UNIQUE.
    expect(Sucursal::query()->distinct()->count('codigo'))->toBe(11);

    // Todas activas.
    expect(Sucursal::query()->where('activa', true)->count())->toBe(11);
});

it('un branch de una de las 11 recibe su sucursal {codigo,nombre} + region', function () {
    $orizaba = makeBranch('ORIZABA');
    $slp = makeBranch('SAN LUIS POTOSI');

    (new SucursalSeeder)->run();

    $sum = $orizaba->fresh()->attributionSummary();
    expect($sum['sucursal']['codigo'])->toBe('ORIZABA')
        ->and($sum['sucursal']['nombre'])->toBe('ORIZABA')
        ->and($sum['region'])->toBe('Q3')
        ->and($sum['activa_derivada'])->toBeTrue();

    $sumSlp = $slp->fresh()->attributionSummary();
    expect($sumSlp['sucursal']['codigo'])->toBe('SAN_LUIS_POTOSI')
        ->and($sumSlp['sucursal']['nombre'])->toBe('SAN LUIS POTOSI')
        ->and($sumSlp['region'])->toBe('Q1');
});

it('un branch que no es una de las 11 (San Juan / Tenango / Aguascalientes / Corporativo) queda con sucursal_id NULL — sin fabricar sucursal', function () {
    $sanJuan = makeBranch('SAN JUAN DEL RÍO');
    $tenango = makeBranch('TENANGO DEL VALLE');
    $ags = makeBranch('AGUASCALIENTES');
    $corp = makeBranch('CORPORATIVO');

    (new SucursalSeeder)->run();

    foreach ([$sanJuan, $tenango, $ags, $corp] as $branch) {
        expect($branch->fresh()->sucursal_id)->toBeNull();
        $sum = $branch->fresh()->attributionSummary();
        expect($sum['sucursal'])->toBeNull()
            ->and($sum['region'])->toBeNull();
    }

    // No se creó ninguna sucursal fuera de las 11.
    expect(Sucursal::query()->count())->toBe(11);
});

it('el seeder es idempotente: correrlo de nuevo no duplica sucursales ni cambia las FK', function () {
    $orizaba = makeBranch('ORIZABA');
    $sanJuan = makeBranch('SAN JUAN DEL RÍO');

    (new SucursalSeeder)->run();
    $firstId = $orizaba->fresh()->sucursal_id;
    $firstUpdatedAt = $orizaba->fresh()->updated_at;

    (new SucursalSeeder)->run();

    expect(Sucursal::query()->count())->toBe(11);
    expect($orizaba->fresh()->sucursal_id)->toBe($firstId);
    expect($sanJuan->fresh()->sucursal_id)->toBeNull();
    // Segunda corrida no reescribe un branch ya correcto.
    expect($orizaba->fresh()->updated_at->equalTo($firstUpdatedAt))->toBeTrue();
});

it('la resolución usa el catálogo (nunca por parecido): una ruta como CHOL se atribuye a ATLIXCO; un nombre inventado queda en NULL', function () {
    $chol = makeBranch('CHOL');            // ruta de ATLIXCO en el catálogo
    $inventado = makeBranch('ATLIXCO INVENTADO 9');

    (new SucursalSeeder)->run();

    expect($chol->fresh()->sucursal->codigo)->toBe('ATLIXCO');
    expect($inventado->fresh()->sucursal_id)->toBeNull();
});

it('estado derivado: un branch cuyo nombre es una ruta INACTIVA del catálogo queda inactivo aunque is_active sea true', function () {
    // ZACATEPEC es una ruta de MIACATLAN marcada inactiva en el catálogo.
    expect(BranchRouteCatalog::route('ZACATEPEC')['active'])->toBeFalse();
    $zacatepec = makeBranch('ZACATEPEC', active: true);

    expect($zacatepec->derivedActive())->toBeFalse();

    // Un branch sucursal-nivel activo sigue activo (su nombre no es una ruta).
    $orizaba = makeBranch('ORIZABA', active: true);
    expect($orizaba->derivedActive())->toBeTrue();

    // is_active=false siempre gana.
    $cerrado = makeBranch('CUERNAVACA', active: false);
    expect($cerrado->derivedActive())->toBeFalse();
});
