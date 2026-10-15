<?php

use App\Models\Period;
use App\Models\PeriodSummary;
use App\Models\User;
use App\Services\RadiografiaExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * 8.3 — Después de iniciar sesión el usuario entra directo: abrir el Dashboard NO
 * calcula el snapshot (hasta ~20 s en frío); las cifras llegan por dashboard-data.
 */
it('abre el Dashboard sin calcular ningún snapshot y deja las cifras para dashboard-data', function () {
    $p = Period::query()->create(['name' => 'Junio 2026', 'code' => 'DD-'.uniqid(), 'type' => 'monthly', 'year' => 2026, 'month' => 6, 'sequence' => 1,
        'start_date' => '2026-05-25', 'end_date' => '2026-06-21']);
    PeriodSummary::query()->create(['period_id' => $p->id, 'status' => 'generated']);

    $this->mock(RadiografiaExportService::class, fn ($m) => $m->shouldNotReceive('buildSnapshot'));

    $this->withoutVite()->actingAs(User::factory()->create(['role' => 'admin']))->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')
            ->where('hasData', true)
            ->where('deferred', true)
            ->where('initialPeriodId', $p->id)
            ->where('kpis', null));
});
