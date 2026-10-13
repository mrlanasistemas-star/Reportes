<?php

use App\Models\Branch;
use App\Models\Period;
use App\Models\Portfolio;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Fase 7 (rediseño premium) — sucursales: diagnóstico seguro de filas que no
 * son ninguna de las 13 operativas (rutas de Lendus heredadas en `branches`).
 */
it('diagnoses a resolvable route-named branch without touching it, and reports how many rows reference it', function () {
    $atlixco = Branch::query()->create(['name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'code' => 'ATLIXCO', 'is_active' => true]);
    $garbage = Branch::query()->create(['name' => 'ATLIX-GTE', 'normalized_name' => 'atlix-gte', 'code' => 'ATLIX_GTE', 'is_active' => true]);
    $period = Period::query()->create(['name' => 'P', 'code' => 'P-AUDIT', 'type' => 'monthly', 'year' => 2026, 'month' => 1, 'sequence' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-01-31']);
    Portfolio::query()->create(['period_id' => $period->id, 'branch_id' => $garbage->id, 'contract' => 'X1', 'client_name' => 'Cliente']);

    $this->artisan('branches:audit-non-operative')
        ->expectsOutputToContain('ATLIX-GTE')
        ->expectsOutputToContain('tienen un mapeo conocido pero NO se reasignaron automáticamente')
        ->assertSuccessful();

    // Sin --deactivate-unresolved, nada cambia: ni la sucursal ni sus referencias.
    expect($garbage->refresh()->is_active)->toBeTrue();
    expect(Portfolio::where('branch_id', $garbage->id)->count())->toBe(1);
});

it('deactivates only unresolved branches with --deactivate-unresolved, never a resolvable one, and never deletes/reassigns anything', function () {
    Branch::query()->create(['name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'code' => 'ATLIXCO', 'is_active' => true]);
    $resolvable = Branch::query()->create(['name' => 'ATLIX-GTE', 'normalized_name' => 'atlix-gte', 'code' => 'ATLIX_GTE', 'is_active' => true]);
    $unknown = Branch::query()->create(['name' => 'RUTA-DESCONOCIDA-XYZ', 'normalized_name' => 'ruta-desconocida-xyz', 'code' => 'RUTA_XYZ', 'is_active' => true]);

    $this->artisan('branches:audit-non-operative --deactivate-unresolved')->assertSuccessful();

    expect($resolvable->refresh()->is_active)->toBeTrue() // resolvable → se deja para decisión manual, no se toca
        ->and($unknown->refresh()->is_active)->toBeFalse(); // sin mapeo → desactivada, nunca borrada
    expect(Branch::query()->count())->toBe(3); // nada se borró
});

it('reports a clean catalog as nothing to review', function () {
    Branch::query()->create(['name' => 'CORDOBA', 'normalized_name' => 'cordoba', 'code' => 'CORDOBA', 'is_active' => true]);

    $this->artisan('branches:audit-non-operative')
        ->expectsOutputToContain('Nada que revisar')
        ->assertSuccessful();
});
