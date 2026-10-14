<?php

use App\Models\User;
use App\Services\Efectividad\Calculadora\EfectividadCalculadoraService;
use App\Services\Efectividad\Calculadora\EfectividadExcelReader;
use App\Services\Efectividad\Calculadora\EfectividadWorkspace;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Calculadora de Efectividad (flujo único, sin BD) con Excel sintéticos con la
 * misma forma que los de Lendus: títulos institucionales, fecha en A4 y
 * encabezados en la fila 5 (cartera); títulos + encabezados (ingresos).
 */
const CALC_FOTO_HEAD = ['Oficina', 'Promotor', 'Nombre promotor', 'Contrato', 'Acreditado', 'Estatus', 'Substatus', 'Producto de crédito',
    'Fecha primer pago', 'Fecha vencimiento', 'Cuota total', 'Periodicidad', 'Pagos', 'Total atrasado', 'Pagos atrasados', 'Total pendiente', 'Saldo capital'];
const CALC_ING_HEAD = ['Oficina', 'Promotor', 'Contrato', 'Acreditado', 'Producto de crédito', 'Operación', 'Concepto', 'Transacción',
    'Fecha transacción', 'Fecha cuota', 'Número cuota', 'Capital', 'Interés', 'Impuesto', 'Cargos calendario', 'Cargos vencimiento'];

function calcXlsx(string $path, array $rows): string
{
    $w = new Writer;
    $w->openToFile($path);
    foreach ($rows as $r) {
        $w->addRow(Row::fromValues($r));
    }
    $w->close();

    return $path;
}

function calcCartera(string $dir, string $fecha, string $nombre, array $extra = []): string
{
    $filas = [
        ['RUTA NORTE', 'P1', 'ANA LOPEZ', 'C1', 'A1', 'ACTIVO', 'VIGENTE', 'SEMANAL 12', '2026-10-05', '2026-12-28', 100, 'SEMANAL', 12, 0, 0, 1000, 900],
        ['RUTA SUR', 'P2', 'BETO RUIZ', 'C2', 'A2', 'ACTIVO', 'VIGENTE', 'SEMANAL 12', '2026-10-06', '2026-12-29', 200, 'SEMANAL', 12, 0, 0, 2000, 1800],
        // Ruta sin Sano por cobrar (solo vencido): no figura ni suma al gran total.
        ['RUTA VACANTE', 'P3', 'VACANTE', 'C3', 'A3', 'ACTIVO', 'VENCIDO', 'SEMANAL 12', '2026-01-05', '2026-03-30', 150, 'SEMANAL', 12, 4000, 10, 4000, 3500],
        ...$extra,
    ];

    return calcXlsx("$dir/$nombre", [
        ['MR LANA'], ['SISTEMA LENDUS'], [''],
        ["ANALÍTICO DE SALDOS DE CLIENTES AL $fecha"],
        CALC_FOTO_HEAD,
        ...$filas,
    ]);
}

function calcIngresos(string $dir, string $nombre, array $filas, string $rango = '2026-10-05 A 2026-10-11'): string
{
    return calcXlsx("$dir/$nombre", [['MR LANA'], ["INGRESOS COBRANZA $rango"], [''], CALC_ING_HEAD, ...$filas]);
}

function calcPago(string $contrato, string $fTrans, string $fCuota, float $capital, string $oficina = 'RUTA NORTE', string $prom = 'ANA LOPEZ'): array
{
    return [$oficina, $prom, $contrato, 'A'.substr($contrato, 1), 'SEMANAL 12', 'PAGO A CONTRATO', 'PRINCIPAL', 'PAGO', $fTrans, $fCuota, 1, $capital, 0, 0, 0, 50];
}

/** Ocho carteras (04 a 11/10) con nombres que NO indican la fecha + ingresos de la semana. */
function calcArchivosSemana(string $dir): array
{
    $out = [];
    foreach (range(4, 11) as $i => $d) {
        $out[] = calcCartera($dir, sprintf('2026-10-%02d', $d), 'Saldos Por Cliente ('.(30 - $i).').xlsx');
    }
    $out[] = calcIngresos($dir, 'Ingresos cobranza (3).xlsx', [
        calcPago('C1', '2026-10-05', '2026-10-05', 100),
        calcPago('C2', '2026-10-06', '2026-10-06', 150, 'RUTA SUR', 'BETO RUIZ'),
        calcPago('C9', '2026-10-07', '2026-10-07', 80), // contrato sin cartera
    ]);

    return $out;
}

function calcUpload(string $path): UploadedFile
{
    $copia = dirname($path).'/up_'.uniqid().'_'.basename($path);
    copy($path, $copia);

    return new UploadedFile($copia, basename($path), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

beforeEach(function () {
    $this->dir = sys_get_temp_dir().'/calc-efect-'.uniqid();
    File::ensureDirectoryExists($this->dir);
    $this->admin = User::factory()->create(['role' => 'admin']);
});

afterEach(function () {
    File::deleteDirectory($this->dir);
    app(EfectividadWorkspace::class)->purgarVencidos(0);
});

it('lee la fecha de la cartera del contenido (A4), con encabezados en la fila 5 y datos desde la 6', function () {
    $f = calcCartera($this->dir, '2026-10-07', 'cualquier-nombre (99).xlsx');
    $r = (new EfectividadExcelReader)->leer($f);

    expect($r['tipo'])->toBe('cartera')
        ->and($r['fecha'])->toBe('2026-10-07')
        ->and($r['resumen']['contratos'])->toBe(3)
        ->and($r['filas'][0]['contrato'])->toBe('C1')
        ->and($r['columnas'])->toContain('nombre_promotor', 'substatus', 'cuota_total');

    $i = (new EfectividadExcelReader)->leer(calcIngresos($this->dir, 'ing.xlsx', [calcPago('C1', '2026-10-06', '2026-10-06', 10)]));
    expect($i['tipo'])->toBe('ingresos')
        ->and([$i['desde'], $i['hasta']])->toBe(['2026-10-05', '2026-10-11']) // rango del encabezado, aunque solo haya cobros el 06
        ->and($i['filas'][0]['ref'])->toBe('5');
});

it('rechaza archivos que no son de Lendus, Excel corruptos, duplicados y fechas repetidas con mensajes claros', function () {
    $svc = app(EfectividadCalculadoraService::class);
    $otro = calcXlsx($this->dir.'/otro.xlsx', [['Nombre', 'Edad'], ['X', 1]]);
    file_put_contents($this->dir.'/roto.xlsx', 'esto no es un zip');
    $c1 = calcCartera($this->dir, '2026-10-04', 'a.xlsx');
    $c2 = calcCartera($this->dir, '2026-10-04', 'b.xlsx', [['RUTA SUR', 'P2', 'BETO RUIZ', 'C7', 'A7', 'ACTIVO', 'VIGENTE', 'S', '2026-10-06', '2026-12-29', 1, 'SEMANAL', 1, 0, 0, 1, 1]]);

    $r = $svc->agregarArchivos(null, $this->admin->id, [calcUpload($otro), calcUpload($this->dir.'/roto.xlsx'), calcUpload($c1), calcUpload($c1), calcUpload($c2)]);
    $errores = array_column($r['rechazados'], 'error', 'nombre');

    expect($r['resumen']['carteras'])->toHaveCount(1)
        ->and($errores['otro.xlsx'])->toContain('No parece un reporte de Lendus')
        ->and($errores['roto.xlsx'])->toContain('dañado')
        ->and($errores['b.xlsx'])->toContain('Ya hay una cartera con fecha 04/10/2026')
        ->and(collect($r['rechazados'])->where('nombre', 'a.xlsx')->first()['error'])->toContain('mismo archivo');
});

it('flujo web completo: carga múltiple, resumen ordenado, cálculo semanal, filtros y limpieza — sin escribir en BD', function () {
    $escrituras = [];
    DB::listen(function ($q) use (&$escrituras) {
        if (preg_match('/^\s*(insert|update|delete|replace)/i', $q->sql)) {
            $escrituras[] = $q->sql;
        }
    });

    $this->actingAs($this->admin)->get('/efectividad-cobranza')->assertOk();

    $token = null;
    foreach (calcArchivosSemana($this->dir) as $f) {
        $resp = $this->actingAs($this->admin)->post('/efectividad-cobranza/calculadora/archivos', array_filter([
            'token' => $token, 'archivos' => [calcUpload($f)],
        ]), ['Accept' => 'application/json'])->assertOk();
        $token = $resp->json('token');
    }
    $resumen = $resp->json('resumen');

    expect(array_column($resumen['carteras'], 'fecha'))->toBe(['2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'])
        ->and(collect($resumen['semana']['dias'])->every(fn ($d) => $d['disponible']))->toBeTrue()
        ->and($resumen['semana']['ingresos_ok'])->toBeTrue()
        ->and(array_column($resumen['cortes']['SEMANAL'], 'valor'))->toBe(['2026-10-11'])
        ->and($resumen['cortes']['DIARIO'])->toHaveCount(7)
        ->and($resumen['cortes']['ACUMULADO'])->toHaveCount(7)
        ->and(array_column($resumen['cortes']['MENSUAL'], 'valor'))->toBe(['2026-10']);

    $res = $this->actingAs($this->admin)->postJson("/efectividad-cobranza/calculadora/$token/calcular", ['modo' => 'SEMANAL', 'valor' => '2026-10-11'])
        ->assertOk()->json('resultado');
    $gt = collect($res['filas'])->firstWhere('tipo', 'gran_total');

    expect($res['titulo'])->toBe('CORTE SEMANAL: 05/10/2026 AL 11/10/2026')
        ->and(collect($res['filas'])->pluck('oficina')->filter()->unique()->values()->all())->toBe(['RUTA NORTE', 'RUTA SUR'])
        ->and($gt['sano_pc'])->toEqual(300.0)
        ->and($gt['vencido_pc'])->toEqual(0.0) // la ruta sin Sano por cobrar no suma su vencido
        ->and($gt['cobranza_total'])->toEqual(250.0)
        ->and($gt['pct_total'])->toEqual(250 / 300)
        ->and($gt['mora'])->toEqual(50.0)
        ->and(collect($res['incidencias'])->pluck('codigo')->all())->toContain('NO-CARTERA')
        ->and($res['avisos'])->not->toBeEmpty(); // sin ingresos previos: se explica que faltan anticipos

    // Filtro por oficina: subtotal y gran total recalculados en el servidor.
    $filas = $this->actingAs($this->admin)->getJson("/efectividad-cobranza/calculadora/$token/vista?oficina=RUTA%20SUR")->assertOk()->json('filas');
    expect($filas)->toHaveCount(3)
        ->and(collect($filas)->firstWhere('tipo', 'gran_total')['sano_pc'])->toEqual(200.0);

    // Diario, acumulado y mensual con los mismos archivos.
    foreach (['DIARIO' => '2026-10-06', 'ACUMULADO' => '2026-10-06', 'MENSUAL' => '2026-10'] as $modo => $valor) {
        $r = $this->actingAs($this->admin)->postJson("/efectividad-cobranza/calculadora/$token/calcular", ['modo' => $modo, 'valor' => $valor])->assertOk()->json('resultado');
        $sano = collect($r['filas'])->firstWhere('tipo', 'gran_total')['sano_pc'];
        expect($sano)->toEqual(match ($modo) { 'DIARIO' => 200.0, 'ACUMULADO' => 300.0, 'MENSUAL' => 300.0 });
    }

    // Limpiar borra el espacio temporal; después, la sesión ya no existe.
    $this->actingAs($this->admin)->postJson("/efectividad-cobranza/calculadora/$token/limpiar")->assertOk();
    expect(is_dir(EfectividadWorkspace::raiz().'/'.$token))->toBeFalse();
    $this->actingAs($this->admin)->postJson("/efectividad-cobranza/calculadora/$token/calcular", ['modo' => 'SEMANAL', 'valor' => '2026-10-11'])
        ->assertStatus(422)->assertJsonPath('message', 'La sesión de cálculo expiró. Vuelve a cargar tus archivos.');

    expect($escrituras)->toBe([]);
    expect(glob($this->dir.'/up_*'))->toBe([]); // los Excel subidos no se conservan
});

it('el historial se arma con varios Excel de ingresos sin duplicar fechas y reconoce anticipos', function () {
    $svc = app(EfectividadCalculadoraService::class);
    $archivos = calcArchivosSemana($this->dir);
    // Historial previo: un anticipo (pagado el 30/09 con cuota del 05/10) y un día que el archivo semanal reemplaza.
    $archivos[] = calcIngresos($this->dir, 'historico.xlsx', [
        calcPago('C1', '2026-09-30', '2026-10-05', 40),
        calcPago('C1', '2026-10-05', '2026-10-05', 999), // fecha también presente en el archivo semanal → se descarta
    ], '2026-09-28 A 2026-10-05');

    $r = $svc->agregarArchivos(null, $this->admin->id, array_map('calcUpload', $archivos));
    $res = $svc->calcular($r['token'], $this->admin->id, 'SEMANAL', '2026-10-11');
    $norte = collect($res['filas'])->firstWhere('promotor', 'ANA LOPEZ');

    expect($norte['sano_cob'])->toEqual(140.0) // 100 de la semana + 40 de anticipo; nunca los 999 reemplazados
        ->and($res['avisos'])->toBe([]);
});

it('aislamiento: otro usuario no puede usar ni descargar el cálculo ajeno; colaborador sin acceso', function () {
    $svc = app(EfectividadCalculadoraService::class);
    $token = $svc->agregarArchivos(null, $this->admin->id, array_map('calcUpload', calcArchivosSemana($this->dir)))['token'];
    $svc->calcular($token, $this->admin->id, 'SEMANAL', '2026-10-11');

    $otro = User::factory()->create(['role' => 'gerencial']);
    $this->actingAs($otro)->postJson("/efectividad-cobranza/calculadora/$token/calcular", ['modo' => 'SEMANAL', 'valor' => '2026-10-11'])->assertStatus(422);
    $this->actingAs($otro)->getJson("/efectividad-cobranza/calculadora/$token/exportar/pdf")->assertStatus(422);
    $this->actingAs($otro)->postJson("/efectividad-cobranza/calculadora/$token/limpiar")->assertOk();
    expect(is_dir(EfectividadWorkspace::raiz().'/'.$token))->toBeTrue(); // limpiar ajeno no borra nada

    $colab = User::factory()->create(['role' => 'colaborador']);
    $this->actingAs($colab)->get('/efectividad-cobranza')->assertForbidden();
    $this->actingAs($colab)->postJson('/efectividad-cobranza/calculadora/archivos', [])->assertForbidden();
});

it('exporta PDF y PNG del mismo resultado y borra los archivos generados tras enviarlos', function () {
    $html = [];
    $this->mock(BrowsershotPdfRenderer::class, function ($m) use (&$html) {
        $m->shouldReceive('renderHtmlToFile')->andReturnUsing(function ($h, $out) use (&$html) {
            $html[] = $h;
            file_put_contents($out, '%PDF-1.4 prueba');
        });
        $m->shouldReceive('renderHtmlToImage')->andReturnUsing(function ($h, $out) use (&$html) {
            $html[] = $h;
            file_put_contents($out, "\x89PNG prueba");
        });
    });
    $svc = app(EfectividadCalculadoraService::class);
    $token = $svc->agregarArchivos(null, $this->admin->id, array_map('calcUpload', calcArchivosSemana($this->dir)))['token'];
    $svc->calcular($token, $this->admin->id, 'SEMANAL', '2026-10-11');

    $pdf = $this->actingAs($this->admin)->get("/efectividad-cobranza/calculadora/$token/exportar/pdf")->assertOk();
    $png = $this->actingAs($this->admin)->get("/efectividad-cobranza/calculadora/$token/exportar/png")->assertOk();
    $pdf->sendContent();
    $png->sendContent();

    expect($html)->toHaveCount(2)
        ->and($html[0])->toContain('GRAN TOTAL')->toContain('$300.00')->toContain('83.33%')
        ->and($html[1])->toContain('$300.00')
        ->and(glob(EfectividadWorkspace::raiz().'/exports/*'))->toBe([]);
});

it('el TTL elimina espacios temporales abandonados', function () {
    $ws = app(EfectividadWorkspace::class);
    $token = $ws->crear($this->admin->id);
    touch(EfectividadWorkspace::raiz().'/'.$token, time() - 2 * 3600);

    $this->artisan('efectividad:limpiar-temporales')->assertSuccessful();
    expect(is_dir(EfectividadWorkspace::raiz().'/'.$token))->toBeFalse();
});
