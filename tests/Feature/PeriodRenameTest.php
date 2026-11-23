<?php

use App\Models\ApiConsumer;
use App\Models\Period;
use App\Models\User;
use App\Services\PeriodGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Nombre manual de periodos (23-nov-2026): solo cambia la etiqueta visible.
 * id, code, fechas, semanas y TODO lo ligado por period_id quedan intactos.
 */
function prMonthly(string $name = 'Abril 2026', int $month = 4): Period
{
    return Period::query()->create([
        'name' => $name, 'code' => sprintf('M-2026-%02d', $month), 'type' => 'monthly', 'year' => 2026, 'month' => $month, 'sequence' => 1,
        'start_date' => '2026-03-30', 'end_date' => '2026-04-26', 'is_closed' => false, 'component_period_ids' => [],
    ]);
}

it('renames a period without touching its id, code, dates or related records', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $period = prMonthly();
    DB::table('fact_placements')->insert([
        'period_id' => $period->id, 'amount' => 1500, 'operation_date' => '2026-04-02', 'product_name' => 'CRECE', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($user)->patch("/periodos/{$period->id}/nombre", ['custom_name' => '  P04   2026 '])->assertRedirect();

    $fresh = $period->fresh();
    expect($fresh->label)->toBe('P04 2026 (30 mar – 26 abr 2026)')
        ->and($fresh->shortLabel())->toBe('P04 2026')
        ->and($fresh->custom_name)->toBe('P04 2026')
        ->and($fresh->name)->toBe('Abril 2026')            // nombre del sistema intacto
        ->and($fresh->systemLabel())->toBe('Abril 2026')
        ->and($fresh->id)->toBe($period->id)
        ->and($fresh->code)->toBe('M-2026-04')
        ->and($fresh->start_date->toDateString())->toBe('2026-03-30')
        ->and($fresh->end_date->toDateString())->toBe('2026-04-26')
        ->and(DB::table('fact_placements')->where('period_id', $period->id)->sum('amount'))->toEqual(1500);
});

it('restores the system name when the custom name is empty or equal to it', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $period = prMonthly();
    $period->forceFill(['custom_name' => 'P04 2026'])->save();

    $this->actingAs($user)->patch("/periodos/{$period->id}/nombre", ['custom_name' => ''])->assertRedirect();
    expect($period->fresh()->custom_name)->toBeNull()->and($period->fresh()->shortLabel())->toBe('Abril 2026');

    $this->actingAs($user)->patch("/periodos/{$period->id}/nombre", ['custom_name' => 'abril 2026'])->assertRedirect();
    expect($period->fresh()->custom_name)->toBeNull();
});

it('rejects a name already used by another period of the same type', function () {
    $user = User::factory()->create(['role' => 'admin']);
    prMonthly('Abril 2026', 4);
    $may = prMonthly('Mayo 2026', 5);

    $this->actingAs($user)->patch("/periodos/{$may->id}/nombre", ['custom_name' => 'ABRIL 2026'])
        ->assertRedirect()->assertSessionHas('error');

    expect($may->fresh()->custom_name)->toBeNull();
});

it('rejects names longer than 120 characters', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $period = prMonthly();

    $this->actingAs($user)->patch("/periodos/{$period->id}/nombre", ['custom_name' => str_repeat('x', 121)])
        ->assertSessionHasErrors('custom_name');
});

it('keeps the custom name when the system regenerates the period', function () {
    $period = prMonthly();
    $period->forceFill(['custom_name' => 'P04 2026'])->save();

    $week = Period::query()->create([
        'name' => 'Semana 14 - Marzo 2026', 'code' => 'W-2026-14', 'type' => 'weekly', 'year' => 2026, 'month' => 4, 'sequence' => 14,
        'start_date' => '2026-03-30', 'end_date' => '2026-04-05', 'is_closed' => false,
    ]);

    // updateOrCreate del generador reescribe `name`, nunca `custom_name`.
    $regenerated = app(PeriodGenerationService::class)->generateMonthly(2026, 4, [$week->id]);

    expect($regenerated->id)->toBe($period->id)
        ->and($period->fresh()->shortLabel())->toBe('P04 2026')
        ->and($period->fresh()->name)->toBe('Abril 2026');
});

it('exposes the custom and system names in the API', function () {
    $period = prMonthly();
    $period->forceFill(['custom_name' => 'P04 2026'])->save();
    $consumer = ApiConsumer::query()->create([
        'name' => 'PR', 'system_code' => 'pr-1', 'is_active' => true, 'abilities' => ['periods.read'],
        'branch_access_mode' => ApiConsumer::BRANCH_ACCESS_GLOBAL,
    ]);
    $token = $consumer->createToken('t', ['periods.read'])->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/periods/{$period->id}")
        ->assertOk()
        ->assertJsonPath('id', $period->id)
        ->assertJsonPath('code', 'M-2026-04')
        ->assertJsonPath('label', 'P04 2026 (30 mar – 26 abr 2026)')
        ->assertJsonPath('custom_name', 'P04 2026')
        ->assertJsonPath('system_label', 'Abril 2026');
});
