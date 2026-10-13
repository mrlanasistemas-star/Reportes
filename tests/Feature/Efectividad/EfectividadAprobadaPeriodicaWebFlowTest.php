<?php

use App\Jobs\GenerateEfectividadAprobadaPeriodicaJob;
use App\Models\Branch;
use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Models\Portfolio;
use App\Models\Recovery;
use App\Models\User;
use App\Services\Efectividad\V4\EfectividadV4Calculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\EfectividadV4Fixtures as V4;

uses(RefreshDatabase::class);

/**
 * Rediseño premium (oct-2026) — reconexión del motor Aprobado anterior como
 * "Periódica": la operación real del usuario (Saldos por Cliente + Ingresos
 * Cobranza cada 4 semanas, sin fotografías diarias) ahora SÍ genera un reporte
 * real por HTTP, en vez de bloquear pidiendo 8 fotos que nunca va a subir.
 */
function periodicaMonth(): Period
{
    return Period::query()->create([
        'name' => 'Periodo Operativo', 'code' => 'M-PERIODICA-2026-9', 'type' => 'monthly',
        'year' => 2026, 'month' => 9, 'sequence' => 1,
        'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'is_closed' => false,
    ]);
}

it('a manager generates a real Periódica report for a monthly period via HTTP — no daily photos involved — and downloads the Excel', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $period = periodicaMonth();
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

    $gen = $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', [
        'period_id' => $period->id, 'branch_id' => $branch->id,
    ])->assertOk()->json();
    expect($gen['accepted'])->toBeTrue();
    expect($gen['run']['engine_version'])->toBe(EfectividadCobranzaRun::ENGINE_APROBADA_2026_10);
    expect($gen['run']['is_periodica_engine'])->toBeTrue();
    expect($gen['run']['is_current_engine'])->toBeTrue(); // vigente, no "obsoleto"

    $run = EfectividadCobranzaRun::query()->findOrFail($gen['run_id']);
    expect($run->status)->toBe('success')->and($run->result_rows)->not->toBeEmpty();

    $prog = $this->actingAs($admin)->getJson("/efectividad-cobranza/runs/{$run->id}/progreso")->assertOk()->json();
    expect($prog['report'])->toContain('Aprobada')
        ->and($prog['engine_label'])->toContain('Aprobada')
        ->and($prog['has_excel'])->toBeTrue()
        ->and($prog['stage_count'])->toBe(4); // 4 etapas de la Periódica, no las 9 de V4

    $download = $this->actingAs($admin)->get("/efectividad-cobranza/runs/{$run->id}/descargar")->assertOk();
    expect(Storage::disk('local')->exists($run->output_excel_path))->toBeTrue();
    $book = IOFactory::load(Storage::disk('local')->path($run->output_excel_path));
    expect($book->getSheetNames())->toContain('EFECTIVIDAD');

    // Aparece correctamente en el historial, nunca etiquetado como V4 ni como obsoleto.
    $this->actingAs($admin)->get('/efectividad-cobranza')->assertInertia(fn ($page) => $page
        ->where('history.data.0.id', $run->id)
        ->where('history.data.0.is_current_engine', true)
        ->where('history.data.0.display_state', 'success'));
});

it('a monthly period routes to Periódica, a weekly period still routes to V4 — same endpoint, different engine by period type', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $weekly = V4::week();
    V4::branch();
    V4::seedValid($weekly, V4::branch());

    $v4Response = $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', ['period_id' => $weekly->id])->assertOk()->json();
    $v4Run = EfectividadCobranzaRun::query()->findOrFail($v4Response['run_id']);
    expect($v4Run->engine_version)->toBe(EfectividadV4Calculator::ENGINE_VERSION);

    $monthly = periodicaMonth();
    $periodicaResponse = $this->actingAs($admin)->postJson('/efectividad-cobranza/generar', ['period_id' => $monthly->id])->assertOk()->json();
    $periodicaRun = EfectividadCobranzaRun::query()->findOrFail($periodicaResponse['run_id']);
    expect($periodicaRun->engine_version)->toBe(EfectividadCobranzaRun::ENGINE_APROBADA_2026_10);
});

it('cancellation works on the Periódica Job the same way it works on V4: cooperative, atomic, never a partial success', function () {
    $period = periodicaMonth();
    $branch = Branch::query()->create(['code' => 'COR', 'name' => 'CORDOBA', 'normalized_name' => 'cordoba', 'is_active' => true]);
    Portfolio::query()->create([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'contract' => '25COR1270278', 'promoter_name' => 'LUIS GOMEZ',
        'status' => 'VIGENTE', 'substatus' => 'VIGENTE', 'installment_amount' => 100, 'periodicity' => 'SEMANAL', 'num_payments' => 10,
        'first_payment_date' => '2026-09-05',
    ]);
    Recovery::query()->create([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'contract' => '25COR1270278', 'promoter_name' => 'LUIS GOMEZ',
        'operation' => 'PAGO A CONTRATO', 'transaction' => 'PAGO', 'capital' => 100, 'total_amount' => 100,
        'payment_date' => '2026-09-05', 'installment_due_date' => '2026-09-05',
    ]);

    $run = EfectividadCobranzaRun::query()->create([
        'period_id' => $period->id, 'branch_id' => $branch->id, 'status' => EfectividadCobranzaRun::STATUS_QUEUED,
        'queued_at' => now(), 'log' => 'En cola.',
    ]);
    $run->forceFill(['cancel_requested_at' => now(), 'cancelled_by' => 1])->save();

    app()->call([new GenerateEfectividadAprobadaPeriodicaJob($run->id), 'handle']);

    $run->refresh();
    expect($run->status)->toBe(EfectividadCobranzaRun::STATUS_CANCELLED)
        ->and($run->output_excel_path)->toBeNull()
        ->and($run->result_rows)->toBe([]);
});
