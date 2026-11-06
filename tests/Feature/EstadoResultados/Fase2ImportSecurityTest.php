<?php

use App\Models\EstadoResultados\EstadoResultadosImportAttempt;
use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Models\EstadoResultados\EstadoResultadosVersion;
use App\Models\Period;
use App\Services\EstadoResultados\Import\BankStatementParser;
use App\Services\EstadoResultados\Import\EstadoResultadosImportException;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\EstadoResultados\Import\SpreadsheetSafetyInspector;
use App\Services\EstadoResultados\Reporting\EstadoResultadosLockedException;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\EstadoResultadosFixtures as F;
use Tests\Support\EstadoResultadosGovernanceFixtures as G;

/**
 * Fase 2 — seguridad de la carga de estados de cuenta: contenedor ZIP (bomba, dañado,
 * macros), dimensiones, fórmulas externas, avisos informativos vs. bloqueantes, ciclo
 * de vida del archivo y bloqueo de reemplazos sobre un periodo aprobado.
 * Todos los libros son SINTÉTICOS (generados en la prueba).
 */
beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
    F::fakeRadiography();
    Storage::fake('local');
});

function f2Dir(): string
{
    $dir = storage_path('framework/testing/er');
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

function f2Save(Spreadsheet $book, string $ext = 'xlsx', bool $precalc = true): string
{
    $path = f2Dir().'/'.uniqid('f2_', true).'.'.$ext;
    $w = new Xlsx($book);
    $w->setPreCalculateFormulas($precalc);
    $w->save($path);

    return $path;
}

/** Copia un xlsx agregando partes extra al ZIP. */
function f2WithParts(string $source, array $parts, string $ext): string
{
    $path = f2Dir().'/'.uniqid('f2_', true).'.'.$ext;
    copy($source, $path);
    $zip = new ZipArchive;
    $zip->open($path);
    foreach ($parts as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();

    return $path;
}

function f2Import(Period $period, string $path, string $slot = 'administrativa'): EstadoResultadosUpload
{
    return G::import($period, $path, $slot);
}

function f2Rejects(Period $period, string $path, string $contains): EstadoResultadosImportException
{
    try {
        f2Import($period, $path);
    } catch (EstadoResultadosImportException $e) {
        expect($e->getMessage().' '.implode(' ', $e->details()))->toContain($contains);

        return $e;
    }
    throw new RuntimeException('Se esperaba que la carga se rechazara: '.$contains);
}

function f2LockPeriod(Period $period): EstadoResultadosVersion
{
    return EstadoResultadosVersion::query()->create([
        'period_id' => $period->id, 'version' => 1, 'status' => EstadoResultadosVersion::APROBADO,
        'rules_version' => 'test', 'dataset_hash' => str_repeat('a', 64), 'snapshot_hash' => str_repeat('b', 64),
        'scopes' => [], 'sources' => [], 'classifications' => [], 'adjustments' => [], 'approved_at' => now(),
    ]);
}

it('rechaza un .xlsx dañado (cabecera PK con contenido basura) sin publicar nada', function () {
    $period = F::period();
    $path = f2Dir().'/'.uniqid('f2_', true).'.xlsx';
    file_put_contents($path, "PK\x03\x04".random_bytes(4096));

    f2Rejects($period, $path, 'dañado');

    $attempt = EstadoResultadosImportAttempt::query()->latest('id')->first();
    expect($attempt->status)->toBe('rechazado')
        ->and($attempt->lifecycle)->toBe('rechazado')
        ->and(EstadoResultadosUpload::query()->count())->toBe(0);
});

it('rechaza una bomba ZIP por la razón de compresión antes de cargar el libro', function () {
    $period = F::period();
    $path = f2Dir().'/'.uniqid('f2_', true).'.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook/>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet>'.str_repeat(' ', 20 * 1024 * 1024).'</worksheet>');
    $zip->close();
    expect(filesize($path))->toBeLessThan(1024 * 1024); // pequeño en disco, 20 MB al expandirse

    f2Rejects($period, $path, 'bomba ZIP');

    // Con límite total más bajo que la expansión ⇒ también se rechaza (aunque la razón se relaje).
    config(['estado_resultados.import.zip.max_ratio' => 100000, 'estado_resultados.import.zip.max_uncompressed_mb' => 5]);
    f2Rejects($period, $path, 'se expande a más de 5 MB');
    config(['estado_resultados.import.zip.max_uncompressed_mb' => 150, 'estado_resultados.import.zip.max_entries' => 2]);
    f2Rejects($period, $path, 'partes internas');

    expect(EstadoResultadosUpload::query()->count())->toBe(0);
});

it('rechaza hojas con demasiadas columnas o filas (límites configurables, antes de cargar)', function () {
    $period = F::period();
    $book = IOFactory::load(F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']]));
    $book->getActiveSheet()->setCellValue('Z3', 'x');
    $wide = f2Save($book);
    config(['estado_resultados.import.max_columns' => 20]);
    f2Rejects($period, $wide, 'columnas (máximo 20)');

    config(['estado_resultados.import.max_columns' => 100, 'estado_resultados.import.max_rows' => 5]);
    $rows = [];
    for ($i = 1; $i <= 8; $i++) {
        $rows[] = ['2026-09-'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT), 10.0 * $i, null, 'S/R', 'PAGO '.$i, 'CORPORATIVO'];
    }
    f2Rejects($period, F::adminXlsx($rows), 'más de 5 filas');

    expect(EstadoResultadosUpload::query()->count())->toBe(0);
});

it('rechaza fórmulas que toman datos de otro libro en columnas usadas y nunca las evalúa', function () {
    $period = F::period();
    $book = IOFactory::load(F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']]));
    $book->getActiveSheet()->setCellValueExplicit('D3', '=[1]Hoja1!D3', DataType::TYPE_FORMULA);
    $path = f2Save($book, 'xlsx', false);

    $e = f2Rejects($period, $path, 'otros libros');
    expect($e->errors()[0])->toMatchArray(['row' => 3, 'column' => 'D'])
        ->and($e->errors()[0]['message'])->toContain('Pega la columna como valores')
        ->and(EstadoResultadosMovement::query()->count())->toBe(0);

    expect(BankStatementParser::isExternalFormula("='C:\\datos\\[banco.xlsx]Hoja1'!A1"))->toBeTrue()
        ->and(BankStatementParser::isExternalFormula("=cmd|' /C calc'!A0"))->toBeTrue()
        ->and(BankStatementParser::isExternalFormula('=WEBSERVICE("http://x")'))->toBeTrue()
        ->and(BankStatementParser::isExternalFormula('=F2+E3-D3'))->toBeFalse()
        ->and(BankStatementParser::isExternalFormula('=SUM(D3:D9)'))->toBeFalse();
});

it('una fórmula externa fuera de las columnas usadas sólo deja un aviso informativo', function () {
    $period = F::period();
    $book = IOFactory::load(F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']]));
    $book->getActiveSheet()->setCellValue('R1', 'NOTA');
    $book->getActiveSheet()->setCellValueExplicit('R3', '=[1]Hoja1!A1', DataType::TYPE_FORMULA);
    $upload = f2Import($period, f2Save($book, 'xlsx', false));

    $w = collect($upload->warnings_detail)->firstWhere('code', 'vinculos_externos');
    expect($w['severity'])->toBe('informativa')
        ->and($upload->blocking_warnings)->toBe(0)
        ->and($upload->movements_count)->toBe(1);
});

it('lee el valor de las fórmulas internas calculándolas (con o sin valor guardado) y avisa si el guardado difiere', function () {
    $period = F::period();
    $book = IOFactory::load(F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO'], ['2026-09-11', 50.00, null, 'S/R', 'PAGO 2', 'CORPORATIVO']]));
    $ws = $book->getActiveSheet();
    $ws->setCellValue('F3', '=F2-D3+E3');
    $ws->setCellValue('F4', '=F3-D4+E4');
    $ws->setCellValue('D4', '=25*2');

    // Con valores guardados (como lo hace Excel).
    $parsed = app(BankStatementParser::class)->parse(f2Save($book, 'xlsx', true));
    expect($parsed['movements'][0]['saldo'])->toBe('900.00')
        ->and($parsed['movements'][1]['saldo'])->toBe('850.00')
        ->and($parsed['movements'][1]['cargo'])->toBe('50.00')
        ->and(array_column($parsed['warnings_detail'], 'code'))->not->toContain('formulas_recalculadas');

    // Sin valores guardados reales (la herramienta escribió 0): se calcula y se avisa.
    $parsed = app(BankStatementParser::class)->parse(f2Save($book, 'xlsx', false));
    expect($parsed['movements'][1]['saldo'])->toBe('850.00')
        ->and($parsed['movements'][1]['cargo'])->toBe('50.00')
        ->and(collect($parsed['warnings_detail'])->firstWhere('code', 'formulas_recalculadas')['severity'])->toBe('informativa');
});

it('acepta .xlsm sólo valores (aviso de macros) y rechaza un .xlsx con macros', function () {
    $period = F::period();
    $base = F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']]);

    $xlsm = f2WithParts($base, ['xl/vbaProject.bin' => 'FAKE-VBA'], 'xlsm');
    $upload = f2Import($period, $xlsm);
    expect($upload->has_macros)->toBeTrue()
        ->and($upload->warnings)->toContain('El archivo contiene macros: no se ejecutan, sólo se leen valores.')
        ->and(collect($upload->warnings_detail)->firstWhere('code', 'macros')['severity'])->toBe('informativa')
        ->and($upload->blocking_warnings)->toBe(0);

    $period2 = F::period('2026-10-01', '2026-10-31');
    $xlsx = f2WithParts(F::adminXlsx([['2026-10-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']]), ['xl/vbaProject.bin' => 'FAKE-VBA'], 'xlsx');
    f2Rejects($period2, $xlsx, 'contiene macros (VBA)');
});

it('reporta encabezados incompletos y fechas inválidas por fila', function () {
    $period = F::period();
    $book = new Spreadsheet;
    $book->getActiveSheet()->fromArray([['FECHA', 'CARGO', 'ABONO', 'REQ'], [46275, 10, null, 'X']], null, 'A1');
    f2Rejects($period, f2Save($book), 'Falta la columna');

    $book = IOFactory::load(F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']]));
    $book->getActiveSheet()->setCellValue('B3', '31/02/2026');
    $e = f2Rejects($period, f2Save($book), 'fila(s) con error');
    expect($e->errors()[0])->toMatchArray(['row' => 3, 'column' => 'FECHA']);
});

it('avisa (informativo) de renglones idénticos dentro del archivo sin perder ninguno', function () {
    $period = F::period();
    $book = IOFactory::load(F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO'], ['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']]));
    $ws = $book->getActiveSheet();
    $ws->setCellValue('C4', $ws->getCell('C3')->getValue());
    $ws->setCellValue('F4', $ws->getCell('F3')->getValue());
    $upload = f2Import($period, f2Save($book));

    $w = collect($upload->warnings_detail)->firstWhere('code', 'movimientos_repetidos');
    expect($w['severity'])->toBe('informativa')
        ->and($w['message'])->toStartWith('1 movimiento(s) repiten')
        ->and($upload->movements_count)->toBe(2)
        ->and(EstadoResultadosMovement::query()->distinct()->count('fingerprint'))->toBe(2);
});

it('un total de control que no cuadra es un aviso BLOQUEANTE; la falta del renglón de control es informativa', function () {
    $period = F::period();
    $book = IOFactory::load(F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']]));
    $book->getActiveSheet()->setCellValue('D5', 999.99);
    $upload = f2Import($period, f2Save($book));

    expect($upload->control_ok)->toBeFalse()
        ->and($upload->blocking_warnings)->toBe(1)
        ->and(array_column($upload->blockingWarningsList(), 'code'))->toBe(['control_no_cuadra'])
        ->and(EstadoResultadosUpload::blockingWarnings($upload->warnings_detail, false))->toHaveCount(1);

    $payload = app(EstadoResultadosUploadService::class)->attempts($period)[0];
    expect($payload['blocking_warnings'][0]['code'])->toBe('control_no_cuadra')
        ->and($payload['message'])->toContain('bloquean la aprobación');

    $up2 = f2Import($period, F::excedentesXlsx([['2026-09-01', null, 100.00, 'RETIRO EXCEDENTE', 'ORIZABA', null]]), 'excedentes');
    expect(collect($up2->warnings_detail)->firstWhere('code', 'sin_totales_control')['severity'])->toBe('informativa')
        ->and($up2->blocking_warnings)->toBe(0)
        // Compatibilidad: cargas previas a la migración (sin detalle) con control que no cuadra.
        ->and(EstadoResultadosUpload::blockingWarnings(null, false)[0]['code'])->toBe('control_no_cuadra')
        ->and(EstadoResultadosUpload::blockingWarnings(null, null))->toBe([]);
});

it('expone el ciclo de vida del archivo: recibido → validado → procesado → conciliado, rechazado, duplicado, reemplazado', function () {
    $period = F::period();
    $path = F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']]);
    $first = f2Import($period, $path);
    expect($first->lifecycle)->toBe('validado')->and($first->validated_at)->not->toBeNull();

    try {
        f2Import($period, $path);
    } catch (EstadoResultadosImportException) {
    }
    $bad = f2Dir().'/'.uniqid('f2_', true).'.xlsx';
    file_put_contents($bad, "PK\x03\x04basura");
    try {
        f2Import($period, $bad);
    } catch (EstadoResultadosImportException) {
    }
    $second = f2Import($period, F::adminXlsx([['2026-09-12', 70.00, null, 'S/R', 'OTRO', 'CORPORATIVO']]));

    $attempts = collect(app(EstadoResultadosUploadService::class)->attempts($period))->sortBy('id')->values();
    expect($attempts->pluck('lifecycle')->all())->toBe(['reemplazado', 'duplicado', 'rechazado', 'validado'])
        ->and($attempts->pluck('lifecycle_label')->all())->toBe(['Reemplazado', 'Ya estaba cargado', 'Rechazado', 'Validado'])
        ->and($first->fresh()->lifecycle)->toBe('reemplazado');

    expect(EstadoResultadosUpload::markProcessed([$second->id, $first->id]))->toBe(1);
    $second->refresh();
    expect($second->status)->toBe('procesado')->and($second->lifecycle)->toBe('procesado')->and($second->processed_at)->not->toBeNull()
        ->and($first->fresh()->status)->toBe('reemplazado');

    expect(EstadoResultadosUpload::lifecycleFor('cargado', true, false, 0))->toBe('validado')
        ->and(EstadoResultadosUpload::lifecycleFor('procesado', true, true, 0))->toBe('conciliado')
        ->and(EstadoResultadosUpload::lifecycleFor('procesado', null, true, 0))->toBe('conciliado')
        ->and(EstadoResultadosUpload::lifecycleFor('procesado', true, true, 3))->toBe('procesado')
        ->and(EstadoResultadosUpload::lifecycleFor('procesado', false, true, 0))->toBe('procesado')
        ->and(EstadoResultadosUpload::lifecycleFor('procesado', true, true, 0, 1))->toBe('procesado')
        ->and(EstadoResultadosUpload::lifecycleFor('reemplazado', true, true, 0))->toBe('reemplazado');
});

it('no permite reemplazar un archivo en un periodo con versión aprobada (también si se aprueba durante la lectura)', function () {
    $period = F::period();
    $original = f2Import($period, F::adminXlsx([['2026-09-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']]));
    f2LockPeriod($period);

    expect(fn () => f2Import($period, F::adminXlsx([['2026-09-11', 1.00, null, 'S/R', 'X', 'CORPORATIVO']])))
        ->toThrow(EstadoResultadosLockedException::class);
    expect(EstadoResultadosImportAttempt::query()->latest('id')->first()->lifecycle)->toBe('rechazado');

    // Carrera: la versión se aprueba MIENTRAS se lee el archivo (después del primer chequeo).
    $period2 = F::period('2026-10-01', '2026-10-31');
    $prev = f2Import($period2, F::adminXlsx([['2026-10-10', 100.00, null, 'S/R', 'PAGO', 'CORPORATIVO']]));
    app()->instance(BankStatementParser::class, new class(new SpreadsheetSafetyInspector, $period2) extends BankStatementParser
    {
        public function __construct(SpreadsheetSafetyInspector $i, private Period $p)
        {
            parent::__construct($i);
        }

        public function parse(string $absolutePath, ?string $extension = null): array
        {
            $out = parent::parse($absolutePath, $extension);
            f2LockPeriod($this->p);

            return $out;
        }
    });
    expect(fn () => f2Import($period2, F::adminXlsx([['2026-10-11', 5.00, null, 'S/R', 'Y', 'CORPORATIVO']])))
        ->toThrow(EstadoResultadosLockedException::class);

    expect(EstadoResultadosUpload::query()->active()->whereIn('period_id', [$period->id, $period2->id])->orderBy('id')->pluck('id')->all())->toBe([$original->id, $prev->id])
        ->and(EstadoResultadosMovement::query()->where('period_id', $period2->id)->pluck('concept')->all())->toBe(['PAGO']);
});
