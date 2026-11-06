<?php

use App\Enums\ReportUploadStatus;
use App\Models\DataSource;
use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosPayrollLine;
use App\Models\EstadoResultados\EstadoResultadosPayrollUpload;
use App\Models\ReportUpload;
use App\Services\EstadoResultados\Calculation\EstadoResultadosDatasetBuilder;
use App\Services\EstadoResultados\Import\CorporatePayrollUploadService;
use App\Services\EstadoResultados\Import\EstadoResultadosImportException;
use App\Services\EstadoResultados\Import\EstadoResultadosUploadService;
use App\Services\EstadoResultados\Reporting\ReportStatusEvaluator;
use App\Services\EstadoResultados\Support\Treatment as T;
use Illuminate\Support\Facades\Artisan;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\EstadoResultadosFixtures as F;

/**
 * Fuentes del Estado de Resultados: NOI de corporativo (fuente propia, sin doble conteo),
 * lectura del ERP desde Carga de archivos con diagnóstico exacto y comando de diagnóstico.
 * Datos 100 % sintéticos.
 */

/** NOI sintético con el MISMO layout que el NOI de sucursales (bloque «Clave del trabajador», concepto, importe). */
function noiCorpXlsx(array $employees): string
{
    $book = new Spreadsheet;
    $ws = $book->getActiveSheet();
    $ws->fromArray(['MR LANA · NÓMINA CORPORATIVO (sintético)'], null, 'A1');
    $ws->fromArray(['CLAVE', 'NOMBRE DEL TRABAJADOR', 'CONCEPTO', 'IMPORTE'], null, 'A3');
    $r = 4;
    foreach ($employees as $code => [$name, $concepts]) {
        $ws->fromArray([null, 'Clave del trabajador : '.$code], null, 'A'.$r++);
        $first = true;
        foreach ($concepts as [$concept, $amount]) {
            $ws->fromArray([null, $first ? $name : null, $concept, $amount], null, 'A'.$r++);
            $first = false;
        }
    }
    $path = tempnam(sys_get_temp_dir(), 'noicorp').'.xlsx';
    (new Xlsx($book))->save($path);

    return $path;
}

function noiCorpUpload($period, string $path): array
{
    return app(CorporatePayrollUploadService::class)->importPath($period, $path, basename($path), null);
}

function fBuild($period): array
{
    return app(EstadoResultadosDatasetBuilder::class)->build($period, 'CONSOLIDADO');
}

function fCheck(array $d, string $key): array
{
    return collect($d['sources']['checklist']['items'])->firstWhere('key', $key);
}

beforeEach(function () {
    F::branch('CORDOBA');
    F::branch('CORPORATIVO');
    F::fakeRadiography();
});

it('NOI de corporativo: suma Σ percepciones a Nómina corporativa, ignora deducciones y totales, y es incremental e idempotente', function () {
    $period = F::period();
    $bankPath = F::adminXlsx([['2026-09-03', 1500.00, null, 'REQ-X', 'PAPELERIA', 'CORPORATIVO']]);
    app(EstadoResultadosUploadService::class)->importPath($period, $bankPath, 'banco.xlsx', 'administrativa', null);
    $before = fBuild($period);
    $movementsBefore = EstadoResultadosMovement::query()->where('period_id', $period->id)->count();
    expect($before['controls']['nomina_corporativa'])->toBe('0.00')
        ->and(fCheck($before, 'noi_corporativo'))->toMatchArray(['state' => 'falta', 'required' => false, 'where' => 'er']);

    $path = noiCorpXlsx([
        7 => ['ANA LOPEZ PEREZ', [['P001 SUELDO', 20000], ['P010 BONO', 5000], ['D001 ISR', -3000], ['Total percepciones', 25000]]],
        8 => ['LUIS GARCIA RUIZ', [['P001 SUELDO', 12000]]],
    ]);
    $res = noiCorpUpload($period, $path);
    expect($res['idempotent'])->toBeFalse()
        ->and($res['upload']->employees_count)->toBe(2)
        ->and((string) $res['upload']->total_percepciones)->toBe('37000.00')
        ->and((string) $res['upload']->total_deducciones)->toBe('3000.00');

    $d = fBuild($period);
    expect($d['controls']['nomina_corporativa'])->toBe('37000.00')
        ->and($d['results']['ebitda'])->toBe(bcsub($before['results']['ebitda'], '37000.00', 2))
        ->and($d['controls']['ingresos'])->toBe($before['controls']['ingresos'])
        ->and(collect($d['checks'])->where('ok', false)->pluck('key')->all())->toBe([])
        ->and(fCheck($d, 'noi_corporativo')['state'])->toBe('lista');
    $items = collect($d['movements'])->where('account_slot', 'noi_corporativo');
    expect($items)->toHaveCount(2)
        ->and($items->pluck('treatment')->unique()->all())->toBe([T::NOMINA_CORPORATIVA]);

    // Incremental: no se tocaron los movimientos bancarios ni se reprocesó nada más.
    expect(EstadoResultadosMovement::query()->where('period_id', $period->id)->count())->toBe($movementsBefore);

    // Idempotente: el mismo archivo no se vuelve a cargar.
    $again = noiCorpUpload($period, $path);
    expect($again['idempotent'])->toBeTrue()
        ->and(EstadoResultadosPayrollUpload::query()->where('period_id', $period->id)->count())->toBe(1);

    // Otro archivo sustituye sólo los renglones del NOI anterior.
    noiCorpUpload($period, noiCorpXlsx([9 => ['ANA LOPEZ PEREZ', [['P001 SUELDO', 21000]]]]));
    expect(EstadoResultadosPayrollUpload::query()->where('period_id', $period->id)->where('status', 'reemplazado')->count())->toBe(1)
        ->and(EstadoResultadosPayrollLine::query()->where('period_id', $period->id)->count())->toBe(1)
        ->and(fBuild($period)['controls']['nomina_corporativa'])->toBe('21000.00')
        ->and(EstadoResultadosMovement::query()->where('period_id', $period->id)->count())->toBe($movementsBefore);
});

it('NOI de corporativo: no cuenta dos veces lo que ya está en el NOI de sucursales ni pagos de banco/ERP del mismo colaborador', function () {
    $period = F::period();
    F::noiEmployee($period, 'LUIS GARCIA RUIZ', null, '12000.00'); // ya en Radiografía (NOI de sucursales)
    F::erp($period, 'CORPORATIVO', 'REQ-N1', '20000.00', '2026-09-10', 'Nómina y Capital Humano', 'PAGO NOMINA ANA LOPEZ PEREZ');
    F::erp($period, 'CORPORATIVO', 'REQ-N2', '3000.00', '2026-09-11', 'Nómina y Capital Humano', 'FINIQUITO');
    noiCorpUpload($period, noiCorpXlsx([
        7 => ['ANA LOPEZ PEREZ', [['P001 SUELDO', 20000]]],
        8 => ['LUIS GARCIA RUIZ', [['P001 SUELDO', 12000]]],
    ]));

    $d = fBuild($period);
    $mov = collect($d['movements']);
    $luis = $mov->first(fn ($m) => $m['account_slot'] === 'noi_corporativo' && $m['beneficiary'] === 'LUIS GARCIA RUIZ');
    $erpAna = $mov->firstWhere('folio', 'REQ-N1');
    expect($luis['treatment'])->toBe(T::CUBIERTO_RADIOGRAFIA)
        ->and($luis['impact']['included'])->toBeFalse()
        ->and($erpAna['treatment'])->toBe(T::PENDIENTE)
        ->and($erpAna['suggested_treatment'])->toBe(T::CUBIERTO_NOI_CORPORATIVO)
        // 20,000 (Ana desde NOI de corporativo) + 3,000 (finiquito ERP, por confirmar) — nunca 12,000 de Luis ni 20,000 del pago ERP.
        ->and($d['controls']['nomina_corporativa'])->toBe('23000.00')
        ->and(collect($d['checks'])->where('ok', false)->pluck('key')->all())->toBe([]);

    $unresolved = collect($d['validation']['unresolved'])->keyBy('key');
    expect($unresolved['noi_corporativo_en_noi_sucursales']['blocking'])->toBeFalse()
        ->and($unresolved['noi_corporativo_doble_conteo']['blocking'])->toBeTrue()
        ->and($unresolved['noi_corporativo_doble_conteo']['open_amount'])->toBe('3000.00');
});

it('sin NOI de corporativo el dataset no cambia: no hay items ni validaciones nuevas y no bloquea la aprobación', function () {
    $period = F::period();
    F::erp($period, 'CORPORATIVO', 'REQ-C1', '7000.00', '2026-09-05', 'Luz', 'LUZ CORPORATIVO');
    $d = fBuild($period);

    expect(collect($d['movements'])->where('source', 'noi_corp'))->toHaveCount(0)
        ->and(collect($d['validation']['unresolved'])->pluck('key')->filter(fn ($k) => str_starts_with($k, 'noi_corporativo'))->all())->toBe([])
        ->and(implode(' ', app(ReportStatusEvaluator::class)->evaluate($d)['preliminary_reasons']))->not->toContain('NOI de corporativo');
});

it('rechaza un archivo que no tiene la estructura del NOI sin guardar nada', function () {
    $period = F::period();
    expect(fn () => noiCorpUpload($period, F::invalidXlsx()))->toThrow(EstadoResultadosImportException::class);
    expect(EstadoResultadosPayrollUpload::query()->count())->toBe(0);
});

it('ERP subido en Carga de archivos pero sin «Cargar registros»: el ER dice exactamente qué pasa y qué hacer', function () {
    $period = F::period();
    $source = DataSource::query()->firstOrCreate(['code' => 'gastos_erp'], ['name' => 'Gastos ERP (Requisiciones)', 'description' => 'ERP', 'is_active' => true]);
    ReportUpload::query()->create([
        'period_id' => $period->id, 'data_source_id' => $source->id, 'original_name' => 'ERP OCTUBRE.xlsx', 'stored_path' => 'reports/erp.xlsx',
        'mime_type' => 'application/vnd.ms-excel', 'file_size' => 10, 'uploaded_at' => now(), 'status' => ReportUploadStatus::Pending,
    ]);

    $d = fBuild($period);
    $erp = fCheck($d, 'erp');
    expect($d['sources']['erp']['status'])->toBe('pendiente')
        ->and($erp['state'])->toBe('con_problemas')
        ->and($erp['where'])->toBe('carga')
        ->and($erp['diagnosis']['reason'])->toBe('archivo_sin_cargar')
        ->and($erp['message'])->toContain('ERP OCTUBRE.xlsx')
        ->and($erp['fix'])->toContain('Cargar registros');

    $reasons = app(ReportStatusEvaluator::class)->evaluate($d)['preliminary_reasons'];
    expect(collect($reasons)->first(fn ($r) => str_contains($r, 'ERP')))
        ->toContain('Falta el ERP de gastos de '.$period->label)->toContain('Cargar registros');
    expect(collect($reasons)->first(fn ($r) => str_contains($r, 'excedentes')))->toContain('Súbelo aquí');
});

it('er:diagnosticar-fuentes explica por qué falta el ERP (sólo lectura)', function () {
    $period = F::period();
    $source = DataSource::query()->firstOrCreate(['code' => 'gastos_erp'], ['name' => 'Gastos ERP (Requisiciones)', 'description' => 'ERP', 'is_active' => true]);
    ReportUpload::query()->create([
        'period_id' => $period->id, 'data_source_id' => $source->id, 'original_name' => 'ERP OCTUBRE.xlsx', 'stored_path' => 'reports/erp.xlsx',
        'mime_type' => 'application/vnd.ms-excel', 'file_size' => 10, 'uploaded_at' => now(), 'status' => ReportUploadStatus::Pending,
    ]);
    $uploads = ReportUpload::query()->count();

    expect(Artisan::call('er:diagnosticar-fuentes', ['periodo' => (string) $period->id]))->toBe(0);
    $out = Artisan::output();
    expect($out)->toContain('archivo_sin_cargar')->toContain('Cargar registros')->toContain('ERP OCTUBRE.xlsx');

    Artisan::call('er:diagnosticar-fuentes', ['periodo' => $period->code, '--json' => true]);
    $json = json_decode(Artisan::output(), true);
    expect($json['carga_de_archivos']['erp']['reason'])->toBe('archivo_sin_cargar')
        ->and($json['carga_de_archivos']['erp']['uploads'][0]['status'])->toBe('pending')
        ->and(ReportUpload::query()->count())->toBe($uploads);

    expect(Artisan::call('er:diagnosticar-fuentes', ['periodo' => 'no-existe']))->toBe(1);
});
