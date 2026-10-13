<?php

use App\Models\EfectividadCobranzaRun;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EfectividadV4Fixtures as V4;

uses(RefreshDatabase::class);

/**
 * Rediseño premium — PDF resumen de Efectividad de Cobranza (Browsershot, bajo
 * demanda, nunca dentro del Job). Reutiliza result_rows ya cerrados: nunca
 * recalcula el resultado financiero.
 */
it('downloads a real PDF for a successful run, generated on first request and reused after', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = V4::week();
    $branch = V4::branch();
    V4::seedValid($period, $branch);

    $gen = $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', [
        'period_id' => $period->id, 'branch_id' => $branch->id,
    ])->assertOk()->json();
    $run = EfectividadCobranzaRun::query()->findOrFail($gen['run_id']);
    expect($run->status)->toBe('success');

    $response = $this->actingAs($admin)->get("/efectividad-cobranza/runs/{$run->id}/descargar-pdf");
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');

    $path = app(\App\Services\Efectividad\EfectividadCobranzaPdfExporter::class)->existingRelativePath($run);
    expect($path)->not->toBeNull();
    expect(\Illuminate\Support\Facades\Storage::disk('local')->size($path))->toBeGreaterThan(1000);

    // Segunda descarga: reutiliza el mismo archivo (no lo regenera).
    $mtimeFirst = \Illuminate\Support\Facades\Storage::disk('local')->lastModified($path);
    $this->actingAs($admin)->get("/efectividad-cobranza/runs/{$run->id}/descargar-pdf")->assertOk();
    expect(\Illuminate\Support\Facades\Storage::disk('local')->lastModified($path))->toBe($mtimeFirst);
})->group('browsershot');

it('returns 404 for the PDF of a run that is not success yet', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = V4::week();
    $run = EfectividadCobranzaRun::query()->create([
        'period_id' => $period->id, 'status' => 'blocked', 'engine_version' => 'mrlana-v4-2026-10',
    ]);

    $this->actingAs($admin)->get("/efectividad-cobranza/runs/{$run->id}/descargar-pdf")->assertNotFound();
});

it('a colaborador cannot download the PDF (same authorization as the Excel)', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $colaborador = User::factory()->create(['role' => 'colaborador']);
    $period = V4::week();
    $run = EfectividadCobranzaRun::query()->create([
        'period_id' => $period->id, 'status' => 'success', 'engine_version' => 'mrlana-v4-2026-10',
        'result_rows' => [['promotor' => 'GRAN TOTAL', 'sano_pc' => 100, 'sano_cob' => 50, 'cobranza_total' => 50, 'pct_total' => 0.5, 'mora' => 50, 'es_total' => true]],
        'created_by' => $admin->id,
    ]);

    $this->actingAs($colaborador)->get("/efectividad-cobranza/runs/{$run->id}/descargar-pdf")->assertForbidden();
});
