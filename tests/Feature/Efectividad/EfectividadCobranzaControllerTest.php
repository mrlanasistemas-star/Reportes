<?php

use App\Models\Branch;
use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Models\Portfolio;
use App\Models\Recovery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Fase 8-11 — confirma el recorrido REAL end-to-end descrito por el usuario:
 * entrar al módulo, seleccionar periodo, generar, ver resultados, descargar
 * Excel — todo vía HTTP, nunca solo verificando servicios PHP aislados.
 */
function makeControllerTestPeriodo(): Period
{
    return Period::query()->create([
        'name' => 'Periodo ControllerTest', 'code' => 'M-CTRLTEST-2026-9', 'type' => 'monthly',
        'year' => 2026, 'month' => 9, 'sequence' => 1,
        'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'is_closed' => false,
    ]);
}

it('a manager can open the module, generate a real report, and download the Excel — full HTTP round-trip', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = makeControllerTestPeriodo();
    $branch = Branch::query()->create(['code' => 'COR', 'name' => 'CORDOBA', 'normalized_name' => 'cordoba', 'is_active' => true]);

    Portfolio::query()->create([
        'period_id' => $period->id, 'branch_id' => $branch->id,
        'contract' => '25COR1270278', 'promoter_name' => 'LUIS GOMEZ', 'route_name' => 'CORDOBA',
        'status' => 'VIGENTE', 'substatus' => 'VIGENTE',
        'installment_amount' => 100, 'periodicity' => 'SEMANAL', 'num_payments' => 10,
        'first_payment_date' => '2026-09-05',
    ]);
    Recovery::query()->create([
        'period_id' => $period->id, 'branch_id' => $branch->id,
        'contract' => '25COR1270278', 'promoter_name' => 'LUIS GOMEZ', 'office_name_raw' => 'CORDOBA',
        'operation' => 'PAGO A CONTRATO', 'transaction' => 'PAGO',
        'capital' => 100, 'interest' => 0, 'tax' => 0, 'total_amount' => 100,
        'payment_date' => '2026-09-05', 'installment_due_date' => '2026-09-05',
    ]);

    // 1. Abrir el módulo con el periodo seleccionado.
    $indexResponse = $this->actingAs($admin)->get("/efectividad-cobranza?period_id={$period->id}&branch_id={$branch->id}");
    $indexResponse->assertOk();
    $indexResponse->assertInertia(fn ($page) => $page
        ->component('EfectividadCobranza/Index')
        ->where('selected.period_id', $period->id)
        ->where('selected.branch_id', $branch->id)
    );

    // 2. Generar — dispara el Job (QUEUE_CONNECTION=sync en tests, corre inline).
    $generateResponse = $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', [
        'period_id' => $period->id,
        'branch_id' => $branch->id,
    ]);
    $generateResponse->assertOk();
    $runId = $generateResponse->json('run_id');
    expect($runId)->not->toBeNull();

    // 3. Progreso — debe reflejar un resultado REAL (no inventado).
    $progressResponse = $this->actingAs($admin)->getJson("/efectividad-cobranza/runs/{$runId}/progreso");
    $progressResponse->assertOk();
    $progressResponse->assertJson(['status' => 'success']);
    expect($progressResponse->json('coverage.status'))->toBe('ok');
    expect($progressResponse->json('has_excel'))->toBeTrue();

    $granTotal = collect($progressResponse->json('rows'))->firstWhere('promotor', 'GRAN TOTAL');
    expect($granTotal)->not->toBeNull();
    expect((float) $granTotal['sano_cob'])->toBe(100.0);

    // 4. Descargar — el archivo Excel generado debe existir y ser un XLSX real.
    $run = EfectividadCobranzaRun::query()->findOrFail($runId);
    $downloadResponse = $this->actingAs($admin)->get("/efectividad-cobranza/runs/{$runId}/descargar");
    $downloadResponse->assertOk();
    // Fase 10 (auditoría 2026-10-12) — output_excel_path ahora es una ruta RELATIVA
    // al disco privado 'local' (storage/app/private), no una ruta absoluta.
    expect(Storage::disk('local')->exists($run->output_excel_path))->toBeTrue();
    expect(Storage::disk('local')->size($run->output_excel_path))->toBeGreaterThan(0);
});

it('a colaborador (non-managerial role) gets 403 on the Efectividad de Cobranza module, same as the rest of Reportería', function () {
    $colaborador = User::factory()->create(['role' => 'colaborador']);
    $period = makeControllerTestPeriodo();

    $this->actingAs($colaborador)->get("/efectividad-cobranza?period_id={$period->id}")->assertForbidden();
});

it('generating twice for the same identity while queued/running reuses the existing run instead of duplicating it', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = makeControllerTestPeriodo();

    $existing = EfectividadCobranzaRun::query()->create([
        'period_id' => $period->id, 'branch_id' => null, 'status' => 'running',
        'queued_at' => now(), 'log' => 'Procesando…',
    ]);

    $response = $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', [
        'period_id' => $period->id,
    ]);

    $response->assertOk();
    expect($response->json('run_id'))->toBe($existing->id);
    expect(EfectividadCobranzaRun::query()->where('period_id', $period->id)->count())->toBe(1);
});

it('Fase 4 (auditoría 2026-10-12): a genuinely concurrent request (lock already held by another request) gets 409 and never creates a second row', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = makeControllerTestPeriodo();

    // Simula la ventana de carrera real: otra request YA tiene el lock de esta
    // identidad exacta (period_id+branch_id=general) cuando llega esta segunda.
    $lock = \Illuminate\Support\Facades\Cache::lock('efectividad-cobranza-generate-' . $period->id . '-general', 15);
    expect($lock->get())->toBeTrue();

    try {
        $response = $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', [
            'period_id' => $period->id,
        ]);

        $response->assertStatus(409);
        expect(EfectividadCobranzaRun::query()->where('period_id', $period->id)->count())->toBe(0);
    } finally {
        $lock->release();
    }
});

it('Fase 5 (auditoría 2026-10-12): status=blocked (not success) when there is no usable data, no Excel is produced, and the UI never gets a download link', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = makeControllerTestPeriodo();

    // Ningún Portfolio/Recovery para este periodo → coverage.status = sin_datos → bloqueado.
    $generateResponse = $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', [
        'period_id' => $period->id,
    ]);
    $generateResponse->assertOk();
    $runId = $generateResponse->json('run_id');

    $progressResponse = $this->actingAs($admin)->getJson("/efectividad-cobranza/runs/{$runId}/progreso");
    $progressResponse->assertOk();
    $progressResponse->assertJson(['status' => EfectividadCobranzaRun::STATUS_BLOCKED, 'has_excel' => false]);
    expect($progressResponse->json('rows'))->toBe([]);

    $downloadResponse = $this->actingAs($admin)->get("/efectividad-cobranza/runs/{$runId}/descargar");
    $downloadResponse->assertNotFound();
});
