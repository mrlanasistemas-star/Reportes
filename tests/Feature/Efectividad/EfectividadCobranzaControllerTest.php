<?php

use App\Models\Branch;
use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Models\Recovery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\EfectividadV4Fixtures as V4;

uses(RefreshDatabase::class);

/**
 * Fase 8-11 — confirma el recorrido REAL end-to-end descrito por el usuario:
 * entrar al módulo, seleccionar periodo, generar, ver resultados, descargar
 * Excel — todo vía HTTP, nunca solo verificando servicios PHP aislados.
 */
/** Motor V4: semana operativa lunes-domingo (05-11/10/2026). */
function makeControllerTestPeriodo(): Period
{
    return V4::week();
}

it('a manager can open the module, generate a real report, and download the Excel — full HTTP round-trip', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = makeControllerTestPeriodo();
    $branch = V4::branch();
    V4::seedValid($period, $branch);

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
    expect($progressResponse->json('coverage.filas_fotos'))->toBe(32);
    expect($progressResponse->json('has_excel'))->toBeTrue();
    expect($progressResponse->json('engine_version'))->toBe('mrlana-v4-2026-10');
    expect($progressResponse->json('parameters.domingo_base'))->toBe('2026-10-04');

    $granTotal = collect($progressResponse->json('rows'))->firstWhere('promotor', 'GRAN TOTAL');
    expect($granTotal)->not->toBeNull();
    expect((float) $granTotal['sano_cob'])->toBe(100.0);
    expect((float) $granTotal['pct_total'])->toBe(0.875);

    // 4. Descargar — el archivo Excel generado debe existir y ser un XLSX real.
    $run = EfectividadCobranzaRun::query()->findOrFail($runId);
    $downloadResponse = $this->actingAs($admin)->get("/efectividad-cobranza/runs/{$runId}/descargar");
    $downloadResponse->assertOk();
    // Fase 10 (auditoría 2026-10-12) — output_excel_path ahora es una ruta RELATIVA
    // al disco privado 'local' (storage/app/private), no una ruta absoluta.
    expect(Storage::disk('local')->exists($run->output_excel_path))->toBeTrue();
    expect(Storage::disk('local')->size($run->output_excel_path))->toBeGreaterThan(0);

    // XLSX válido sin macros, con la hoja EFECTIVIDAD, la auditoría y las fuentes.
    $book = IOFactory::load(Storage::disk('local')->path($run->output_excel_path));
    expect($book->getSheetNames())->toBe(['EFECTIVIDAD', '_AUDITORIA_EFECTIVIDAD', 'FUENTES']);
    expect($book->hasMacros())->toBeFalse();
});

it('the branch selector only offers the 13 operative branches, never a raw Lendus route name that leaked into the branches table', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = makeControllerTestPeriodo();
    V4::branch(); // CORDOBA — real operative branch, must appear
    Branch::query()->create(['name' => 'ATLIX-GTE', 'normalized_name' => 'atlix-gte', 'code' => 'ATLIX_GTE_LEGACY', 'is_active' => true]);

    $response = $this->actingAs($admin)->get("/efectividad-cobranza?period_id={$period->id}");

    $response->assertOk();
    $response->assertInertia(function ($page) {
        $names = collect($page->toArray()['props']['branches'])->pluck('name');
        expect($names)->toContain('CORDOBA')
            ->and($names)->not->toContain('ATLIX-GTE')
            ->and($names->all())->toBe($names->sort()->values()->all());

        return $page->component('EfectividadCobranza/Index');
    });
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
        'engine_version' => 'mrlana-v4-2026-10', 'mode' => 'SEMANAL', 'base_date' => '2026-10-04', 'cutoff_date' => '2026-10-11',
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
    $lock = Cache::lock('efectividad-cobranza-generate-'.$period->id.'-general-SEMANAL-2026-10-11', 15);
    expect($lock->get())->toBeTrue();

    try {
        $response = $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', [
            'period_id' => $period->id,
            'confirm_partial' => true,
        ]);

        $response->assertStatus(409);
        expect($response->json('requires_confirmation'))->toBeNull();
        expect(EfectividadCobranzaRun::query()->where('period_id', $period->id)->count())->toBe(0);
    } finally {
        $lock->release();
    }
});

it('Fase 5 (auditoría 2026-10-12): status=blocked (not success) when there is no usable data, no Excel is produced, and the UI never gets a download link', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = makeControllerTestPeriodo();

    // Sin fotografías ni ingresos: faltan fuentes indispensables → se encola y queda bloqueado
    // (no se pide confirmación de semana: el bloqueo por fotografías manda).
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

it('V4: con fotografías completas pero Ingresos de pocos días pide confirmar la semana (como la macro) y solo encola tras confirmar', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = makeControllerTestPeriodo();
    $branch = V4::branch();
    V4::snapshots($period, $branch);
    // Solo movimientos del lunes (1 día con movimientos ≤ 3).
    Recovery::query()->create([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'contract' => V4::CONTRATOS[0], 'promoter_name' => 'LUIS GOMEZ',
        'office_name_raw' => 'CORDOBA', 'operation' => 'PAGO A CONTRATO', 'transaction' => 'PAGO', 'capital' => 100, 'interest' => 0,
        'tax' => 0, 'total_amount' => 100, 'payment_date' => '2026-10-05', 'installment_due_date' => '2026-10-05',
    ]);

    $first = $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', ['period_id' => $period->id, 'branch_id' => $branch->id]);
    $first->assertStatus(409)->assertJson(['requires_confirmation' => true, 'accepted' => false]);
    expect($first->json('message'))->toContain('05/10/2026');
    expect(EfectividadCobranzaRun::query()->count())->toBe(0);

    $second = $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', ['period_id' => $period->id, 'branch_id' => $branch->id, 'confirm_partial' => true]);
    $second->assertOk()->assertJson(['accepted' => true]);
    $run = EfectividadCobranzaRun::query()->findOrFail($second->json('run_id'));
    expect($run->status)->toBe('success')->and($run->metadata['confirmed_partial'])->toBeTrue();
});

it('V4: modo DIARIO calcula solo el día de corte, con su propia identidad', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = makeControllerTestPeriodo();
    $branch = V4::branch();
    V4::seedValid($period, $branch);

    $res = $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', [
        'period_id' => $period->id, 'branch_id' => $branch->id, 'mode' => 'DIARIO', 'cutoff_date' => '2026-10-05',
    ]);
    $res->assertOk();
    $run = EfectividadCobranzaRun::query()->findOrFail($res->json('run_id'));
    expect($run->status)->toBe('success')->and($run->mode)->toBe('DIARIO')->and($run->cutoff_date->format('Y-m-d'))->toBe('2026-10-05');
    // El lunes vencen las 4 cuotas (400); solo A paga ese día (100) → 25%.
    $gt = collect($run->result_rows)->firstWhere('tipo', 'gran_total');
    expect($gt['sano_pc'])->toEqual(400.0)->and($gt['cobranza_total'])->toEqual(100.0)->and($gt['pct_total'])->toEqual(0.25);
    expect($run->metadata['parametros']['inicio_ingresos'])->toBe('2026-10-05')->and($run->metadata['parametros']['fin_ingresos'])->toBe('2026-10-05');

    // Diario sin fecha de corte → 422 de validación.
    $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', ['period_id' => $period->id, 'mode' => 'DIARIO'])->assertStatus(422);
});

it('V4: disponibilidad e incidencias por HTTP (consulta paginada y exportación CSV)', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = makeControllerTestPeriodo();
    $branch = V4::branch();
    V4::seedValid($period, $branch);
    // Pago de un contrato sin respaldo en fotografías → incidencia NO-CARTERA (no entra al cálculo).
    Recovery::query()->create([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'contract' => '26ORI0687909', 'promoter_name' => 'OTRA',
        'office_name_raw' => 'OTRA RUTA', 'operation' => 'PAGO A CONTRATO', 'transaction' => 'PAGO', 'capital' => 77, 'interest' => 0,
        'tax' => 0, 'total_amount' => 77, 'payment_date' => '2026-10-06', 'installment_due_date' => '2026-10-06',
    ]);

    $this->actingAs($admin)->getJson("/efectividad-cobranza/disponibilidad?period_id={$period->id}")
        ->assertOk()->assertJson(['supported' => true, 'fotos_disponibles' => 8, 'fotos_requeridas' => 8, 'bloqueante' => false])
        ->assertJsonPath('fotos.0.nombre', 'CARTERA DOMINGO')->assertJsonPath('fotos.7.nombre', 'CARTERA DOMINGO CORTE');

    $runId = $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', ['period_id' => $period->id])->json('run_id');
    $run = EfectividadCobranzaRun::query()->findOrFail($runId);
    expect($run->status)->toBe('success')->and($run->incidents_count)->toBe(1);
    expect(collect($run->result_rows)->firstWhere('tipo', 'gran_total')['cobranza_total'])->toEqual(350.0); // los 77 no se forzaron

    $json = $this->actingAs($admin)->getJson("/efectividad-cobranza/runs/{$runId}/incidencias")->assertOk()->json();
    expect($json['meta']['total'])->toBe(1)
        ->and($json['data'][0]['code'])->toBe('NO-CARTERA')
        ->and($json['data'][0]['contract'])->toBe('26ORI0687909')
        ->and($json['data'][0]['status'])->toBe('Pago excluido del cálculo')
        ->and($json['data'][0]['amount'])->toEqual(77.0)
        ->and($json['summary'][0]['cantidad'])->toBe(1);
    expect($this->actingAs($admin)->getJson("/efectividad-cobranza/runs/{$runId}/incidencias?code=DUP")->json('meta.total'))->toBe(0);

    $csv = $this->actingAs($admin)->get("/efectividad-cobranza/runs/{$runId}/incidencias/exportar");
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('NO-CARTERA')->toContain('26ORI0687909');

    $colaborador = User::factory()->create(['role' => 'colaborador']);
    $this->actingAs($colaborador)->getJson("/efectividad-cobranza/runs/{$runId}/incidencias")->assertForbidden();
    $this->actingAs($colaborador)->getJson("/efectividad-cobranza/disponibilidad?period_id={$period->id}")->assertForbidden();
});
