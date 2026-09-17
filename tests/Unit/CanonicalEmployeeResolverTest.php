<?php

use App\Services\CanonicalEmployeeResolver;

/**
 * Cierre 17-sep-2026, ronda 2 (C1-C4) — CanonicalEmployeeResolver extraído de
 * OkrEmployeeBranchResolver::canonicalize() para que la identidad canónica sea
 * reutilizable (C2: "una única identidad... debe ser la MISMA para
 * Reportería/Excel/OKR"). Prueba la regla en aislamiento, sin BD.
 */
function fakeEmployeeRow(int $id, ?string $normalizedName, ?string $fullName = null): object
{
    return (object) ['id' => $id, 'normalized_name' => $normalizedName, 'full_name' => $fullName ?? "Employee {$id}"];
}

it('CASO A — two historical Employee rows with the SAME normalized_name (same real person) collapse into ONE canonical identity', function () {
    $rows = collect([
        fakeEmployeeRow(10, 'adrian david muniz vazquez', 'ADRIAN DAVID MUÑIZ VAZQUEZ'),
        fakeEmployeeRow(55, 'adrian david muniz vazquez', 'ADRIAN DAVID MUÑIZ VAZQUEZ'),
    ]);

    $result = app(CanonicalEmployeeResolver::class)->canonicalize($rows);

    expect($result)->toHaveCount(1);
    expect($result->first()->id)->toBe(10); // el MENOR — el más antiguo/estable
    expect($result->first()->employee_ids)->toEqualCanonicalizing([10, 55]);
});

it('CASO B — two DIFFERENT normalized_name values (distinguishable identities) remain as TWO separate options', function () {
    $rows = collect([
        fakeEmployeeRow(100, 'juan perez lopez a'),
        fakeEmployeeRow(200, 'juan perez lopez b'),
    ]);

    $result = app(CanonicalEmployeeResolver::class)->canonicalize($rows);

    expect($result)->toHaveCount(2);
    expect($result->pluck('id')->sort()->values()->all())->toEqualCanonicalizing([100, 200]);
});

it('KNOWN LIMITATION (documented, not hidden) — two rows with the LITERALLY IDENTICAL normalized_name merge, even if they were meant to be different real people — same behavior as EmployeeBranchAutoMatchService::buildCanonicalIndex() and RadiographySnapshotBuilder::buildEmployeesGestores() elsewhere in Reportería', function () {
    $rows = collect([
        fakeEmployeeRow(1, 'juan perez lopez', 'JUAN PEREZ LOPEZ'),
        fakeEmployeeRow(2, 'juan perez lopez', 'JUAN PEREZ LOPEZ'),
    ]);

    $result = app(CanonicalEmployeeResolver::class)->canonicalize($rows);

    // Esto es INTENCIONAL y documentado — no hay ninguna señal en el esquema hoy
    // (CURP, fecha de nacimiento, etc.) para distinguir dos personas reales con
    // el nombre EXACTAMENTE idéntico. Resolverlo requeriría un dato nuevo, no una
    // heurística de texto más agresiva (que empeoraría el riesgo, no lo resolvería).
    expect($result)->toHaveCount(1);
});

it('a row without normalized_name is NEVER blindly merged with another — treated as its own identity', function () {
    $rows = collect([
        fakeEmployeeRow(1, null),
        fakeEmployeeRow(2, null),
        fakeEmployeeRow(3, 'maria lopez'),
    ]);

    $result = app(CanonicalEmployeeResolver::class)->canonicalize($rows);

    expect($result)->toHaveCount(3);
});
