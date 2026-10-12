<?php

use App\Models\ApiConsumer;
use App\Models\Branch;
use App\Models\EfectividadCobranzaRun;
use App\Models\EfectividadCobranzaRunIncident;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Models\PortfolioSnapshot;
use App\Models\User;
use App\Services\Efectividad\EfectividadCobranzaRunPresenter;
use App\Services\Efectividad\V4\EfectividadV4FingerprintService;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EfectividadV4Fixtures as V4;

/**
 * Fase 8 — permisos por endpoint del módulo Efectividad V4 (web + API).
 *
 * Reglas del sistema (no se inventan roles nuevos):
 *  - colaborador: sin acceso a Reportería (EnsureReporteriaAccess → 403).
 *  - gerencial: Reportería global, pero una EJECUCIÓN solo si la solicitó o quedó
 *    suscrito (misma regla que el historial). Un ID ajeno responde 404.
 *  - admin: todas las ejecuciones.
 *  - API: token Sanctum + ability `collections.read`.
 */
function secRun(Branch $branch, User $owner, array $extra = []): EfectividadCobranzaRun
{
    $period = Period::query()->where('type', 'weekly')->firstOrFail();
    Storage::disk('local')->put("efectividad_cobranza/sec_{$owner->id}.xlsx", 'xlsx');
    $run = EfectividadCobranzaRun::query()->create($extra + [
        'period_id' => $period->id, 'branch_id' => $branch->id, 'engine_version' => EfectividadCobranzaRun::currentEngineVersion(),
        'mode' => 'SEMANAL', 'base_date' => '2026-10-04', 'cutoff_date' => '2026-10-11', 'status' => 'success', 'finished_at' => now(),
        'created_by' => $owner->id, 'output_excel_path' => "efectividad_cobranza/sec_{$owner->id}.xlsx",
        'result_rows' => [['tipo' => 'gran_total', 'promotor' => 'GRAN TOTAL', 'sano_cob' => 1.0, 'es_total' => true]],
    ]);
    EfectividadCobranzaRunIncident::query()->create([
        'efectividad_cobranza_run_id' => $run->id, 'position' => 1, 'code' => 'NO-CARTERA', 'incident_key' => 'NO-CARTERA|=CMD|1',
        'contract' => '=HYPERLINK("http://x")', 'description' => 'Contrato sin respaldo: Ñandú «prueba»', 'amount' => -12.5,
        'treatment' => 'Pago excluido del cálculo', 'occurred_on' => '2026-10-05', 'created_at' => now(),
    ]);

    return $run;
}

function secEndpoints(EfectividadCobranzaRun $run): array
{
    return [
        ['get', "/efectividad-cobranza/runs/{$run->id}"],
        ['getJson', "/efectividad-cobranza/runs/{$run->id}/progreso"],
        ['get', "/efectividad-cobranza/runs/{$run->id}/descargar"],
        ['getJson', "/efectividad-cobranza/runs/{$run->id}/incidencias"],
        ['get', "/efectividad-cobranza/runs/{$run->id}/incidencias/exportar"],
    ];
}

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    $this->week = V4::week();
    $this->branch = V4::branch();
    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->gerenteA = User::factory()->create(['role' => 'gerencial']);
    $this->gerenteB = User::factory()->create(['role' => 'gerencial']);
    $this->colaborador = User::factory()->create(['role' => 'colaborador']);
});

it('sin sesión: todos los endpoints redirigen a login', function () {
    $run = secRun($this->branch, $this->gerenteA);
    foreach ([...secEndpoints($run), ['get', '/efectividad-cobranza'], ['getJson', '/efectividad-cobranza/actividad'],
        ['getJson', "/efectividad-cobranza/disponibilidad?period_id={$this->week->id}"], ['getJson', '/efectividad-cobranza/fotos?from=2026-10-01&to=2026-10-31']] as [$m, $url]) {
        $status = $this->{$m}($url)->status();
        expect($status)->toBeIn([302, 401]);
    }
    $this->postJson('/efectividad-cobranza/generar', ['period_id' => $this->week->id])->assertUnauthorized();
    $this->postJson('/efectividad-cobranza/fotos', [])->assertUnauthorized();
});

it('colaborador: 403 en generar, historial, detalle, progreso, XLSX, incidencias, CSV, disponibilidad y fotografías', function () {
    $run = secRun($this->branch, $this->gerenteA);
    $as = $this->actingAs($this->colaborador);
    foreach (secEndpoints($run) as [$m, $url]) {
        $as->{$m}($url)->assertForbidden();
    }
    $as->get('/efectividad-cobranza')->assertForbidden();
    $as->getJson('/efectividad-cobranza/actividad')->assertForbidden();
    $as->getJson("/efectividad-cobranza/disponibilidad?period_id={$this->week->id}")->assertForbidden();
    $as->getJson('/efectividad-cobranza/fotos?from=2026-10-01&to=2026-10-31')->assertForbidden();
    $as->postJson('/efectividad-cobranza/generar', ['period_id' => $this->week->id])->assertForbidden();
    $as->postJson('/efectividad-cobranza/fotos', ['snapshot_date' => '2026-10-05'])->assertForbidden();
    $as->postJson('/efectividad-cobranza/fotos/1/reprocesar')->assertForbidden();
});

it('gerencial: no abre una ejecución ajena cambiando el ID (404 en detalle, progreso, XLSX, incidencias y CSV)', function () {
    $ajena = secRun($this->branch, $this->gerenteB);
    foreach (secEndpoints($ajena) as [$m, $url]) {
        $this->actingAs($this->gerenteA)->{$m}($url)->assertNotFound();
    }
});

it('gerencial: sí abre las propias y aquellas a las que quedó suscrito; admin abre todas', function () {
    $propia = secRun($this->branch, $this->gerenteA);
    $suscrita = secRun($this->branch, $this->gerenteB, ['subscriber_ids' => [$this->gerenteA->id]]);
    foreach ([$propia, $suscrita] as $run) {
        $this->actingAs($this->gerenteA)->getJson("/efectividad-cobranza/runs/{$run->id}/progreso")->assertOk();
        $this->actingAs($this->gerenteA)->get("/efectividad-cobranza/runs/{$run->id}/descargar")->assertOk();
    }
    foreach (secEndpoints($propia) as [$m, $url]) {
        $this->actingAs($this->admin)->{$m}($url)->assertOk();
    }
});

it('gerencial: seleccionar el periodo NO enfoca la ejecución de otra persona', function () {
    $ajena = secRun($this->branch, $this->gerenteB);

    $this->actingAs($this->gerenteA)->get("/efectividad-cobranza?period_id={$this->week->id}&branch_id={$this->branch->id}")
        ->assertOk()->assertInertia(fn ($page) => $page->where('focusRun', null));
    $this->actingAs($this->admin)->get("/efectividad-cobranza?period_id={$this->week->id}&branch_id={$this->branch->id}")
        ->assertInertia(fn ($page) => $page->where('focusRun.id', $ajena->id));
});

it('CSV de incidencias: UTF-8 con BOM, escape correcto y sin inyección de fórmulas', function () {
    $run = secRun($this->branch, $this->admin);
    $csv = $this->actingAs($this->admin)->get("/efectividad-cobranza/runs/{$run->id}/incidencias/exportar")->assertOk()->streamedContent();

    expect(substr($csv, 0, 3))->toBe("\xEF\xBB\xBF");
    $rows = array_map('str_getcsv', array_filter(explode("\n", substr($csv, 3))));
    expect($rows[0])->toBe(['#', 'ID', 'Código', 'Contrato', 'Fecha reconocimiento', 'Importe', 'Motivo', 'Tratamiento', 'Acción recomendada'])
        ->and($rows[1][1])->toBe('NO-CARTERA|=CMD|1')
        ->and($rows[1][3])->toBe('\'=HYPERLINK("http://x")')   // neutralizado
        ->and($rows[1][4])->toBe('05/10/2026')
        ->and($rows[1][5])->toBe('-12.50')                          // el importe negativo sigue siendo número
        ->and($rows[1][6])->toBe('Contrato sin respaldo: Ñandú «prueba»');
});

it('el XLSX vive en el disco privado (local), nunca en public/storage', function () {
    Queue::fake();
    $run = secRun($this->branch, $this->admin);
    $path = app(EfectividadCobranzaRunPresenter::class)->resolveExcelAbsolutePath($run);

    expect($path)->toBe(Storage::disk('local')->path($run->output_excel_path))
        ->and(Storage::disk('public')->exists($run->output_excel_path))->toBeFalse()
        ->and(config('filesystems.disks.local.root'))->not->toContain('public');
});

it('una ejecución fallida no expone trazas ni rutas internas en progreso (gerencial sin referencia técnica)', function () {
    $run = secRun($this->branch, $this->gerenteA, ['status' => 'failed', 'error_message' => 'Se produjo un error durante la generación (etapa «Excel»).',
        'metadata' => ['last_error_reference' => 'RuntimeException'], 'output_excel_path' => null]);
    $json = $this->actingAs($this->gerenteA)->getJson("/efectividad-cobranza/runs/{$run->id}/progreso")->assertOk()->json();

    $texto = json_encode($json);
    expect($json['technical_reference'])->toBeNull()
        ->and($texto)->not->toContain('#0 ')->not->toContain('vendor')->not->toContain('storage\\\\app')->not->toContain('/storage/app');
    $this->actingAs($this->gerenteA)->get("/efectividad-cobranza/runs/{$run->id}/descargar")->assertNotFound();
});

// ── API (Sanctum) ──────────────────────────────────────────────────────────

function secToken(array $abilities): string
{
    $consumer = ApiConsumer::query()->create(['name' => 'Sec', 'system_code' => 'sec-'.uniqid(), 'is_active' => true, 'abilities' => $abilities]);

    return $consumer->createToken('t', $abilities)->plainTextToken;
}

it('API: sin token 401; token sin ability collections.read 403; con ability 200', function () {
    $url = "/api/v1/collections/effectiveness?period_id={$this->week->id}";
    $this->getJson($url)->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer '.secToken(['financial.read']))->getJson($url)->assertForbidden();
    $this->app['auth']->forgetGuards(); // el guard memoriza al consumidor anterior dentro de la prueba
    $this->withHeader('Authorization', 'Bearer '.secToken(['collections.read']))->getJson($url)->assertOk()
        ->assertJsonPath('versions.approved.version', 'approved_snapshot_v4');
});

it('API: un promotor homónimo dentro de la misma sucursal NO se atribuye (not_attributable)', function () {
    $employee = Employee::query()->create(['full_name' => 'LUIS GOMEZ', 'normalized_name' => 'luis gomez', 'is_active' => true, 'source_system' => 'lendus_cobranza']);
    EmployeeBranchAssignment::query()->create(['period_id' => $this->week->id, 'employee_id' => $employee->id, 'branch_id' => $this->branch->id,
        'source_type' => 'lendus', 'match_type' => 'normalized', 'confidence' => '0.90', 'was_manual_reviewed' => false]);
    $fp = app(EfectividadV4FingerprintService::class)->compute($this->week, $this->branch->id, 'SEMANAL', null);
    secRun($this->branch, $this->admin, ['source_fingerprint' => $fp, 'result_rows' => [
        ['tipo' => 'promotor', 'oficina' => 'CORDOBA', 'promotor' => 'LUIS GOMEZ', 'sano_cob' => 10.0, 'es_total' => false],
        ['tipo' => 'promotor', 'oficina' => 'CORDOBA NORTE', 'promotor' => 'Luis Gómez', 'sano_cob' => 20.0, 'es_total' => false],
    ]]);

    $json = $this->withHeader('Authorization', 'Bearer '.secToken(['collections.read']))
        ->getJson("/api/v1/collections/effectiveness?period_id={$this->week->id}&scope=employee&employee_id={$employee->id}")->assertOk()->json('versions.approved');

    expect($json['available'])->toBeFalse()
        ->and($json['status'])->toBe('not_attributable')
        ->and($json['reason'])->toContain('homonimia')->toContain('CORDOBA NORTE');
});

it('API: si cambian las fuentes (nueva fotografía) el snapshot V4 se entrega como stale, nunca como ok', function () {
    V4::seedValid($this->week, $this->branch);
    $fp = app(EfectividadV4FingerprintService::class)->compute($this->week, $this->branch->id, 'SEMANAL', null);
    secRun($this->branch, $this->admin, ['source_fingerprint' => $fp]);
    $token = secToken(['collections.read']);
    $url = "/api/v1/collections/effectiveness?period_id={$this->week->id}&scope=branch&branch_id={$this->branch->id}";

    $this->withHeader('Authorization', "Bearer {$token}")->getJson($url)->assertJsonPath('versions.approved.status', 'ok');

    // Se sustituye la fotografía del miércoles (nueva carga → nuevo snapshot activo).
    $old = PortfolioSnapshot::query()->whereDate('snapshot_date', '2026-10-07')->sole();
    $nuevo = $old->replicate()->fill(['imported_at' => now()->addMinute()]);
    $nuevo->save();
    $old->update(['status' => PortfolioSnapshot::STATUS_REPLACED, 'replaced_by_id' => $nuevo->id]);

    $this->withHeader('Authorization', "Bearer {$token}")->getJson($url)
        ->assertJsonPath('versions.approved.status', 'stale')
        ->assertJsonPath('versions.approved.is_stale', true);
});
