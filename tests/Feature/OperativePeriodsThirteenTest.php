<?php

use App\Models\DataSource;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\PeriodGenerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Calendario MR LANA de 13 periodos (23-nov-2026): P01-P13 por año, sin pisar un
 * periodo existente con datos y con su rango de fechas en el nombre visible.
 */
function opWeek(int $seq, string $start, string $end, int $month): Period
{
    return Period::query()->create([
        'name' => "Semana {$seq}", 'code' => sprintf('W-2026-%02d', $seq), 'type' => 'weekly', 'year' => 2026, 'month' => $month, 'sequence' => $seq,
        'start_date' => $start, 'end_date' => $end, 'is_closed' => false,
    ]);
}

it('never reassigns the weeks of an existing operative period that already has data', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $w42 = opWeek(42, '2026-10-12', '2026-10-18', 10);
    $w46 = opWeek(46, '2026-11-09', '2026-11-15', 11);
    $p11 = app(PeriodGenerationService::class)->generateMonthly(2026, 11, [$w42->id]);
    $source = DataSource::query()->firstOrCreate(['code' => 'gastos'], ['name' => 'Gastos', 'description' => 'x', 'is_active' => true]);
    ReportUpload::query()->create([
        'period_id' => $p11->id, 'data_source_id' => $source->id, 'original_name' => 'g.xlsx', 'stored_path' => 'reports/g.xlsx',
        'mime_type' => 'application/vnd.ms-excel', 'file_size' => 1, 'uploaded_at' => now(), 'status' => 'processed',
    ]);

    $this->actingAs($user)->post('/periodos', ['type' => 'monthly', 'year' => 2026, 'month' => 11, 'week_ids' => [$w46->id]])
        ->assertRedirect()
        ->assertSessionHas('error', fn ($m) => str_contains($m, 'P12'));

    expect(array_map('intval', $p11->fresh()->component_period_ids))->toBe([$w42->id]);
});

it('creates P13 (no calendar month of its own) and shows every operative period with its date range', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $w50 = opWeek(50, '2026-12-07', '2026-12-13', 12);
    $w53 = opWeek(53, '2026-12-28', '2027-01-03', 12);

    $this->actingAs($user)->post('/periodos', ['type' => 'monthly', 'year' => 2026, 'month' => 13, 'week_ids' => [$w50->id, $w53->id]])
        ->assertRedirect()
        ->assertSessionHas('success');

    $p13 = Period::query()->where('type', 'monthly')->where('year', 2026)->where('month', 13)->firstOrFail();
    expect($p13->name)->toBe('P13 2026')
        ->and($p13->code)->toBe('M-2026-13')
        ->and($p13->label)->toBe('P13 2026 (7 dic 2026 – 3 ene 2027)')
        ->and($p13->shortLabel())->toBe('P13 2026');
});

it('rejects P13 for weekly generation and anything above P13 for operative periods', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $this->actingAs($user)->post('/periodos', ['type' => 'weekly', 'year' => 2026, 'month' => 13])->assertSessionHasErrors('month');
    $this->actingAs($user)->post('/periodos', ['type' => 'monthly', 'year' => 2026, 'month' => 14])->assertSessionHasErrors('month');
});

it('adds P13 to the last grouped blocks only when the year has a P13', function () {
    $gen = app(PeriodGenerationService::class);
    foreach ([10, 11, 12] as $i => $m) {
        $w = opWeek(40 + $i, sprintf('2026-%02d-05', $m), sprintf('2026-%02d-11', $m), $m);
        $gen->generateMonthly(2026, $m, [$w->id]);
    }
    $gen->syncDerivedPeriods(2026);
    $q4 = Period::query()->where('type', 'quarterly')->where('year', 2026)->where('sequence', 4)->first();
    expect($q4)->not->toBeNull()->and($q4->component_period_ids)->toHaveCount(3);

    $w = opWeek(53, '2026-12-28', '2027-01-03', 12);
    $gen->generateMonthly(2026, 13, [$w->id]);
    $gen->syncDerivedPeriods(2026);

    expect(Period::query()->where('type', 'quarterly')->where('year', 2026)->where('sequence', 4)->first()->component_period_ids)->toHaveCount(4);
});
