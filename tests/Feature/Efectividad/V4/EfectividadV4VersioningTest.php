<?php

use App\Jobs\GenerateEfectividadCobranzaAprobadaJob;
use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Models\PortfolioSnapshot;
use App\Models\Recovery;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\Efectividad\V4\EfectividadV4ExcelExporter;
use App\Services\ReportAnalysisService;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EfectividadV4Fixtures as V4;
use Tests\Support\EfectividadV4Reference as Ref;

/**
 * Fases 9-10 — versionado, vigencia y concurrencia del motor V4 con CAMBIOS
 * REALES de archivos (importadores reales sobre XLSX sintéticos), no con
 * estados alterados en memoria.
 */
const VER_FOTO_HEADERS = ['Contrato', 'Acreditado', 'Oficina', 'Nombre promotor', 'Promotor', 'Substatus', 'Estatus', 'Producto de credito',
    'Fecha primer pago', 'Fecha vencimiento', 'Cuota total', 'Periodicidad', 'Pagos', 'Total atrasado', 'Pagos atrasados', 'Total pendiente',
    'Saldo capital', 'Fecha última transacción'];

const VER_ING_HEADERS = ['Oficina', 'Promotor', 'Contrato', 'Acreditado', 'Operación', 'Transacción', 'Fecha transacción', 'Fecha cuota',
    'Fecha vencimiento', 'Capital', 'Interés', 'Impuesto', 'Cargos calendario', 'Producto de crédito', 'Concepto', 'Número cuota', 'Total'];

function verFoto(string $fecha, float $cuota = 100.0): void
{
    $rows = array_map(fn ($c) => [$c, 'COR1', 'CORDOBA', 'LUIS GOMEZ', 'COR-P1', 'VIGENTE', 'ACTIVO', 'S12', ['__d' => '2026-10-05'],
        ['__d' => '2026-12-21'], $cuota, 'SEMANAL', 12, 0, 0, 1100, 900, ['__d' => $fecha]], V4::CONTRATOS);
    $rel = 'reports/ver_foto_'.$fecha.'_'.uniqid().'.xlsx';
    Ref::writeXlsx([VER_FOTO_HEADERS, ...$rows], Storage::disk('public')->path($rel), 4);
    app(ReportAnalysisService::class)->analyze(Ref::upload('lendus_saldos_cliente_foto', Period::query()->where('type', 'weekly')->first(), $rel, $fecha));
}

/** @return ReportUpload */
function verIngresos(array $pagos)
{
    $rows = array_map(fn ($p) => ['CORDOBA', 'LUIS GOMEZ', $p[0], 'COR1', 'PAGO A CONTRATO', 'PAGO', ['__d' => $p[1]], ['__d' => '2026-10-05'],
        ['__d' => '2026-12-21'], $p[2], 0, 0, 0, 'S12', 'PRINCIPAL', 1, $p[2]], $pagos);
    $rel = 'reports/ver_ing_'.uniqid().'.xlsx';
    Ref::writeXlsx([VER_ING_HEADERS, ...$rows], Storage::disk('public')->path($rel), 3);
    $upload = Ref::upload('lendus_ingresos_cobranza', Period::query()->where('type', 'weekly')->first(), $rel);
    app(ReportAnalysisService::class)->analyze($upload);

    return $upload->fresh();
}

function verSemanaCompleta(): void
{
    foreach (range(0, 7) as $i) {
        verFoto(date('Y-m-d', strtotime("2026-10-04 +{$i} days")));
    }
    verIngresos([[V4::CONTRATOS[0], '2026-10-05', 100], [V4::CONTRATOS[1], '2026-10-06', 100], [V4::CONTRATOS[2], '2026-10-07', 50], [V4::CONTRATOS[3], '2026-10-08', 100]]);
}

function verGenerar(User $user, Period $week, array $extra = []): array
{
    return test()->actingAs($user)->postJson('/efectividad-cobranza/generar', ['period_id' => $week->id] + $extra)->assertOk()->json();
}

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
    Storage::disk('public')->makeDirectory('reports');
    Mail::fake();
    $this->week = V4::week();
    V4::branch();
    $this->admin = User::factory()->create(['role' => 'admin']);
});

it('importadores reales → generación V4 → resultado vigente con versión, modo, corte, fuentes y huella', function () {
    verSemanaCompleta();
    expect(Recovery::query()->count())->toBe(4);

    $gen = verGenerar($this->admin, $this->week);
    $run = EfectividadCobranzaRun::query()->findOrFail($gen['run_id']);
    $det = $this->actingAs($this->admin)->getJson("/efectividad-cobranza/runs/{$run->id}/progreso")->assertOk()->json();

    expect($run->status)->toBe('success')
        ->and($run->engine_version)->toBe('mrlana-v4-2026-10')
        ->and($run->mode)->toBe('SEMANAL')
        ->and($run->base_date->format('Y-m-d'))->toBe('2026-10-04')
        ->and($run->cutoff_date->format('Y-m-d'))->toBe('2026-10-11')
        ->and($run->branch_id)->toBeNull()
        ->and($run->source_fingerprint)->toHaveLength(64)
        ->and(collect($run->sources['fotos'])->pluck('estado')->unique()->all())->toBe(['disponible'])
        ->and($run->sources['ingresos']['cargas'])->toHaveCount(1)
        ->and($det['is_stale'])->toBeFalse()
        ->and($det['display_state'])->toBe('success')
        ->and(collect($det['rows'])->firstWhere('promotor', 'GRAN TOTAL')['cobranza_total'])->toEqual(350.0);
});

it('sustituir una fotografía (nueva carga real de la misma fecha) deja el resultado OBSOLETO', function () {
    verSemanaCompleta();
    $run = EfectividadCobranzaRun::query()->findOrFail(verGenerar($this->admin, $this->week)['run_id']);

    verFoto('2026-10-07', 120.0); // se vuelve a descargar la cartera del miércoles

    expect(PortfolioSnapshot::query()->whereDate('snapshot_date', '2026-10-07')->where('status', 'replaced')->count())->toBe(1);
    $det = $this->actingAs($this->admin)->getJson("/efectividad-cobranza/runs/{$run->id}/progreso")->json();
    expect($det['is_stale'])->toBeTrue()
        ->and(collect($det['issues'])->pluck('code'))->toContain('EC-VIG-FUENTES-CAMBIARON');

    // La regeneración es una ejecución NUEVA; la anterior se conserva y queda como obsoleta.
    $nuevo = EfectividadCobranzaRun::query()->findOrFail(verGenerar($this->admin, $this->week)['run_id']);
    expect($nuevo->id)->not->toBe($run->id)
        ->and($nuevo->source_fingerprint)->not->toBe($run->source_fingerprint)
        ->and(EfectividadCobranzaRun::query()->count())->toBe(2);
    $viejo = $this->actingAs($this->admin)->getJson("/efectividad-cobranza/runs/{$run->id}/progreso")->json();
    expect($viejo['display_state'])->toBe('obsolete')->and($viejo['superseded_by_id'])->toBe($nuevo->id);
});

it('reprocesar o cargar más Ingresos Cobranza deja el resultado OBSOLETO', function () {
    verSemanaCompleta();
    $run = EfectividadCobranzaRun::query()->findOrFail(verGenerar($this->admin, $this->week)['run_id']);

    verIngresos([[V4::CONTRATOS[2], '2026-10-09', 50]]); // un pago nuevo del viernes

    expect($this->actingAs($this->admin)->getJson("/efectividad-cobranza/runs/{$run->id}/progreso")->json('is_stale'))->toBeTrue();
});

it('cargar fotografías de OTRA semana no invalida el resultado vigente', function () {
    verSemanaCompleta();
    $run = EfectividadCobranzaRun::query()->findOrFail(verGenerar($this->admin, $this->week)['run_id']);
    Period::query()->create(['name' => 'Semana 42', 'code' => 'W-V4-2026-42', 'type' => 'weekly', 'year' => 2026, 'month' => 10, 'sequence' => 42, 'start_date' => '2026-10-12', 'end_date' => '2026-10-18']);

    verFoto('2026-10-12');

    expect($this->actingAs($this->admin)->getJson("/efectividad-cobranza/runs/{$run->id}/progreso")->json('is_stale'))->toBeFalse();
});

it('SEMANAL, DIARIO martes y DIARIO miércoles son identidades distintas: tres ejecuciones, sin reutilización cruzada', function () {
    Queue::fake();
    verSemanaCompleta();

    $semanal = verGenerar($this->admin, $this->week);
    $martes = verGenerar($this->admin, $this->week, ['mode' => 'DIARIO', 'cutoff_date' => '2026-10-06']);
    $miercoles = verGenerar($this->admin, $this->week, ['mode' => 'DIARIO', 'cutoff_date' => '2026-10-07']);
    $martesOtraVez = verGenerar($this->admin, $this->week, ['mode' => 'DIARIO', 'cutoff_date' => '2026-10-06']);

    expect(collect([$semanal, $martes, $miercoles])->pluck('run_id')->unique())->toHaveCount(3)
        ->and($martesOtraVez['run_id'])->toBe($martes['run_id'])
        ->and($martesOtraVez['reused'])->toBeTrue();
    Queue::assertPushed(GenerateEfectividadCobranzaAprobadaJob::class, 3);

    $runs = EfectividadCobranzaRun::query()->orderBy('id')->get();
    expect($runs->pluck('mode')->all())->toBe(['SEMANAL', 'DIARIO', 'DIARIO'])
        ->and($runs->map(fn ($r) => $r->cutoff_date->format('Y-m-d'))->all())->toBe(['2026-10-11', '2026-10-06', '2026-10-07']);
});

it('DIARIO real: resultado del día con su identidad; no se presenta como el SEMANAL', function () {
    verSemanaCompleta();
    $diario = EfectividadCobranzaRun::query()->findOrFail(verGenerar($this->admin, $this->week, ['mode' => 'DIARIO', 'cutoff_date' => '2026-10-06'])['run_id']);
    $semanal = EfectividadCobranzaRun::query()->findOrFail(verGenerar($this->admin, $this->week)['run_id']);

    $dia = collect($diario->result_rows)->firstWhere('promotor', 'GRAN TOTAL');
    expect($diario->status)->toBe('success')
        ->and($diario->metadata['parametros']['inicio_ingresos'])->toBe('2026-10-06')
        ->and($dia['sano_pc'])->toEqual(0.0)  // cuota semanal: el martes no tiene cuota programada
        ->and($dia['atraso_cob'])->toEqual(100.0);
    // El semanal posterior NO marca el diario como reemplazado (identidades distintas).
    expect($this->actingAs($this->admin)->getJson("/efectividad-cobranza/runs/{$diario->id}/progreso")->json('display_state'))->toBe('success')
        ->and($semanal->id)->toBeGreaterThan($diario->id);
});

it('Excel perdido después de generar: has_excel=false y descarga 404 (nunca un archivo vacío)', function () {
    verSemanaCompleta();
    $run = EfectividadCobranzaRun::query()->findOrFail(verGenerar($this->admin, $this->week)['run_id']);
    Storage::disk('local')->delete($run->output_excel_path);

    $this->actingAs($this->admin)->getJson("/efectividad-cobranza/runs/{$run->id}/progreso")->assertJsonPath('has_excel', false);
    $this->actingAs($this->admin)->get("/efectividad-cobranza/runs/{$run->id}/descargar")->assertNotFound();
});

it('si el exportador no produce un XLSX el Job termina en error técnico, nunca en success', function () {
    verSemanaCompleta();
    app()->bind(EfectividadV4ExcelExporter::class, fn () => new class extends EfectividadV4ExcelExporter
    {
        public function export(array $filas, array $parametros, array $incidencias, string $outputPath, array $fuentes = [], string $engineVersion = ''): string
        {
            return $outputPath; // no escribe nada
        }
    });

    $run = EfectividadCobranzaRun::query()->create(['period_id' => $this->week->id, 'engine_version' => EfectividadCobranzaRun::currentEngineVersion(),
        'mode' => 'SEMANAL', 'status' => 'queued', 'created_by' => $this->admin->id, 'metadata' => ['confirmed_partial' => true]]);
    foreach ([1, 2] as $intento) {
        $job = new GenerateEfectividadCobranzaAprobadaJob($run->id);
        $job->setJob(new class($intento) extends SyncJob
        {
            public function __construct(private int $n)
            {
                parent::__construct(app(), '{}', 'sync', 'default');
            }

            public function attempts()
            {
                return $this->n;
            }
        });
        try {
            app()->call([$job, 'handle']);
        } catch (RuntimeException) {
        }
    }

    $run->refresh();
    expect($run->status)->toBe('failed')
        ->and($run->failure_kind)->toBe('technical')
        ->and($run->output_excel_path)->toBeNull()
        ->and($run->error_message)->toContain('Excel');
});

it('una fotografía faltante bloquea con la fecha exacta; al cargarla, la nueva generación sí termina', function () {
    foreach ([0, 1, 2, 4, 5, 6, 7] as $i) { // falta el miércoles
        verFoto(date('Y-m-d', strtotime("2026-10-04 +{$i} days")));
    }
    verIngresos([[V4::CONTRATOS[0], '2026-10-05', 100], [V4::CONTRATOS[1], '2026-10-06', 100], [V4::CONTRATOS[2], '2026-10-07', 50], [V4::CONTRATOS[3], '2026-10-08', 100]]);

    $bloq = EfectividadCobranzaRun::query()->findOrFail(verGenerar($this->admin, $this->week)['run_id']);
    expect($bloq->status)->toBe('blocked')
        ->and(collect($bloq->issues)->firstWhere('code', 'EC-V4-FOTO-FALTANTE')['message'])->toContain('CARTERA MIERCOLES')->toContain('07/10/2026')
        ->and($bloq->output_excel_path)->toBeNull();

    verFoto('2026-10-07');
    $ok = EfectividadCobranzaRun::query()->findOrFail(verGenerar($this->admin, $this->week)['run_id']);
    expect($ok->id)->not->toBe($bloq->id)->and($ok->status)->toBe('success');
});
