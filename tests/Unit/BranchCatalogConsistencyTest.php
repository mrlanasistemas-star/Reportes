<?php

use App\Services\BranchResolverService;
use App\Services\Catalog\BranchRouteCatalog;

/**
 * Un solo criterio de sucursales: el catálogo financiero de Reportería (13) es
 * exactamente el operativo de OKR (11) más las 2 históricas. Si alguien agrega o
 * quita una sucursal en un solo lado, esta prueba falla en vez de dejar dos
 * catálogos contradictorios.
 */
it('las 13 sucursales financieras son las 11 de OKR más las 2 históricas, sin contradicción', function () {
    $norm = fn (string $s) => strtr(mb_strtoupper($s), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U']);
    $financieras = array_map($norm, app(BranchResolverService::class)->operativeFinancialBranches());
    $okr = array_map($norm, BranchRouteCatalog::OKR_BRANCHES);
    $historicas = array_map($norm, BranchRouteCatalog::HISTORICAL_FINANCIAL_BRANCHES);
    sort($financieras);
    $esperadas = array_merge($okr, $historicas);
    sort($esperadas);

    expect($financieras)->toBe($esperadas)
        ->and(array_intersect($okr, $historicas))->toBe([])
        ->and(count($okr))->toBe(11)
        ->and(array_intersect($okr, array_map($norm, BranchRouteCatalog::NON_OPERATIVE)))->toBe([]);
});

it('las rutas se resuelven por catálogo, nunca por parecido del nombre', function () {
    $c = app(BranchRouteCatalog::class);
    foreach (['CHOL', 'TOCH', 'ATLIX-CEN', 'ATLIX-SUR', 'ATLIX-NOR', 'ATLIX-GTE', 'ATLIX-MATS'] as $r) {
        expect($c->officialBranchFor($r))->toBe('ATLIXCO');
    }
    foreach (['TULA CENTRO', 'TEPEJI', 'CRUZ AZUL', 'TLAXCOAPAN', 'ATOTONILCO'] as $r) {
        expect($c->officialBranchFor($r))->toBe('TULA');
    }
    expect($c->officialBranchFor('APIZ-CEN'))->toBe('HUAMANTLA')
        ->and($c->officialBranchFor('RUTA INVENTADA ATLIXCO 9'))->toBeNull();
});
