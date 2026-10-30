<?php

use App\Models\User;
use App\Services\Efectividad\Calculadora\EfectividadCalculadoraExcelExporter;
use App\Services\Efectividad\Calculadora\EfectividadCalculadoraService;
use App\Services\Efectividad\Calculadora\EfectividadExcelReader;
use App\Services\Efectividad\Calculadora\EfectividadWorkspace;
use App\Services\Pdf\BrowsershotPdfRenderer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PhpOffice\PhpSpreadsheet\IOFactory;

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
        ['TEPEJI', 'P1', 'ANA LOPEZ', 'C1', 'A1', 'ACTIVO', 'VIGENTE', 'SEMANAL 12', '2026-10-05', '2026-12-28', 100, 'SEMANAL', 12, 0, 0, 1000, 900],
        ['ACAMBAY', 'P2', 'BETO RUIZ', 'C2', 'A2', 'ACTIVO', 'VIGENTE', 'SEMANAL 12', '2026-10-06', '2026-12-29', 200, 'SEMANAL', 12, 0, 0, 2000, 1800],
        // Ruta sin Sano por cobrar (solo vencido): no figura ni suma al gran total.
        ['ATITALAQUIA', 'P3', 'VACANTE', 'C3', 'A3', 'ACTIVO', 'VENCIDO', 'SEMANAL 12', '2026-01-05', '2026-03-30', 150, 'SEMANAL', 12, 4000, 10, 4000, 3500],
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

function calcPago(string $contrato, string $fTrans, string $fCuota, float $capital, string $oficina = 'TEPEJI', string $prom = 'ANA LOPEZ'): array
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
        calcPago('C2', '2026-10-06', '2026-10-06', 150, 'ACAMBAY', 'BETO RUIZ'),
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
    $c2 = calcCartera($this->dir, '2026-10-04', 'b.xlsx', [['ACAMBAY', 'P2', 'BETO RUIZ', 'C7', 'A7', 'ACTIVO', 'VIGENTE', 'S', '2026-10-06', '2026-12-29', 1, 'SEMANAL', 1, 0, 0, 1, 1]]);

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
        ->and(collect($resumen['cortes']['SEMANAL'][0]['requisitos'])->where('tipo', 'cartera')->count())->toBe(8)
        ->and(collect($resumen['cortes'])->flatten(1)->every(fn ($o) => $o['listo']))->toBeTrue()
        ->and(array_column($resumen['cortes']['SEMANAL'], 'valor'))->toBe(['2026-10-11'])
        ->and($resumen['cortes']['DIARIO'])->toHaveCount(7)
        ->and($resumen['cortes']['ACUMULADO'])->toHaveCount(7)
        ->and(array_column($resumen['cortes']['MENSUAL'], 'valor'))->toBe(['2026-10']);

    $res = $this->actingAs($this->admin)->postJson("/efectividad-cobranza/calculadora/$token/calcular", ['modo' => 'SEMANAL', 'valor' => '2026-10-11'])
        ->assertOk()->json('resultado');
    $gt = collect($res['filas'])->firstWhere('tipo', 'gran_total');

    expect($res['titulo'])->toBe('CORTE SEMANAL: 05/10/2026 AL 11/10/2026')
        // Sucursal → Ruta → Promotor: ACAMBAY es de ATLACOMULCO y TEPEJI de TULA (ATITALAQUIA, sin Sano, no figura).
        ->and(collect($res['filas'])->pluck('oficina')->filter()->unique()->values()->all())->toBe(['ACAMBAY', 'TEPEJI'])
        ->and(collect($res['filas'])->where('tipo', 'subtotal_sucursal')->pluck('promotor')->all())->toBe(['TOTAL SUCURSAL ATLACOMULCO', 'TOTAL SUCURSAL TULA'])
        ->and(collect($res['filas'])->firstWhere('promotor', 'ANA LOPEZ')['sucursal'])->toBe('TULA')
        ->and($res['catalogo'])->toBe([
            ['sucursal' => 'ATLACOMULCO', 'rutas' => [['ruta' => 'ACAMBAY', 'promotores' => ['BETO RUIZ']]]],
            ['sucursal' => 'TULA', 'rutas' => [['ruta' => 'TEPEJI', 'promotores' => ['ANA LOPEZ']]]],
        ])
        ->and(array_column($resumen['sucursales'], 'sucursal'))->toBe(['ATLACOMULCO', 'TULA'])
        ->and($gt['sano_pc'])->toEqual(300.0)
        ->and($gt['vencido_pc'])->toEqual(0.0) // la ruta sin Sano por cobrar no suma su vencido
        ->and($gt['cobranza_total'])->toEqual(250.0)
        ->and($gt['pct_total'])->toEqual(250 / 300)
        ->and($gt['mora'])->toEqual(50.0)
        ->and(collect($res['incidencias'])->pluck('codigo')->all())->toContain('NO-CARTERA')
        ->and($res['avisos'])->not->toBeEmpty(); // sin ingresos previos: se explica que faltan anticipos

    // Filtro por oficina: subtotal y gran total recalculados en el servidor.
    $filas = $this->actingAs($this->admin)->getJson("/efectividad-cobranza/calculadora/$token/vista?oficina=ACAMBAY")->assertOk()->json('filas');
    expect($filas)->toHaveCount(3)
        ->and(collect($filas)->firstWhere('tipo', 'gran_total')['sano_pc'])->toEqual(200.0);

    // Filtro por sucursal: solo sus rutas; el gran total es el de la sucursal.
    $filas = $this->actingAs($this->admin)->getJson("/efectividad-cobranza/calculadora/$token/vista?sucursal=TULA")->assertOk()->json('filas');
    expect(collect($filas)->pluck('oficina')->filter()->unique()->values()->all())->toBe(['TEPEJI'])
        ->and(collect($filas)->firstWhere('tipo', 'gran_total')['sano_pc'])->toEqual(100.0);

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

it('exporta Excel por HTTP del mismo resultado, respeta los filtros y borra el archivo tras enviarlo', function () {
    // Captura las filas que llegan al exportador: prueba el filtrado de extremo a extremo
    // sin depender de parsear el xlsx. El exportador real se cubre en su propio test unitario.
    $capturado = [];
    $this->mock(EfectividadCalculadoraExcelExporter::class, function ($m) use (&$capturado) {
        $m->shouldReceive('export')->andReturnUsing(function ($r, $out) use (&$capturado) {
            $capturado[] = $r['filas'];
            file_put_contents($out, "PK\x03\x04prueba-xlsx"); // firma ZIP (como un .xlsx real)

            return $out;
        });
    });

    $svc = app(EfectividadCalculadoraService::class);
    $token = $svc->agregarArchivos(null, $this->admin->id, array_map('calcUpload', calcArchivosSemana($this->dir)))['token'];
    $svc->calcular($token, $this->admin->id, 'SEMANAL', '2026-10-11');

    // Descarga sin filtro: 200, nombre .xlsx y tipo de contenido de hoja de cálculo.
    $sinFiltro = $this->actingAs($this->admin)->get("/efectividad-cobranza/calculadora/$token/exportar-excel")->assertOk();
    expect($sinFiltro->headers->get('content-type'))->toContain('spreadsheetml')
        ->and($sinFiltro->headers->get('content-disposition'))->toContain('.xlsx');
    $sinFiltro->sendContent(); // dispara deleteFileAfterSend

    // Mismo endpoint con filtro por oficina: al exportador llegan solo las filas de esa ruta.
    $this->actingAs($this->admin)->get("/efectividad-cobranza/calculadora/$token/exportar-excel?oficina=ACAMBAY")->assertOk()->sendContent();

    $promSinFiltro = collect($capturado[0])->where('tipo', 'promotor')->pluck('promotor')->all();
    $promConFiltro = collect($capturado[1])->where('tipo', 'promotor')->pluck('promotor')->all();
    expect($promSinFiltro)->toContain('ANA LOPEZ', 'BETO RUIZ')
        ->and($promConFiltro)->toBe(['BETO RUIZ']) // solo la ruta ACAMBAY
        ->and(glob(EfectividadWorkspace::raiz().'/exports/*'))->toBe([]); // nada queda en disco
});

it('el Excel descargado de un cálculo real trae logo, formato del PDF y gran total', function () {
    $svc = app(EfectividadCalculadoraService::class);
    $token = $svc->agregarArchivos(null, $this->admin->id, array_map('calcUpload', calcArchivosSemana($this->dir)))['token'];
    $svc->calcular($token, $this->admin->id, 'SEMANAL', '2026-10-11');

    $respuesta = $this->actingAs($this->admin)->get("/efectividad-cobranza/calculadora/$token/exportar-excel")->assertOk();
    $copia = $this->dir.'/descarga.xlsx';
    copy($respuesta->getFile()->getPathname(), $copia);
    $respuesta->sendContent();
    $ws = IOFactory::load($copia)->getSheetByName('EFECTIVIDAD');

    $gt = collect($svc->resultado($token, $this->admin->id)['filas'])->firstWhere('tipo', 'gran_total');
    expect($ws->getDrawingCollection())->toHaveCount(1)
        ->and($ws->getDrawingCollection()[0]->getCoordinates())->toBe('A1')
        ->and($ws->getCell('B2')->getValue())->toBe('Efectividad de Cobranza')
        ->and((string) $ws->getCell('B3')->getValue())->toContain('Metodología')
        ->and($ws->getCell('H7')->getValue())->toEqual($gt['cobranza_total'])
        ->and(glob(EfectividadWorkspace::raiz().'/exports/*'))->toBe([]);
});

it('el botón de Excel exige el permiso efectividad.exportar_excel: sin él es 403 aunque el PDF sí funcione', function () {
    // Gerencial trae por defecto calcular+exportar (PDF/imagen) pero NO exportar_excel.
    $gerente = User::factory()->create(['role' => 'gerencial']);
    $svc = app(EfectividadCalculadoraService::class);
    $token = $svc->agregarArchivos(null, $gerente->id, array_map('calcUpload', calcArchivosSemana($this->dir)))['token'];
    $svc->calcular($token, $gerente->id, 'SEMANAL', '2026-10-11');

    // El gate de permiso corta ANTES del controlador: 403 para Excel.
    $this->actingAs($gerente)->get("/efectividad-cobranza/calculadora/$token/exportar-excel")->assertForbidden();

    // Pero su permiso de exportar sí le deja el PDF (422 = pasó el gate; aquí solo validamos el acceso).
    $this->mock(BrowsershotPdfRenderer::class, function ($m) {
        $m->shouldReceive('renderHtmlToFile')->andReturnUsing(fn ($h, $out) => file_put_contents($out, '%PDF-1.4'));
    });
    $this->actingAs($gerente)->get("/efectividad-cobranza/calculadora/$token/exportar/pdf")->assertOk();
});

it('el TTL elimina espacios temporales abandonados', function () {
    $ws = app(EfectividadWorkspace::class);
    $token = $ws->crear($this->admin->id);
    touch(EfectividadWorkspace::raiz().'/'.$token, time() - 2 * 3600);

    $this->artisan('efectividad:limpiar-temporales')->assertSuccessful();
    expect(is_dir(EfectividadWorkspace::raiz().'/'.$token))->toBeFalse();
});

it('una ruta fuera del catálogo se calcula igual y se muestra en SIN SUCURSAL ASIGNADA con aviso', function () {
    $svc = app(EfectividadCalculadoraService::class);
    $nueva = [['RUTA NUEVA', 'P4', 'CARLA DIAZ', 'C4', 'A4', 'ACTIVO', 'VIGENTE', 'SEMANAL 12', '2026-10-05', '2026-12-28', 50, 'SEMANAL', 12, 0, 0, 500, 450]];
    $archivos = [];
    foreach (range(4, 11) as $d) {
        $archivos[] = calcCartera($this->dir, sprintf('2026-10-%02d', $d), "c$d.xlsx", $nueva);
    }
    $archivos[] = calcIngresos($this->dir, 'ing.xlsx', [calcPago('C4', '2026-10-05', '2026-10-05', 50, 'RUTA NUEVA', 'CARLA DIAZ')]);

    $r = $svc->agregarArchivos(null, $this->admin->id, array_map('calcUpload', $archivos));
    $res = $svc->calcular($r['token'], $this->admin->id, 'SEMANAL', '2026-10-11');

    expect(collect($r['resumen']['sucursales'])->firstWhere('sucursal', 'SIN SUCURSAL ASIGNADA')['rutas'])->toBe(['RUTA NUEVA'])
        ->and(collect($res['filas'])->firstWhere('promotor', 'CARLA DIAZ')['sucursal'])->toBe('SIN SUCURSAL ASIGNADA')
        ->and(collect($res['filas'])->firstWhere('tipo', 'gran_total')['sano_pc'])->toEqual(350.0)
        ->and(collect($res['avisos'])->contains(fn ($a) => str_contains($a, 'RUTA NUEVA')))->toBeTrue()
        // Sin sucursal asignada va al final de la tabla.
        ->and(collect($res['filas'])->where('tipo', 'subtotal_sucursal')->last()['promotor'])->toBe('TOTAL SUCURSAL SIN SUCURSAL ASIGNADA');
});

it('cada modalidad pide solo sus archivos: diario con domingo + día + ingresos de ese día; sin ingresos no calcula', function () {
    $svc = app(EfectividadCalculadoraService::class);
    $up = fn (array $p) => array_map('calcUpload', $p);
    $r = $svc->agregarArchivos(null, $this->admin->id, $up([
        calcCartera($this->dir, '2026-10-04', 'x.xlsx'), calcCartera($this->dir, '2026-10-05', 'y.xlsx'),
    ]));
    $t = $r['token'];
    $lunes = collect($r['resumen']['cortes']['DIARIO'])->firstWhere('valor', '2026-10-05');

    // Solo 2 carteras + ingresos del lunes; ningún otro día de la semana es requisito.
    expect(collect($lunes['requisitos'])->pluck('etiqueta')->all())->toBe([
        'Cartera base (domingo) 04/10', 'Cartera del lunes 05/10', 'Ingresos Cobranza del lunes 05/10',
    ])
        ->and($lunes['estado'])->toBe('faltan_ingresos')
        ->and($lunes['falta'])->toBe('Solo falta cargar Ingresos Cobranza del lunes 05/10 para calcular.')
        ->and(collect($r['resumen']['cortes']['SEMANAL'])->firstWhere('valor', '2026-10-11')['estado'])->toBe('faltan_carteras');
    expect(fn () => $svc->calcular($t, $this->admin->id, 'DIARIO', '2026-10-05'))
        ->toThrow(App\Services\Efectividad\Calculadora\EfectividadArchivoException::class, 'Solo falta cargar Ingresos Cobranza');

    // Un único Excel de ingresos de toda la semana habilita el lunes (solo usa sus movimientos del día).
    $r = $svc->agregarArchivos($t, $this->admin->id, $up([calcIngresos($this->dir, 'ing.xlsx', [
        calcPago('C1', '2026-10-05', '2026-10-05', 100), calcPago('C2', '2026-10-06', '2026-10-06', 150, 'ACAMBAY', 'BETO RUIZ'),
    ])]));
    expect(collect($r['resumen']['cortes']['DIARIO'])->firstWhere('valor', '2026-10-05')['listo'])->toBeTrue();
    $gt = collect($svc->calcular($t, $this->admin->id, 'DIARIO', '2026-10-05')['filas'])->firstWhere('tipo', 'gran_total');
    expect($gt['sano_pc'])->toEqual(100.0)->and($gt['cobranza_total'])->toEqual(100.0);

    // Acumulado al martes: pide domingo, lunes y martes; con la cartera del martes ya calcula.
    $r = $svc->agregarArchivos($t, $this->admin->id, $up([calcCartera($this->dir, '2026-10-06', 'z.xlsx')]));
    $acu = collect($r['resumen']['cortes']['ACUMULADO'])->firstWhere('valor', '2026-10-06');
    expect(collect($acu['requisitos'])->where('tipo', 'cartera')->count())->toBe(3)->and($acu['listo'])->toBeTrue();
    $gt = collect($svc->calcular($t, $this->admin->id, 'ACUMULADO', '2026-10-06')['filas'])->firstWhere('tipo', 'gran_total');
    expect($gt['sano_pc'])->toEqual(300.0)->and($gt['cobranza_total'])->toEqual(250.0);
    // Los archivos se conservan entre modalidades: el diario del lunes sigue disponible.
    expect($svc->calcular($t, $this->admin->id, 'DIARIO', '2026-10-05')['titulo'])->toBe('CORTE DIARIO: 05/10/2026');
});
