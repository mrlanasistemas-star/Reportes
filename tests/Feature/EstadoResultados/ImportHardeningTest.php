<?php

use App\Models\EstadoResultados\EstadoResultadosImportAttempt;
use App\Models\EstadoResultados\EstadoResultadosImportError;
use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\EstadoResultadosProcessor;
use App\Services\EstadoResultados\Export\EstadoResultadosExcelExporter;
use App\Services\EstadoResultados\Support\SucursalNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * Endurecimiento de la carga de estados de cuenta + neutralización de inyección de
 * fórmulas en el Excel exportado.
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
    F::fakeRadiography();
    Storage::fake('local');
});

function ihPost($test, $user, $period, UploadedFile $file, string $slot = 'administrativa')
{
    return $test->actingAs($user)->post("/estado-resultados/{$period->id}/cargas", ['slot' => $slot, 'file' => $file], ['Accept' => 'application/json']);
}

function ihFile(string $path, string $name = 'estado.xlsx'): UploadedFile
{
    return new UploadedFile($path, $name, null, null, true);
}

it('un archivo inválido no publica nada, registra el intento con sus errores por fila y conserva el original', function () {
    $period = F::period();
    $admin = G::admin();
    $book = IOFactory::load(F::adminXlsx([
        ['2026-09-10', 100.00, null, 'S/R', 'PAGO LIMPIEZA', 'CORPORATIVO'],
        ['2026-09-11', 200.00, null, 'S/R', 'PAGO X', 'CORPORATIVO'],
        ['2026-09-12', 300.00, null, 'S/R', 'PAGO Y', 'CORPORATIVO'],
    ]));
    $book->getActiveSheet()->setCellValue('B4', 'no-es-fecha');
    $book->getActiveSheet()->setCellValue('D5', 'abc');
    $bad = storage_path('framework/testing/er/bad_'.uniqid().'.xlsx');
    (new Xlsx($book))->save($bad);

    $res = ihPost($this, $admin, $period, ihFile($bad, 'septiembre.xlsx'))->assertStatus(422);

    expect($res->json('message'))->toContain('2 fila(s) con error')
        ->and($res->json('errors'))->toBe([
            ['row' => 4, 'column' => 'FECHA', 'message' => "Fila 4: FECHA vacía o inválida ('no-es-fecha') en un movimiento con importe."],
            ['row' => 5, 'column' => 'CARGO', 'message' => "Fila 5: CARGO no es un importe válido ('abc')."],
        ])
        ->and($res->json('attempt_id'))->toBeInt();

    $attempt = EstadoResultadosImportAttempt::query()->findOrFail($res->json('attempt_id'));
    expect($attempt->status)->toBe('rechazado')
        ->and($attempt->errors_count)->toBe(2)
        ->and($attempt->original_name)->toBe('septiembre.xlsx')
        ->and($attempt->file_hash)->toBe(hash_file('sha256', $bad))
        ->and($attempt->stored_path)->not->toBeNull()
        ->and(EstadoResultadosImportError::query()->where('attempt_id', $attempt->id)->pluck('row_number')->all())->toBe([4, 5])
        ->and(EstadoResultadosUpload::query()->count())->toBe(0)
        ->and(EstadoResultadosMovement::query()->count())->toBe(0);
    Storage::disk('local')->assertExists($attempt->stored_path);

    $attempts = $this->actingAs($admin)->getJson("/estado-resultados/{$period->id}/cargas/intentos")->assertOk()->json('attempts');
    expect($attempts[0]['status'])->toBe('rechazado')
        ->and($attempts[0]['status_label'])->toBe('Rechazado')
        ->and($attempts[0]['errors'][0]['row'])->toBe(4)
        ->and($attempts[0]['original_kept'])->toBeTrue();
});

it('rechaza archivos que exceden el tamaño máximo (configurable, 10 MB por defecto)', function () {
    $period = F::period();
    $admin = G::admin();

    ihPost($this, $admin, $period, UploadedFile::fake()->create('enorme.xlsx', 11 * 1024))
        ->assertStatus(422)
        ->assertJsonPath('message', fn ($m) => str_contains($m, 'excede el máximo permitido de 10 MB'));

    config(['estado_resultados.import.max_mb' => 0.001]);
    ihPost($this, $admin, $period, ihFile(F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']])))
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'excede el máximo'));

    expect(EstadoResultadosUpload::query()->count())->toBe(0)
        ->and(EstadoResultadosImportAttempt::query()->where('status', 'rechazado')->count())->toBe(2);
});

it('valida extensión y contenido real del archivo', function () {
    $period = F::period();
    $admin = G::admin();
    $txt = storage_path('framework/testing/er/falso_'.uniqid().'.txt');
    @mkdir(dirname($txt), 0775, true);
    file_put_contents($txt, "FECHA,CARGO,ABONO\n2026-09-10,1,0\n");

    ihPost($this, $admin, $period, ihFile($txt, 'estado.csv'))
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Formato no permitido (.csv)'));
    ihPost($this, $admin, $period, ihFile($txt, 'renombrado.xlsx'))
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'no corresponde a un Excel'));
    // Estructura (encabezados) inválida.
    ihPost($this, $admin, $period, ihFile(F::invalidXlsx()))
        ->assertStatus(422)->assertJsonPath('message', fn ($m) => str_contains($m, 'Estructura no reconocida'));

    expect(EstadoResultadosUpload::query()->count())->toBe(0);
});

it('impide dos cargas simultáneas de la misma cuenta y periodo (candado)', function () {
    $period = F::period();
    $admin = G::admin();
    $lock = Cache::lock('estado_resultados:carga:'.$period->id.':administrativa', 300);
    expect($lock->get())->toBeTrue();

    ihPost($this, $admin, $period, ihFile(F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']])))
        ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'Ya hay una carga en curso'));
    // Otra cuenta del mismo periodo no está bloqueada.
    ihPost($this, $admin, $period, ihFile(F::excedentesXlsx([['2026-09-01', null, 100.00, 'RETIRO EXCEDENTE', 'ORIZABA', null]])), 'excedentes')->assertOk();
    expect(EstadoResultadosUpload::query()->where('account_slot', 'administrativa')->count())->toBe(0);

    $lock->release();
    ihPost($this, $admin, $period, ihFile(F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']])))->assertOk();
    expect(EstadoResultadosUpload::query()->where('account_slot', 'administrativa')->count())->toBe(1);
});

it('reintentar la misma carga es idempotente: responde OK sin duplicar movimientos', function () {
    $period = F::period();
    $admin = G::admin();
    $path = F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO'], ['2026-09-11', 50.00, null, 'S/R', 'PAGO 2', 'CORPORATIVO']]);

    $first = ihPost($this, $admin, $period, ihFile($path))->assertOk()->assertJsonPath('idempotent', false);
    $retry = ihPost($this, $admin, $period, ihFile($path))->assertOk()->assertJsonPath('idempotent', true);

    expect($retry->json('upload.id'))->toBe($first->json('upload.id'))
        ->and(EstadoResultadosUpload::query()->count())->toBe(1)
        ->and(EstadoResultadosMovement::query()->count())->toBe(2)
        ->and(EstadoResultadosImportAttempt::query()->orderBy('id')->pluck('status')->all())->toBe(['completado', 'duplicado']);

    // El mismo archivo en la OTRA cuenta no es un reintento: es un error.
    ihPost($this, $admin, $period, ihFile($path), 'excedentes')->assertStatus(422);
});

it('una falla a mitad de la escritura no deja una carga parcial publicada', function () {
    $period = F::period();
    $prev = G::import($period, F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO PREVIO', 'CORPORATIVO']]));
    $calls = 0;
    app()->instance(SucursalNormalizer::class, new class($calls) extends SucursalNormalizer
    {
        public function __construct(private int &$calls) {}

        public function normalize(?string $raw): array
        {
            if (++$this->calls === 2) {
                throw new RuntimeException('Disco lleno (simulado)');
            }

            return ['name' => 'CORPORATIVO', 'kind' => 'corporativo'];
        }
    });

    expect(fn () => G::import($period, F::adminXlsx([['2026-09-10', 1.00, null, 'S/R', 'A', 'CORPORATIVO'], ['2026-09-11', 2.00, null, 'S/R', 'B', 'CORPORATIVO']])))
        ->toThrow(RuntimeException::class, 'Disco lleno');

    // La carga anterior sigue activa e intacta; la nueva no existe.
    expect(EstadoResultadosUpload::query()->active()->pluck('id')->all())->toBe([$prev->id])
        ->and(EstadoResultadosMovement::query()->pluck('concept')->all())->toBe(['PAGO PREVIO'])
        ->and(EstadoResultadosImportAttempt::query()->latest('id')->first()->status)->toBe('rechazado');
});

it('neutraliza la inyección de fórmulas en el Excel y conserva las fórmulas de validación propias', function () {
    F::fakeRadiography(['gastos_detalle' => ['=HYPERLINK("http://malicioso","clic")' => 12000.00, 'Renta Oficina' => 8000.00]]);
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-Z1', '700.00', '2026-09-05', '@SUM(A1:A9)', '=cmd|\' /C calc\'!A0');
    G::import($period, F::adminXlsx([
        ['2026-09-10', 100.00, null, 'S/R', '+cmd|calc', 'CORPORATIVO', '-2+3'],
        ['2026-09-11', 50.00, null, 'S/R', "\tTAB", 'CORPORATIVO', '@proveedor'],
    ]));
    app(EstadoResultadosProcessor::class)->process($period);
    $path = storage_path('framework/testing/er/inj_'.uniqid().'.xlsx');
    app(EstadoResultadosExcelExporter::class)->export(app(EstadoResultadosDatasetBuilder::class)->build($period), $path);

    $book = IOFactory::load($path);
    $dangerous = [];
    $formulas = [];
    foreach ($book->getWorksheetIterator() as $ws) {
        foreach ($ws->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $v = $cell->getValue();
                if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                    $formulas[] = (string) $v;
                } elseif (is_string($v) && EstadoResultadosExcelExporter::isDangerous($v)) {
                    $dangerous[] = [$ws->getTitle(), $cell->getCoordinate(), $v, $cell->getDataType(), $ws->getStyle($cell->getCoordinate())->getQuotePrefix()];
                }
            }
        }
    }

    $values = array_column($dangerous, 2);
    expect($values)->toContain('=HYPERLINK("http://malicioso","clic")', '+cmd|calc', '-2+3', '@proveedor', '=cmd|\' /C calc\'!A0')
        ->and(collect($dangerous)->every(fn ($c) => $c[3] === DataType::TYPE_STRING && $c[4] === true))->toBeTrue()
        ->and($formulas)->not->toBeEmpty()
        // Las únicas fórmulas son las propias del exportador (referencias a celdas del libro y
        // literales numéricos): ningún texto de origen terminó como fórmula.
        ->and(collect($formulas)->every(fn ($f) => ! preg_match('/HYPERLINK|cmd|calc|proveedor|SUM\(A1:A9\)/i', $f)))->toBeTrue()
        ->and(collect($formulas)->every(fn ($f) => (bool) preg_match('/^=[A-Za-z0-9$:()+\-*\/,.<>"\x{2014} \'!_ÁÉÍÓÚáéíóúñ]+$/u', $f)))->toBeTrue();
});
