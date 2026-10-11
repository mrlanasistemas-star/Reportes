<?php

use App\Models\ApiConsumer;
use App\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Fase 3.2 — "para una página de 100 empleados, no deben ejecutarse 100
 * consultas independientes de asignaciones". Prueba real de conteo de
 * queries, no solo una aserción de que la respuesta es correcta.
 */
it('resolves branch_id for a full page of employees without N+1 queries', function () {
    for ($i = 1; $i <= 40; $i++) {
        Employee::query()->create([
            'employee_code' => "E{$i}", 'full_name' => "Empleado {$i}", 'normalized_name' => "empleado {$i}", 'is_active' => true,
        ]);
    }

    $consumer = ApiConsumer::query()->create(['name' => 'Test', 'system_code' => 'queries-test', 'is_active' => true, 'abilities' => ['employees.read']]);
    $token = $consumer->createToken('test', ['employees.read'])->plainTextToken;

    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/employees?per_page=40')
        ->assertOk();

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($response->json('data'))->toHaveCount(40);
    // Un número pequeño y constante (consumidor, empleados paginados, UNA consulta
    // agregada de asignaciones, log de auditoría) — nunca O(N) con 40 empleados.
    expect($queryCount)->toBeLessThan(10);
});
