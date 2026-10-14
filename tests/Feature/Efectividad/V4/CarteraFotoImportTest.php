<?php

use App\Enums\ReportUploadStatus;
use App\Models\Branch;
use App\Models\Period;
use App\Models\PortfolioSnapshot;
use App\Models\PortfolioSnapshotRow;
use App\Models\ProcessRun;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\Efectividad\V4\EfectividadV4DataAdapter;
use App\Services\Imports\LendusCarteraFotoImportService;
use App\Services\ReportAnalysisService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\EfectividadV4Fixtures as V4;
use Tests\Support\EfectividadV4Reference as Ref;

/**
 * Importador de FOTOGRAFÍAS DIARIAS de cartera (Efectividad V4) con archivos
 * XLSX sintéticos (sin datos personales): una fotografía por día de la semana
 * 05-11/10/2026 + domingo base 04/10, validación de fecha declarada vs
 * contenido, encabezados, filas, reemplazo controlado y aislamiento respecto
 * del flujo mensual de Saldos por Cliente (Reportería histórica).
 */
const FOTO_HEADERS = [
    'Contrato', 'Acreditado', 'Oficina', 'Nombre promotor', 'Promotor', 'Cliente', 'Substatus', 'Estatus',
    'Producto de credito', 'Origen credito', 'Fecha primer pago', 'Fecha vencimiento', 'Fecha siguiente pago',
    'Cuota total', 'Periodicidad', 'Pagos', 'Total atrasado', 'Pagos atrasados', 'Total pendiente', 'Saldo capital',
    'Fecha última transacción',
];

function fotoRow(string $contrato, string $ultima, array $over = []): array
{
    $row = array_combine(FOTO_HEADERS, [
        $contrato, 'COR1', 'CORDOBA', 'LUIS GOMEZ', 'COR-P1', 'C-'.$contrato, 'VIGENTE', 'ACTIVO',
        'S12', 'NUEVO', ['__d' => '2026-10-05'], ['__d' => '2026-12-21'], ['__d' => '2026-10-12'],
        100, 'SEMANAL', 12, 0, 0, 1100, 900, ['__d' => $ultima],
    ]);

    return array_values(array_merge($row, $over));
}

/** Escribe y procesa una fotografía; devuelve el upload (o lanza la excepción del importador). */
function importarFoto(string $fecha, ?array $rows = null, ?array $headers = null, int $titulos = 4, ?Period $period = null): ReportUpload
{
    $period ??= Period::query()->where('type', 'weekly')->first();
    $rows ??= array_map(fn ($c) => fotoRow($c, $fecha), V4::CONTRATOS);
    $rel = 'reports/foto_'.$fecha.'_'.uniqid().'.xlsx';
    Ref::writeXlsx([$headers ?? FOTO_HEADERS, ...$rows], Storage::disk('public')->path($rel), $titulos);
    $upload = Ref::upload('lendus_saldos_cliente_foto', $period, $rel, $fecha);
    app(ReportAnalysisService::class)->analyze($upload);

    return $upload->fresh();
}

function intentarFoto(string $fecha, ?array $rows = null, ?array $headers = null): array
{
    try {
        importarFoto($fecha, $rows, $headers);
    } catch (Throwable $e) {
        return [ReportUpload::snapshots()->latest('id')->first(), $e->getMessage()];
    }

    return [ReportUpload::snapshots()->latest('id')->first(), null];
}

beforeEach(function () {
    Storage::fake('public');
    Storage::disk('public')->makeDirectory('reports');
    $this->week = V4::week();
    V4::branch();
});

it('importa las ocho fotografías (domingo base … domingo corte) y cada una conserva su fecha', function () {
    $dias = ['2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'];
    foreach ($dias as $fecha) {
        $upload = importarFoto($fecha);
        expect($upload->status)->toBe(ReportUploadStatus::Processed)
            ->and($upload->snapshot_date->format('Y-m-d'))->toBe($fecha);
    }

    $snaps = PortfolioSnapshot::query()->active()->orderBy('snapshot_date')->get();
    expect($snaps)->toHaveCount(8)
        ->and($snaps->map(fn ($s) => $s->snapshot_date->format('Y-m-d'))->all())->toBe($dias)
        ->and($snaps->pluck('rows_count')->unique()->all())->toBe([4]);
    foreach ($dias as $fecha) {
        expect(PortfolioSnapshotRow::query()->whereDate('snapshot_date', $fecha)->count())->toBe(4);
    }

    // La disponibilidad del adaptador ve las 8 fechas.
    $disp = app(EfectividadV4DataAdapter::class)->availability($this->week, null);
    expect($disp['fotos_disponibles'])->toBe(8)
        ->and(collect($disp['fotos'])->pluck('estado')->unique()->all())->toBe(['disponible']);
});

it('la fotografía del martes NO reemplaza ni borra la del lunes', function () {
    importarFoto('2026-10-05');
    $lunes = PortfolioSnapshot::query()->active()->sole();
    importarFoto('2026-10-06');

    expect($lunes->fresh()->status)->toBe(PortfolioSnapshot::STATUS_ACTIVE)
        ->and($lunes->fresh()->replaced_by_id)->toBeNull()
        ->and(PortfolioSnapshotRow::query()->where('portfolio_snapshot_id', $lunes->id)->count())->toBe(4)
        ->and(PortfolioSnapshot::query()->active()->count())->toBe(2);
});

it('rechaza una fotografía cuya fecha declarada es anterior a las transacciones que contiene', function () {
    $rows = array_map(fn ($c) => fotoRow($c, '2026-10-07'), V4::CONTRATOS); // contenido del miércoles
    [$upload, $error] = intentarFoto('2026-10-06', $rows);                    // declarada como martes

    expect($error)->toContain('Fecha incompatible')->toContain('06/10/2026')->toContain('07/10/2026')
        ->and($upload->status)->toBe(ReportUploadStatus::Failed)
        ->and(PortfolioSnapshot::query()->count())->toBe(0)
        ->and(PortfolioSnapshotRow::query()->count())->toBe(0);
});

it('un solo movimiento posterior a la fecha de la fotografía basta para rechazarla', function () {
    $rows = array_map(fn ($c) => fotoRow($c, '2026-10-08'), V4::CONTRATOS);
    $rows[2] = fotoRow(V4::CONTRATOS[2], '2026-10-09');
    [, $error] = intentarFoto('2026-10-08', $rows);

    expect($error)->toContain('Fecha incompatible');
});

it('acepta un archivo con transacciones antiguas pero avisa que confirme la fecha', function () {
    $rows = array_map(fn ($c) => fotoRow($c, '2026-10-01'), V4::CONTRATOS);
    $upload = importarFoto('2026-10-08', $rows);

    expect($upload->status)->toBe(ReportUploadStatus::Processed)
        ->and(ProcessRun::query()->where('report_upload_id', $upload->id)->value('log'))->toContain('Aviso')->toContain('01/10/2026');
});

it('rechaza un archivo sin encabezados obligatorios de la calculadora V4 y lista cuáles faltan', function () {
    $headers = array_values(array_diff(FOTO_HEADERS, ['Cuota total', 'Periodicidad']));
    $rows = array_map(fn ($c) => array_values(array_diff_key(array_combine(FOTO_HEADERS, fotoRow($c, '2026-10-05')), array_flip(['Cuota total', 'Periodicidad']))), V4::CONTRATOS);
    [$upload, $error] = intentarFoto('2026-10-05', $rows, $headers);

    expect($error)->toContain('columnas obligatorias')->toContain('cuota_total')->toContain('periodicidad')
        ->and($upload->status)->toBe(ReportUploadStatus::Failed)
        ->and(PortfolioSnapshot::query()->count())->toBe(0);
});

it('rechaza un archivo que no es Saldos por Cliente (sin fila de encabezados)', function () {
    [, $error] = intentarFoto('2026-10-05', [['A', 'B'], ['1', '2']], ['Columna 1', 'Columna 2']);

    expect($error)->toContain('Saldos por Cliente');
});

it('con encabezados duplicados usa la PRIMERA columna, igual que ColumnaEncabezado de la macro', function () {
    $headers = [...FOTO_HEADERS, 'Cuota total'];
    $rows = array_map(fn ($c) => [...fotoRow($c, '2026-10-05'), 999], V4::CONTRATOS);
    importarFoto('2026-10-05', $rows, $headers);

    expect(PortfolioSnapshotRow::query()->pluck('installment_total')->unique()->values()->all())->toBe([100.0]);
});

it('omite filas sin contrato y las cuenta como omitidas', function () {
    $rows = array_map(fn ($c) => fotoRow($c, '2026-10-05'), V4::CONTRATOS);
    $rows[] = fotoRow('', '2026-10-05');
    $rows[] = fotoRow('   ', '2026-10-05');
    $upload = importarFoto('2026-10-05', $rows);

    $run = ProcessRun::query()->where('report_upload_id', $upload->id)->sole();
    expect(PortfolioSnapshotRow::query()->count())->toBe(4)
        ->and($run->rows_inserted)->toBe(4)
        ->and($run->rows_skipped)->toBe(2);
});

it('conserva contratos repetidos tal cual (el motor los reporta como FOTO-DUP y usa la primera fila)', function () {
    $rows = array_map(fn ($c) => fotoRow($c, '2026-10-05'), V4::CONTRATOS);
    $rows[] = fotoRow(V4::CONTRATOS[0], '2026-10-05', ['Cuota total' => 555]);
    importarFoto('2026-10-05', $rows);

    $filas = PortfolioSnapshotRow::query()->where('contract', V4::CONTRATOS[0])->orderBy('source_row')->get();
    expect($filas)->toHaveCount(2)
        ->and($filas[0]->installment_total)->toBe(100.0)
        ->and($filas[1]->installment_total)->toBe(555.0);
});

it('registra varias sucursales y oficinas de una misma fotografía', function () {
    Branch::query()->firstOrCreate(['code' => 'TUL'], ['name' => 'TULANCINGO', 'normalized_name' => 'tulancingo', 'is_active' => true]);
    $rows = array_map(fn ($c) => fotoRow($c, '2026-10-05'), V4::CONTRATOS);
    $rows[] = fotoRow('25TUL0000001', '2026-10-05', ['Oficina' => 'TULANCINGO', 'Promotor' => 'TUL-P1']);
    importarFoto('2026-10-05', $rows);

    $snap = PortfolioSnapshot::query()->sole();
    expect($snap->branch_ids)->toHaveCount(2)
        ->and($snap->offices)->toEqualCanonicalizing(['CORDOBA', 'TULANCINGO'])
        ->and(PortfolioSnapshotRow::query()->whereNull('branch_id')->count())->toBe(0);
});

it('una segunda fotografía de la MISMA fecha reemplaza la anterior de forma controlada (sin borrarla)', function () {
    importarFoto('2026-10-05');
    $primera = PortfolioSnapshot::query()->sole();
    $upload2 = importarFoto('2026-10-05');
    $segunda = PortfolioSnapshot::query()->where('report_upload_id', $upload2->id)->sole();

    expect($primera->fresh()->status)->toBe(PortfolioSnapshot::STATUS_REPLACED)
        ->and($primera->fresh()->replaced_by_id)->toBe($segunda->id)
        ->and($segunda->status)->toBe(PortfolioSnapshot::STATUS_ACTIVE)
        // Histórico conservado: las filas de la sustituida siguen en BD.
        ->and(PortfolioSnapshotRow::query()->where('portfolio_snapshot_id', $primera->id)->count())->toBe(4)
        ->and(ReportUpload::snapshots()->count())->toBe(2)
        ->and(ProcessRun::query()->where('report_upload_id', $upload2->id)->value('log'))->toContain('Sustituye a 1');
});

it('una fotografía de otra sucursal con la misma fecha NO reemplaza a la existente', function () {
    Branch::query()->firstOrCreate(['code' => 'TUL'], ['name' => 'TULANCINGO', 'normalized_name' => 'tulancingo', 'is_active' => true]);
    importarFoto('2026-10-05');
    importarFoto('2026-10-05', [fotoRow('25TUL0000001', '2026-10-05', ['Oficina' => 'TULANCINGO', 'Promotor' => 'TUL-P1'])]);

    expect(PortfolioSnapshot::query()->active()->count())->toBe(2);
});

it('una carga fallida de la misma fecha conserva intacta la fotografía vigente', function () {
    importarFoto('2026-10-05');
    $vigente = PortfolioSnapshot::query()->sole();
    [, $error] = intentarFoto('2026-10-05', array_map(fn ($c) => fotoRow($c, '2026-10-06'), V4::CONTRATOS));

    expect($error)->toContain('Fecha incompatible')
        ->and($vigente->fresh()->status)->toBe(PortfolioSnapshot::STATUS_ACTIVE)
        ->and(PortfolioSnapshotRow::query()->where('portfolio_snapshot_id', $vigente->id)->count())->toBe(4);
});

it('reimportar (reprocesar) el MISMO archivo reemplaza sus filas sin duplicarlas', function () {
    $upload = importarFoto('2026-10-05');
    app(ReportAnalysisService::class)->analyze($upload->fresh());
    app(ReportAnalysisService::class)->analyze($upload->fresh());

    expect(PortfolioSnapshot::query()->count())->toBe(1)
        ->and(PortfolioSnapshot::query()->active()->count())->toBe(1)
        ->and(PortfolioSnapshotRow::query()->count())->toBe(4);
});

it('el mismo archivo cargado como dos fechas distintas bloquea la semana (FOTO-DUPLICADA)', function () {
    $rows = array_map(fn ($c) => fotoRow($c, '2026-10-04'), V4::CONTRATOS);
    $rel = 'reports/mismo.xlsx';
    Ref::writeXlsx([FOTO_HEADERS, ...$rows], Storage::disk('public')->path($rel), 4);
    foreach (['2026-10-05', '2026-10-06'] as $fecha) {
        app(ReportAnalysisService::class)->analyze(Ref::upload('lendus_saldos_cliente_foto', $this->week, $rel, $fecha));
    }

    $disp = app(EfectividadV4DataAdapter::class)->availability($this->week, null);
    expect(collect($disp['issues'])->pluck('code'))->toContain('EC-V4-FOTO-DUPLICADA')
        ->and($disp['bloqueante'])->toBeTrue();
});

it('fotografías incompletas: la disponibilidad nombra exactamente las fechas faltantes y bloquea', function () {
    foreach (['2026-10-04', '2026-10-05', '2026-10-06'] as $f) {
        importarFoto($f);
    }

    $disp = app(EfectividadV4DataAdapter::class)->availability($this->week, null);
    $faltantes = collect($disp['fotos'])->where('estado', 'faltante')->pluck('fecha')->all();
    expect($disp['fotos_disponibles'])->toBe(3)
        ->and($faltantes)->toBe(['2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'])
        ->and($disp['bloqueante'])->toBeTrue()
        ->and(collect($disp['issues'])->where('code', 'EC-V4-FOTO-FALTANTE')->count())->toBe(5);
});

it('una carga fallida aparece como «fallida» con su motivo en la disponibilidad', function () {
    intentarFoto('2026-10-05', array_map(fn ($c) => fotoRow($c, '2026-10-09'), V4::CONTRATOS));

    $disp = app(EfectividadV4DataAdapter::class)->availability($this->week, null);
    $lunes = collect($disp['fotos'])->firstWhere('fecha', '2026-10-05');
    expect($lunes['estado'])->toBe('fallida')
        ->and($lunes['error'])->toContain('Fecha incompatible');
});

// ── Aislamiento respecto de Reportería histórica ───────────────────────────

it('las fotografías NO tocan fact_portfolios ni aparecen en el flujo mensual de Saldos por Cliente', function () {
    // Carga tradicional (mensual) de Saldos por Cliente del mismo periodo.
    $mensual = Ref::upload('lendus_saldos_cliente', $this->week, 'reports/saldos_mensual.xlsx');
    $portfoliosAntes = DB::table('fact_portfolios')->count();

    importarFoto('2026-10-05');
    importarFoto('2026-10-06');

    expect(DB::table('fact_portfolios')->count())->toBe($portfoliosAntes)
        // Eloquent (global scope): el flujo mensual solo ve su archivo.
        ->and(ReportUpload::query()->pluck('id')->all())->toBe([$mensual->id])
        ->and(ReportUpload::query()->where('period_id', $this->week->id)->count())->toBe(1)
        ->and($this->week->fresh()->reportUploads()->count())->toBe(1)
        ->and(ReportUpload::query()->find(ReportUpload::snapshots()->value('id')))->toBeNull()
        // Acceso explícito de V4.
        ->and(ReportUpload::snapshots()->count())->toBe(2)
        // La carga mensual no quedó marcada como reemplazada por las fotos.
        ->and($mensual->fresh()->status)->toBe(ReportUploadStatus::Pending);
});

it('el scope se aplica a joins y relaciones Eloquent; Query Builder directo NO lo aplica (documentado)', function () {
    importarFoto('2026-10-05');
    $snapUploadId = ReportUpload::snapshots()->value('id');

    // Relación inversa desde PortfolioSnapshot: acceso explícito (withoutGlobalScopes) — correcto.
    expect(PortfolioSnapshot::query()->sole()->upload?->id)->toBe($snapUploadId);
    // Query Builder crudo: ve todas las filas. Las fotos no generan filas en tablas de hechos
    // (fact_*), por lo que los joins fact_* → report_uploads de los comandos de auditoría no las alcanzan.
    expect(DB::table('report_uploads')->whereNotNull('snapshot_date')->count())->toBe(1);
    foreach (['fact_portfolios', 'fact_recoveries', 'fact_expenses', 'fact_payrolls'] as $tabla) {
        if (Schema::hasTable($tabla)) {
            expect(DB::table($tabla)->where('report_upload_id', $snapUploadId)->count())->toBe(0);
        }
    }
});

it('la descarga del archivo original del flujo mensual no expone una fotografía por id', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    importarFoto('2026-10-05');
    $id = ReportUpload::snapshots()->value('id');

    $routes = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), '{reportUpload}') || str_contains($r->uri(), '{upload}'))
        ->filter(fn ($r) => in_array('GET', $r->methods(), true) && ! str_contains($r->uri(), 'efectividad'));
    expect($routes)->not->toBeEmpty();
    foreach ($routes as $r) {
        $uri = preg_replace('/\{(reportUpload|upload)\}/', (string) $id, $r->uri());
        $status = $this->actingAs($admin)->get('/'.$uri)->status();
        expect($status)->toBeIn([302, 403, 404]);
    }
});

it('Histórico General no ofrece ni acepta la fuente de fotografía diaria (necesita fecha, no mes)', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $foto = Ref::source('lendus_saldos_cliente_foto');
    Ref::source('lendus_saldos_cliente');
    $mes = Period::query()->create(['name' => 'Octubre 2026', 'code' => 'M-FOTO-10', 'type' => 'monthly', 'year' => 2026, 'month' => 10, 'sequence' => 1,
        'start_date' => '2026-10-05', 'end_date' => '2026-11-01', 'component_period_ids' => [$this->week->id]]);

    $this->actingAs($admin)->get('/historico-general')->assertOk()
        ->assertInertia(fn ($p) => $p->where('sources', fn ($sources) => collect($sources)->pluck('code')->contains('lendus_saldos_cliente')
            && ! collect($sources)->pluck('code')->contains('lendus_saldos_cliente_foto')));
    $this->actingAs($admin)->post('/historico-general', ['period_id' => $mes->id, 'covered_period_ids' => [$this->week->id], 'data_source_id' => $foto->id,
        'file' => UploadedFile::fake()->create('saldos.xlsx', 5, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')])
        ->assertSessionHasErrors(['data_source_id' => 'Las fotografías diarias de cartera se cargan en Efectividad de Cobranza › Disponibilidad de información, indicando la fecha de cada fotografía.']);
    expect(DB::table('report_uploads')->count())->toBe(0);
});

it('los mensajes de error de una fotografía nunca muestran rutas del servidor', function () {
    $msg = LendusCarteraFotoImportService::mensajePublico(
        'Could not open '.storage_path('app/public/reports/abc.xlsx').' for reading. Otro: /var/www/reportes/storage/app/public/reports/z9.xlsx'
    );

    expect($msg)->toBe('Could not open abc.xlsx for reading. Otro: z9.xlsx')
        ->and($msg)->not->toContain(base_path());
});

// ── Carga por HTTP (pantalla de Disponibilidad) ────────────────────────────

