<?php

use App\Mail\EfectividadCobranzaRunMail;
use App\Models\DataSource;
use App\Models\EfectividadCobranzaRun;
use App\Models\Period;
use App\Models\Recovery;
use App\Models\ReportUpload;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\EfectividadV4Fixtures as V4;
use Tests\Support\EfectividadV4Reference as Ref;

/**
 * Fase 7 — recorrido web COMPLETO por HTTP, con las rutas reales que usa la UI:
 * login → carga de 8 fotografías (Disponibilidad de información) → carga de
 * Ingresos Cobranza (Histórico General) → disponibilidad → generar → 9 etapas
 * → salir a otro módulo → regresar (estado restaurado desde BD) → resultado →
 * incidencias → CSV → XLSX → historial → notificación interna → correo.
 */
function flowXlsx(array $rows, int $titulos = 0): UploadedFile
{
    $tmp = tempnam(sys_get_temp_dir(), 'flow').'.xlsx';
    Ref::writeXlsx($rows, $tmp, $titulos);

    return new UploadedFile($tmp, basename($tmp), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

it('un usuario gerencial opera el módulo de punta a punta desde la web', function () {
    Storage::fake('public');
    Storage::fake('local');
    Mail::fake();
    $week = V4::week();
    V4::branch();
    $user = User::factory()->create(['role' => 'gerencial', 'email' => 'gerente@example.test', 'password' => bcrypt('secreto-123')]);
    DataSource::query()->firstOrCreate(['code' => 'lendus_ingresos_cobranza'], ['name' => 'Ingresos Cobranza', 'is_active' => true]);
    DataSource::query()->firstOrCreate(['code' => 'lendus_saldos_cliente_foto'], ['name' => 'Fotografía de cartera', 'is_active' => true]);

    // 1. Iniciar sesión.
    $this->post('/login', ['email' => 'gerente@example.test', 'password' => 'secreto-123'])->assertRedirect();
    $this->assertAuthenticatedAs($user);

    // 2-7. Ocho fotografías con su fecha (domingo base … domingo corte).
    $headers = ['Contrato', 'Acreditado', 'Oficina', 'Nombre promotor', 'Promotor', 'Substatus', 'Estatus', 'Producto de credito', 'Fecha primer pago',
        'Fecha vencimiento', 'Cuota total', 'Periodicidad', 'Pagos', 'Total atrasado', 'Pagos atrasados', 'Total pendiente', 'Saldo capital', 'Fecha última transacción'];
    foreach (range(0, 7) as $i) {
        $fecha = date('Y-m-d', strtotime("2026-10-04 +{$i} days"));
        $rows = array_map(fn ($c) => [$c, 'COR1', 'CORDOBA', 'LUIS GOMEZ', 'COR-P1', 'VIGENTE', 'ACTIVO', 'S12', ['__d' => '2026-10-05'],
            ['__d' => '2026-12-21'], 100, 'SEMANAL', 12, 0, 0, 1100, 900, ['__d' => $fecha]], V4::CONTRATOS);
        $this->postJson('/efectividad-cobranza/fotos', ['snapshot_date' => $fecha, 'file' => flowXlsx([$headers, ...$rows], 4)])
            ->assertOk()->assertJsonPath('snapshot_date', $fecha);
    }
    $fotos = $this->getJson('/efectividad-cobranza/fotos?from=2026-10-01&to=2026-10-31')->assertOk()->json('uploads');
    expect($fotos)->toHaveCount(8)->and(collect($fotos)->pluck('snapshot_status')->unique()->all())->toBe(['active']);

    // 8. Ingresos Cobranza por Histórico General (carga + análisis).
    $ingHeaders = ['Oficina', 'Promotor', 'Contrato', 'Acreditado', 'Operación', 'Transacción', 'Fecha transacción', 'Fecha cuota', 'Fecha vencimiento',
        'Capital', 'Interés', 'Impuesto', 'Cargos calendario', 'Producto de crédito', 'Concepto', 'Número cuota', 'Total'];
    $pagos = [[V4::CONTRATOS[0], '2026-10-05', 100], [V4::CONTRATOS[1], '2026-10-06', 100], [V4::CONTRATOS[2], '2026-10-07', 50], [V4::CONTRATOS[3], '2026-10-08', 100],
        ['99ZZZ0000001', '2026-10-09', 70]]; // contrato sin respaldo en las fotos → NO-CARTERA
    $ingRows = array_map(fn ($p) => ['CORDOBA', 'LUIS GOMEZ', $p[0], 'COR1', 'PAGO A CONTRATO', 'PAGO', ['__d' => $p[1]], ['__d' => '2026-10-05'],
        ['__d' => '2026-12-21'], $p[2], 0, 0, 0, 'S12', 'PRINCIPAL', 1, $p[2]], $pagos);
    $mes = Period::query()->create(['name' => 'Octubre 2026', 'code' => 'M-FLOW-2026-10', 'type' => 'monthly', 'year' => 2026, 'month' => 10,
        'sequence' => 1, 'start_date' => '2026-10-05', 'end_date' => '2026-11-01', 'component_period_ids' => [$week->id]]);
    $this->post('/historico-general', ['period_id' => $mes->id, 'covered_period_ids' => [$week->id], 'data_source_id' => DataSource::query()->where('code', 'lendus_ingresos_cobranza')->value('id'),
        'file' => flowXlsx([$ingHeaders, ...$ingRows], 3)])->assertRedirect()->assertSessionHasNoErrors();
    $ing = ReportUpload::query()->latest('id')->firstOrFail();
    $this->post("/historico-general/{$ing->id}/analizar")->assertRedirect();
    expect(Recovery::query()->count())->toBe(5);

    // 9-11. Abrir el módulo, elegir la semana y revisar disponibilidad.
    $this->get("/efectividad-cobranza?period_id={$week->id}")->assertOk()->assertInertia(fn ($p) => $p->component('EfectividadCobranza/Index')->where('engineVersion', 'mrlana-v4-2026-10'));
    $disp = $this->getJson("/efectividad-cobranza/disponibilidad?period_id={$week->id}")->assertOk()->json();
    expect($disp['supported'])->toBeTrue()->and($disp['fotos_disponibles'])->toBe(8)->and($disp['bloqueante'])->toBeFalse()
        ->and($disp['ingresos']['movimientos_corte'])->toBe(5);

    // 12-13. Generar (QUEUE_CONNECTION=sync: el worker corre aquí) y ver las 9 etapas.
    $gen = $this->postJson('/efectividad-cobranza/generar', ['period_id' => $week->id])->assertOk()->json();
    $prog = $this->getJson("/efectividad-cobranza/runs/{$gen['run_id']}/progreso")->assertOk()->json();
    expect($prog['status'])->toBe('success')
        ->and($prog['stages'])->toHaveCount(9)
        ->and(collect($prog['stages'])->pluck('status')->unique()->all())->toBe(['done'])
        ->and($prog['incidents_count'])->toBe(1)
        ->and($prog['has_excel'])->toBeTrue();

    // 14-16. Salir a otro módulo y regresar sin parámetros: la ejecución se recupera desde BD.
    $this->get('/periodos')->assertOk();
    $this->get('/efectividad-cobranza')->assertOk()->assertInertia(fn ($p) => $p->where('focusRun.id', $gen['run_id'])->where('focusRun.status', 'success'));

    // 17. Resultado.
    $gt = collect($prog['rows'])->firstWhere('promotor', 'GRAN TOTAL');
    expect($gt['sano_pc'])->toEqual(400.0)->and($gt['cobranza_total'])->toEqual(350.0)->and($gt['mora'])->toEqual(50.0);

    // 18-19. Incidencias y su CSV.
    $inc = $this->getJson("/efectividad-cobranza/runs/{$gen['run_id']}/incidencias?code=NO-CARTERA")->assertOk()->json();
    expect($inc['meta']['total'])->toBe(1)->and($inc['data'][0]['contract'])->toBe('99ZZZ0000001')->and($inc['data'][0]['amount'])->toEqual(70.0);
    $csv = $this->get("/efectividad-cobranza/runs/{$gen['run_id']}/incidencias/exportar")->assertOk()->streamedContent();
    expect($csv)->toContain('99ZZZ0000001')->toContain('70.00');

    // 20. Excel: se descarga y abre con la hoja EFECTIVIDAD.
    $resp = $this->get("/efectividad-cobranza/runs/{$gen['run_id']}/descargar")->assertOk();
    $book = IOFactory::load($resp->baseResponse->getFile()->getPathname());
    expect($book->getSheetNames())->toBe(['EFECTIVIDAD', '_AUDITORIA_EFECTIVIDAD', 'FUENTES'])
        ->and($book->getSheetByName('_AUDITORIA_EFECTIVIDAD')->getCell('D6')->getValue())->toBe('99ZZZ0000001');

    // 21. Historial.
    $this->get('/efectividad-cobranza')->assertInertia(fn ($p) => $p->where('history.meta.total', 1)->where('history.data.0.id', $gen['run_id'])->where('history.data.0.engine_version', 'mrlana-v4-2026-10'));

    // 22-23. Notificación interna y correo.
    expect($user->fresh()->notifications()->count())->toBe(1);
    $this->getJson('/notificaciones')->assertOk()->assertJsonPath('unread_count', 1);
    Mail::assertSent(EfectividadCobranzaRunMail::class, fn ($m) => $m->hasTo('gerente@example.test'));
    expect(EfectividadCobranzaRun::query()->find($gen['run_id'])->notification_status)->toBe('sent');
});
