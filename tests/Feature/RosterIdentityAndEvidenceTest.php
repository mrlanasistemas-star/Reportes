<?php

use App\Enums\ReportUploadStatus;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Models\User;
use App\Services\CanonicalEmployeeResolver;
use App\Services\Imports\NoiNominaImportService;
use App\Services\Okr\OkrEmployeeBranchResolver;
use App\Services\PeriodEmployeeRosterService;
use App\Services\Roster\EmploymentEligibilityPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/**
 * Cierre 2026-10 — identidad laboral por identificadores y evidencia de
 * directorio válida para la FECHA del periodo:
 *   - un directorio posterior al periodo nunca confirma una baja;
 *   - dos homónimos con clave NOI distinta nunca se fusionan;
 *   - NOI normal + fiscal del mismo empleado cuentan una sola vez;
 *   - registros antiguos (sin evidencia ni categoría) se siguen leyendo.
 */
function riPeriod(int $month): Period
{
    $start = sprintf('2026-%02d-01', $month);
    $names = [3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre'];

    return Period::query()->create([
        'name' => ($names[$month] ?? 'Mes').' 2026', 'code' => 'RI-'.$month.'-'.uniqid(), 'type' => 'monthly', 'year' => 2026, 'month' => $month,
        'sequence' => 1, 'start_date' => $start, 'end_date' => date('Y-m-t', strtotime($start)),
    ]);
}

function riSource(string $code): int
{
    return (int) (DB::table('data_sources')->where('code', $code)->value('id')
        ?? DB::table('data_sources')->insertGetId(['code' => $code, 'name' => $code, 'created_at' => now(), 'updated_at' => now()]));
}

function riUpload(Period $period, string $code, string $name = 'archivo.xls'): int
{
    $user = User::query()->first() ?? User::factory()->create();

    return (int) DB::table('report_uploads')->insertGetId([
        'period_id' => $period->id, 'data_source_id' => riSource($code), 'uploaded_by' => $user->id, 'original_name' => $name,
        'stored_path' => 'x', 'status' => 'processed', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function riEmployee(string $name): Employee
{
    return Employee::query()->where('normalized_name', mb_strtolower($name))->orderBy('id')->first()
        ?? Employee::query()->create(['normalized_name' => mb_strtolower($name), 'full_name' => $name, 'is_active' => true]);
}

/** Nómina del periodo: [nombre => sucursal] o [nombre => [sucursal, fuente]] (fuente noi_nomina|noi_nomina_fiscal|ambos). */
function riPayroll(Period $period, array $people): void
{
    $uploads = [];
    foreach ($people as $name => $spec) {
        [$branch, $source] = is_array($spec) ? $spec : [$spec, 'noi_nomina'];
        $e = $name instanceof Employee ? $name : riEmployee($name);
        foreach ($source === 'ambos' ? ['noi_nomina', 'noi_nomina_fiscal'] : [$source] as $code) {
            $uploads[$code] ??= riUpload($period, $code);
            DB::table('fact_noi_movements')->insert([
                'period_id' => $period->id, 'employee_id' => $e->id, 'report_upload_id' => $uploads[$code], 'concept' => 'P001 SUELDO',
                'amount' => 1000, 'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if ($branch) {
            EmployeeBranchAssignment::query()->updateOrCreate(['period_id' => $period->id, 'employee_id' => $e->id], ['branch_id' => riBranch($branch)->id]);
        }
    }
}

function riBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(['normalized_name' => mb_strtolower($name)], ['code' => substr(strtoupper($name), 0, 6).rand(10, 99), 'name' => $name, 'is_active' => true]);
}

/** Directorio laboral cargado en $period: [nombre => estatus]; $latestHire = fecha de ingreso más reciente del archivo. */
function riDirectory(Period $period, array $rows, ?string $latestHire = null): void
{
    $upload = riUpload($period, 'lendus_directorio_empleados', 'Reporte de empleados.xlsx');
    $first = true;
    foreach ($rows as $name => $status) {
        DB::table('lendus_employee_directory')->insert([
            'report_upload_id' => $upload, 'nombre' => $name, 'normalized_name' => mb_strtolower($name), 'puesto' => 'GESTOR DE CRÉDITO',
            'estatus' => $status, 'fecha_inicio' => $first ? $latestHire : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $first = false;
    }
}

function riBuild(Period ...$periods): array
{
    $out = [];
    foreach ($periods as $p) {
        $out[$p->id] = app(PeriodEmployeeRosterService::class)->buildForPeriod($p);
    }

    return $out;
}

function riRow(Period $period, string $name): object
{
    return DB::table('period_employee_rosters')->where('period_id', $period->id)->where('nombre_normalizado', mb_strtolower($name))->firstOrFail();
}

function riEvidence(object $row): string
{
    return collect(json_decode((string) $row->movement_evidence, true)['items'] ?? [])->map(fn ($i) => $i['fuente'].': '.$i['detalle'])->implode(' | ');
}

/** CSV NOI plano: cada fila [nombre, clave, concepto, importe]. */
function riNoiImport(Period $period, string $sourceCode, array $rows): array
{
    Storage::fake('public');
    $csv = "nombre,clave,concepto,tipo_concepto,importe,cantidad,tipo_nomina,fecha\n";
    foreach ($rows as [$name, $code, $concept, $amount]) {
        $csv .= "{$name},\"Clave del trabajador : {$code}\",{$concept},percepcion,{$amount},1,ordinaria,2026-05-10\n";
    }
    $path = 'report_uploads/ri_'.uniqid().'.csv';
    Storage::disk('public')->put($path, $csv);
    $source = DataSource::query()->firstOrCreate(['code' => $sourceCode], ['name' => $sourceCode, 'description' => $sourceCode, 'is_active' => true]);
    $upload = ReportUpload::query()->create([
        'period_id' => $period->id, 'data_source_id' => $source->id, 'original_name' => "{$sourceCode}.csv", 'stored_path' => $path,
        'mime_type' => 'text/csv', 'file_size' => strlen($csv), 'uploaded_by' => (User::query()->first() ?? User::factory()->create())->id,
        'uploaded_at' => now(), 'status' => ReportUploadStatus::Pending,
    ]);

    return app(NoiNominaImportService::class)->handle($upload->fresh('dataSource'));
}

it('classifies three consecutive periods: alta, continuity and a baja confirmed by the period directory', function () {
    [$abr, $may, $jun] = [riPeriod(4), riPeriod(5), riPeriod(6)];
    riPayroll($abr, ['ANA BASE UNO' => 'ATLIXCO', 'BETO BASE DOS' => 'ATLIXCO']);
    riPayroll($may, ['ANA BASE UNO' => 'ATLIXCO', 'BETO BASE DOS' => 'ATLIXCO', 'CARLA NUEVA TRES' => 'ATLIXCO']);
    riPayroll($jun, ['ANA BASE UNO' => 'ATLIXCO', 'CARLA NUEVA TRES' => 'ATLIXCO']);
    riDirectory($jun, ['BETO BASE DOS' => 'INACTIVO', 'ANA BASE UNO' => 'ACTIVO']);

    $stats = riBuild($abr, $may, $jun);

    expect($stats[$abr->id]['altas'])->toBe(0)
        ->and(riRow($may, 'CARLA NUEVA TRES')->reconciliation_status)->toBe('alta_confirmada')
        ->and($stats[$may->id]['altas_confirmadas'])->toBe(1)
        ->and(riRow($jun, 'CARLA NUEVA TRES')->reconciliation_status)->toBe('activo_confirmado')
        ->and(riRow($jun, 'BETO BASE DOS')->reconciliation_status)->toBe('baja_confirmada')
        ->and($stats[$jun->id]['active_count'])->toBe(2)
        ->and($stats[$jun->id]['con_sucursal'])->toBe(2)
        ->and($stats[$jun->id]['bajas'])->toBe(1)
        ->and($stats[$jun->id]['directorio_tipo'])->toBe('periodo');
});

it('never confirms a baja with a directory dated AFTER the period (October directory for a May absence)', function () {
    [$abr, $may] = [riPeriod(4), riPeriod(5)];
    $oct = riPeriod(10);
    riPayroll($abr, ['ANA SALE DESPUES' => 'ATLIXCO', 'SIGUE BASE' => 'ATLIXCO']);
    riPayroll($may, ['SIGUE BASE' => 'ATLIXCO']);
    riDirectory($oct, ['ANA SALE DESPUES' => 'INACTIVO', 'SIGUE BASE' => 'ACTIVO']);

    $stats = riBuild($abr, $may)[$may->id];
    $ana = riRow($may, 'ANA SALE DESPUES');

    expect($ana->movement_type)->toBe('ausencia')
        ->and($ana->reconciliation_status)->toBe('posible_baja')
        ->and((bool) $ana->requires_review)->toBeTrue()
        ->and($ana->reconciliation_note)->toContain('POSTERIOR')
        ->and(riEvidence($ana))->toContain('Directorio laboral (posterior)')
        ->and($stats['bajas'])->toBe(0)
        ->and($stats['directorio_laboral'])->toBeNull()
        ->and($stats['directorio_posterior'])->toContain('Octubre 2026');
});

it('treats a directory loaded in the period but whose content is months later as POSTERIOR evidence', function () {
    [$abr, $may] = [riPeriod(4), riPeriod(5)];
    riPayroll($abr, ['LUIS FOTO TARDIA' => 'ATLIXCO', 'BASE FOTO' => 'ATLIXCO']);
    riPayroll($may, ['BASE FOTO' => 'ATLIXCO']);
    // Cargado como "Mayo", pero trae ingresos de octubre: es una foto de octubre.
    riDirectory($may, ['LUIS FOTO TARDIA' => 'INACTIVO', 'BASE FOTO' => 'ACTIVO'], '2026-10-05');

    riBuild($abr, $may);

    expect(riRow($may, 'LUIS FOTO TARDIA')->reconciliation_status)->toBe('posible_baja')
        ->and(riRow($may, 'LUIS FOTO TARDIA')->movement_type)->toBe('ausencia');
});

it('marks vigencia desconocida when no directory exists at all', function () {
    [$abr, $may] = [riPeriod(4), riPeriod(5)];
    riPayroll($abr, ['SIN DIRECTORIO UNO' => 'ATLIXCO', 'BASE SIN DIR' => 'ATLIXCO']);
    riPayroll($may, ['BASE SIN DIR' => 'ATLIXCO']);

    $stats = riBuild($abr, $may)[$may->id];

    expect(riRow($may, 'SIN DIRECTORIO UNO')->reconciliation_status)->toBe('vigencia_desconocida')
        ->and($stats['bajas'])->toBe(0)
        ->and($stats['pendientes_conciliacion'])->toBe(1);
});

it('a directory cut shortly after the close confirms an absence but a later baja of someone paid is NOT a contradiction', function () {
    [$abr, $may] = [riPeriod(4), riPeriod(5)];
    riPayroll($abr, ['AUSENTE INACTIVO' => 'ATLIXCO', 'COBRA Y SALE DESPUES' => 'ATLIXCO']);
    riPayroll($may, ['COBRA Y SALE DESPUES' => 'ATLIXCO']);
    // Corte 15/06: dentro de la ventana de cierre de Mayo (31/05 + 31 días).
    riDirectory($may, ['AUSENTE INACTIVO' => 'INACTIVO', 'COBRA Y SALE DESPUES' => 'INACTIVO'], '2026-06-15');

    riBuild($abr, $may);

    expect(riRow($may, 'AUSENTE INACTIVO')->reconciliation_status)->toBe('baja_confirmada');
    $paid = riRow($may, 'COBRA Y SALE DESPUES');
    expect($paid->reconciliation_status)->not->toBe('contradiccion_fuentes')
        ->and($paid->is_active_for_period)->toBeTruthy()
        ->and(riEvidence($paid))->toContain('baja posterior');
});

it('keeps the contradiction when a directory INSIDE the period says INACTIVO for someone being paid', function () {
    [$abr, $may] = [riPeriod(4), riPeriod(5)];
    riPayroll($abr, ['PAGADO PERO INACTIVO' => 'ATLIXCO']);
    riPayroll($may, ['PAGADO PERO INACTIVO' => 'ATLIXCO']);
    riDirectory($may, ['PAGADO PERO INACTIVO' => 'INACTIVO'], '2026-05-20');

    riBuild($abr, $may);

    $row = riRow($may, 'PAGADO PERO INACTIVO');
    expect($row->reconciliation_status)->toBe('contradiccion_fuentes')
        ->and($row->movement_type)->toBe('activo')
        ->and((bool) $row->requires_review)->toBeTrue();
});

it('uses a PRIOR directory: its INACTIVO confirms, its old ACTIVO does not contradict a later absence', function () {
    [$abr, $may] = [riPeriod(4), riPeriod(5)];
    riPayroll($abr, ['INACTIVO DESDE ABRIL' => 'ATLIXCO', 'ACTIVO EN ABRIL' => 'ATLIXCO', 'BASE PREVIA' => 'ATLIXCO']);
    riPayroll($may, ['BASE PREVIA' => 'ATLIXCO']);
    riDirectory($abr, ['INACTIVO DESDE ABRIL' => 'INACTIVO', 'ACTIVO EN ABRIL' => 'ACTIVO', 'BASE PREVIA' => 'ACTIVO']);

    $stats = riBuild($abr, $may)[$may->id];

    expect($stats['directorio_tipo'])->toBe('anterior')
        ->and(riRow($may, 'INACTIVO DESDE ABRIL')->reconciliation_status)->toBe('baja_confirmada')
        ->and(riRow($may, 'INACTIVO DESDE ABRIL')->reconciliation_note)->toContain('a más tardar')
        ->and(riRow($may, 'ACTIVO EN ABRIL')->reconciliation_status)->toBe('posible_baja');
});

it('distinguishes reingreso (after a confirmed baja) from a return after an unconfirmed absence', function () {
    [$mar, $abr, $may] = [riPeriod(3), riPeriod(4), riPeriod(5)];
    riPayroll($mar, ['REINGRESA CONFIRMADO' => 'ATLIXCO', 'REGRESA SIN BAJA' => 'ATLIXCO', 'BASE REI' => 'ATLIXCO']);
    riPayroll($abr, ['BASE REI' => 'ATLIXCO']);
    riDirectory($abr, ['REINGRESA CONFIRMADO' => 'INACTIVO']);
    riPayroll($may, ['BASE REI' => 'ATLIXCO', 'REINGRESA CONFIRMADO' => 'ATLIXCO', 'REGRESA SIN BAJA' => 'ATLIXCO']);

    riBuild($mar, $abr, $may);

    expect(riRow($may, 'REINGRESA CONFIRMADO')->reconciliation_status)->toBe('reingreso')
        ->and(riRow($may, 'REINGRESA CONFIRMADO')->movement_type)->toBe('alta')
        ->and(riRow($may, 'REGRESA SIN BAJA')->reconciliation_status)->toBe('retorno_tras_ausencia')
        ->and(riRow($may, 'REGRESA SIN BAJA')->movement_type)->toBe('activo');
});

it('never merges two homonyms with different NOI keys in the same file, and keeps each one\'s continuity', function () {
    [$abr, $may] = [riPeriod(4), riPeriod(5)];
    $result = riNoiImport($abr, 'noi_nomina', [
        ['JUAN PEREZ LOPEZ', '10', 'P001 SUELDO', 1000],
        ['JUAN PEREZ LOPEZ', '55', 'P001 SUELDO', 2000],
        ['OTRA PERSONA BASE', '11', 'P001 SUELDO', 1500],
    ]);

    $ids = Employee::query()->where('normalized_name', 'juan perez lopez')->orderBy('id')->pluck('id')->all();
    expect($ids)->toHaveCount(2)
        ->and(collect($result['incidents'])->pluck('type')->all())->toContain('noi_identidad.homonimo')
        ->and(DB::table('employee_match_rejections')->count())->toBe(1);

    // Mayo: los dos siguen (otro archivo); cada clave reconoce a su persona.
    riNoiImport($may, 'noi_nomina', [
        ['JUAN PEREZ LOPEZ', '55', 'P001 SUELDO', 2000],
        ['JUAN PEREZ LOPEZ', '10', 'P001 SUELDO', 1000],
        ['OTRA PERSONA BASE', '11', 'P001 SUELDO', 1500],
    ]);
    expect(Employee::query()->where('normalized_name', 'juan perez lopez')->count())->toBe(2);

    $stats = riBuild($abr, $may);
    $mayRows = DB::table('period_employee_rosters')->where('period_id', $may->id)->where('nombre_normalizado', 'juan perez lopez')->get();
    expect($stats[$abr->id]['active_count'])->toBe(3)
        ->and($stats[$abr->id]['homonimos_separados'])->toBe(1)
        ->and($mayRows)->toHaveCount(2)
        ->and($mayRows->pluck('employee_key')->unique())->toHaveCount(2)
        ->and($mayRows->pluck('reconciliation_status')->unique()->values()->all())->toBe(['activo_confirmado'])
        ->and($stats[$may->id]['altas'])->toBe(0)
        ->and($stats[$may->id]['bajas'])->toBe(0);

    // La identidad canónica (OKR/Reportería) tampoco los junta.
    $groups = app(CanonicalEmployeeResolver::class)->canonicalize(Employee::query()->whereIn('id', $ids)->get());
    expect($groups)->toHaveCount(2);
});

it('counts NOI normal and NOI fiscal of the same employee once, and never confuses keys across the two systems', function () {
    $may = riPeriod(5);
    riNoiImport($may, 'noi_nomina', [['MARIA DOS NOMINAS', '36', 'P001 SUELDO', 1000]]);
    // En NOI fiscal la clave 36 es OTRA persona: las claves no se comparten entre sistemas.
    riNoiImport($may, 'noi_nomina_fiscal', [['MARIA DOS NOMINAS', '7', 'P001 SUELDO', 900], ['RICARDO FISCAL TREINTA', '36', 'P001 SUELDO', 800]]);

    expect(Employee::query()->where('normalized_name', 'maria dos nominas')->count())->toBe(1)
        ->and(Employee::query()->where('normalized_name', 'ricardo fiscal treinta')->count())->toBe(1);

    $stats = riBuild($may)[$may->id];
    expect($stats['active_count'])->toBe(2)
        ->and(riRow($may, 'MARIA DOS NOMINAS')->source)->toBe('ambos');
});

it('recognizes the same person by NOI key when the captured name changes slightly', function () {
    [$abr, $may] = [riPeriod(4), riPeriod(5)];
    riNoiImport($abr, 'noi_nomina', [['MARIA LOPEZ PEREZ', '10', 'P001 SUELDO', 1000], ['BASE CLAVE', '11', 'P001 SUELDO', 1000]]);
    riNoiImport($may, 'noi_nomina', [['MARIA LOPES PEREZ', '10', 'P001 SUELDO', 1000], ['BASE CLAVE', '11', 'P001 SUELDO', 1000]]);

    expect(Employee::query()->where('normalized_name', 'maria lopes perez')->exists())->toBeFalse()
        ->and(DB::table('employee_aliases')->where('normalized_alias', 'maria lopes perez')->exists())->toBeTrue();

    $stats = riBuild($abr, $may)[$may->id];
    expect($stats['altas'])->toBe(0)->and($stats['bajas'])->toBe(0)->and($stats['ausencias'])->toBe(0);
});

it('keeps ONE roster row for a person with several assignment records, and records a branch change', function () {
    [$abr, $may] = [riPeriod(4), riPeriod(5)];
    $a = Employee::query()->create(['normalized_name' => 'pedro dos registros', 'full_name' => 'PEDRO DOS REGISTROS', 'is_active' => true, 'source_system' => 'noi']);
    $b = Employee::query()->create(['normalized_name' => 'pedro dos registros', 'full_name' => 'PEDRO DOS REGISTROS', 'is_active' => true, 'source_system' => 'lendus_cobranza']);
    riPayroll($abr, [$a->id => 'ATLIXCO']);
    // (riPayroll acepta nombres: aquí se cargan movimientos para ambos registros a mano)
    DB::table('fact_noi_movements')->where('period_id', $abr->id)->delete();
    foreach ([[$abr, $a, 'ATLIXCO'], [$may, $a, 'CUERNAVACA'], [$may, $b, 'CUERNAVACA']] as [$p, $e, $br]) {
        DB::table('fact_noi_movements')->insert(['period_id' => $p->id, 'employee_id' => $e->id, 'report_upload_id' => riUpload($p, 'noi_nomina'),
            'concept' => 'P001 SUELDO', 'amount' => 1000, 'quantity' => 0, 'created_at' => now(), 'updated_at' => now()]);
        EmployeeBranchAssignment::query()->updateOrCreate(['period_id' => $p->id, 'employee_id' => $e->id], ['branch_id' => riBranch($br)->id]);
    }

    $stats = riBuild($abr, $may)[$may->id];

    expect(DB::table('period_employee_rosters')->where('period_id', $may->id)->count())->toBe(1)
        ->and($stats['active_count'])->toBe(1)
        ->and($stats['duplicados_fusionados'])->toBe(1)
        ->and(riRow($may, 'PEDRO DOS REGISTROS')->reconciliation_status)->toBe('cambio_sucursal');
});

it('reads old incomplete roster rows (no evidence, no category) without inventing bajas', function () {
    [$mar, $abr, $may] = [riPeriod(3), riPeriod(4), riPeriod(5)];
    $x = riEmployee('LEGADO SIN CATEGORIA');
    $y = riEmployee('LEGADO ACTIVO');
    $legacy = fn (Period $p, Employee $e, bool $active, string $movement) => DB::table('period_employee_rosters')->insert([
        'period_id' => $p->id, 'employee_id' => $e->id, 'employee_key' => $e->normalized_name, 'nombre_normalizado' => $e->normalized_name,
        'nombre_original' => $e->full_name, 'source' => 'noi_nomina', 'is_active_for_period' => $active, 'movement_type' => $movement,
        'reconciliation_status' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $legacy($mar, $x, true, 'activo');
    $legacy($mar, $y, true, 'activo');
    $legacy($abr, $x, false, 'baja'); // baja antigua "por ausencia", sin evidencia
    $legacy($abr, $y, true, 'activo');
    riPayroll($may, ['LEGADO SIN CATEGORIA' => 'ATLIXCO', 'LEGADO ACTIVO' => 'ATLIXCO']);

    riBuild($may);

    expect(riRow($may, 'LEGADO ACTIVO')->reconciliation_status)->toBe('activo_confirmado')
        // La "baja" antigua no estaba confirmada: vuelve como retorno, no como reingreso/alta.
        ->and(riRow($may, 'LEGADO SIN CATEGORIA')->reconciliation_status)->toBe('retorno_tras_ausencia')
        ->and(riRow($may, 'LEGADO SIN CATEGORIA')->movement_type)->toBe('activo');
});

it('does not duplicate employees when the database update scan runs again or the person came from Lendus first', function () {
    $may = riPeriod(5);
    Employee::query()->create(['normalized_name' => 'lendus primero', 'full_name' => 'LENDUS PRIMERO', 'is_active' => true, 'source_system' => 'lendus_cobranza']);
    Storage::fake('public');
    $csv = "nombre,clave,concepto,tipo_concepto,importe,cantidad,tipo_nomina,fecha\n"
        ."LENDUS PRIMERO,\"Clave del trabajador : 5\",P001 SUELDO,percepcion,1000,1,ordinaria,2026-05-10\n"
        ."NUEVO EN NOI,\"Clave del trabajador : 6\",P001 SUELDO,percepcion,1000,1,ordinaria,2026-05-10\n";
    Storage::disk('public')->put('report_uploads/scan.csv', $csv);
    $source = DataSource::query()->firstOrCreate(['code' => 'noi_nomina'], ['name' => 'NOI', 'description' => 'NOI', 'is_active' => true]);
    $upload = ReportUpload::query()->create(['period_id' => $may->id, 'data_source_id' => $source->id, 'original_name' => 'scan.csv', 'stored_path' => 'report_uploads/scan.csv',
        'mime_type' => 'text/csv', 'file_size' => strlen($csv), 'uploaded_by' => User::factory()->create()->id, 'uploaded_at' => now(), 'status' => ReportUploadStatus::Pending]);

    $svc = app(NoiNominaImportService::class);
    $svc->scanForDatabaseUpdate($upload->fresh('dataSource'));
    $afterFirstScan = Employee::query()->count();
    $svc->scanForDatabaseUpdate($upload->fresh('dataSource'));
    $svc->handle($upload->fresh('dataSource'));

    expect(Employee::query()->where('normalized_name', 'lendus primero')->count())->toBe(1)
        ->and(Employee::query()->where('normalized_name', 'nuevo en noi')->count())->toBe(1)
        // Antes, en MySQL, cada "Actualizar BD" volvía a insertar a toda la plantilla.
        ->and(Employee::query()->count())->toBe($afterFirstScan)
        ->and(DB::table('employee_external_identities')->where('external_type', 'noi_clave')->count())->toBe(2);
});

it('flags an unlinked near-duplicate (only normal vs only fiscal) for reconciliation and merges it once an alias is conciliated', function () {
    [$abr, $may] = [riPeriod(4), riPeriod(5)];
    riPayroll($abr, ['BASE DUP' => 'ATLIXCO']);
    riPayroll($may, ['BASE DUP' => 'ATLIXCO', 'JUAN CARLOS RAMIREZ SOTO' => ['ATLIXCO', 'noi_nomina'], 'JUAN CARLOS RAMIRES SOTO' => ['ATLIXCO', 'noi_nomina_fiscal']]);

    $stats = riBuild($abr, $may)[$may->id];
    expect(riRow($may, 'JUAN CARLOS RAMIREZ SOTO')->reconciliation_status)->toBe('registro_duplicado')
        ->and(riRow($may, 'JUAN CARLOS RAMIRES SOTO')->reconciliation_status)->toBe('registro_duplicado')
        ->and($stats['altas'])->toBe(0)
        ->and($stats['posibles_duplicados_presentes'])->toBe(2);

    DB::table('employee_aliases')->insert([
        'employee_id' => riEmployee('JUAN CARLOS RAMIREZ SOTO')->id, 'alias_name' => 'JUAN CARLOS RAMIRES SOTO', 'normalized_alias' => 'juan carlos ramires soto',
        'source' => 'manual', 'confidence' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $stats = riBuild($may)[$may->id];

    expect($stats['active_count'])->toBe(2)
        ->and($stats['vinculos_alias'])->toBe(1)
        ->and(riRow($may, 'JUAN CARLOS RAMIREZ SOTO')->source)->toBe('ambos');
});

it('applies one current-eligibility policy: placeholders out, older-directory contradictions visible, newer INACTIVO out', function () {
    [$abr, $may] = [riPeriod(4), riPeriod(5)];
    riPayroll($abr, ['PEDRO COBRA HOY' => 'ATLIXCO', 'VACANTE GESTOR NORTE' => 'ATLIXCO']);
    riPayroll($may, ['PEDRO COBRA HOY' => 'ATLIXCO', 'VACANTE GESTOR NORTE' => 'ATLIXCO']);
    riDirectory($abr, ['PEDRO COBRA HOY' => 'INACTIVO']);
    riBuild($abr, $may);

    $resolver = app(OkrEmployeeBranchResolver::class);
    $pedro = riEmployee('PEDRO COBRA HOY')->id;
    expect($resolver->employeeIsActive($pedro))->toBeTrue()
        ->and(collect($resolver->statusFlags([$pedro])[$pedro])->pluck('code')->all())->toContain('labor_contradiction')
        ->and($resolver->employeeIsActive(riEmployee('VACANTE GESTOR NORTE')->id))->toBeFalse()
        ->and(EmploymentEligibilityPolicy::isPlaceholderName('Vacante gestor norte'))->toBeTrue()
        ->and(EmploymentEligibilityPolicy::isPlaceholderName('Gestor de Tlaxcala parent test'))->toBeFalse();

    // Un directorio POSTERIOR a la nómina vigente que lo marca INACTIVO sí lo saca de nuevos OKR.
    riDirectory(riPeriod(6), ['PEDRO COBRA HOY' => 'INACTIVO']);
    app()->forgetScopedInstances(); // el directorio vigente se cachea por solicitud
    expect(app(OkrEmployeeBranchResolver::class)->employeeIsActive($pedro))->toBeFalse();
});

it('lets an authorized user reconcile ONE of two homonyms by its identity key and shows the evidence sources on the page', function () {
    [$abr, $may] = [riPeriod(4), riPeriod(5)];
    riNoiImport($abr, 'noi_nomina', [
        ['JUAN PEREZ LOPEZ', '10', 'P001 SUELDO', 1000],
        ['JUAN PEREZ LOPEZ', '55', 'P001 SUELDO', 2000],
    ]);
    riNoiImport($may, 'noi_nomina', [['JUAN PEREZ LOPEZ', '10', 'P001 SUELDO', 1000]]);
    riDirectory(riPeriod(10), ['JUAN PEREZ LOPEZ' => 'ACTIVO']);
    riBuild($abr, $may);

    $absent = DB::table('period_employee_rosters')->where('period_id', $may->id)->where('is_active_for_period', false)->first();
    expect($absent->employee_key)->toContain('#')
        // Homónimos: el directorio no se puede atribuir a uno solo.
        ->and(riEvidence($absent))->toContain('homónimos');

    $admin = User::factory()->create(['role' => 'admin']);
    $props = $this->actingAs($admin)->get(route('asignaciones-empleado-sucursal.index', ['period_id' => $may->id]))
        ->assertOk()->viewData('page')['props'];
    $pending = collect($props['labor']['pending'])->firstWhere('identity_key', $absent->employee_key);
    expect($pending)->not->toBeNull()
        ->and($props['labor']['roster']['periodo_rango'])->not->toBeNull()
        ->and($props['labor']['roster']['plantilla'])->toBe(1)
        ->and($props['labor']['roster']['con_sucursal'])->toBe(0)
        ->and($props['labor']['roster']['directorio']['posterior'])->toContain('Octubre 2026');

    $this->actingAs($admin)->post(route('asignaciones-empleado-sucursal.review-movement', $may), [
        'nombre_normalizado' => $absent->employee_key, 'decision' => 'confirmar_baja', 'note' => 'RH confirma la baja de la clave 55.',
    ])->assertSessionHas('success');

    $rows = DB::table('period_employee_rosters')->where('period_id', $may->id)->where('nombre_normalizado', 'juan perez lopez')->get()->keyBy('employee_key');
    expect($rows[$absent->employee_key]->reconciliation_status)->toBe('baja_confirmada')
        ->and($rows->where('is_active_for_period', true)->first()->reconciliation_status)->toBe('activo_confirmado');
});

function riGestoresRows(Period $period): array
{
    $builder = app(\App\Services\Radiography\RadiographySnapshotBuilder::class);
    $prop = new ReflectionProperty($builder, 'dataIds');
    $prop->setAccessible(true);
    $prop->setValue($builder, [$period->id]);
    $method = new ReflectionMethod($builder, 'buildEmployeesGestores');
    $method->setAccessible(true);

    return $method->invoke($builder, $period)['rows'];
}

it('never sums two REJECTED homonyms together in the radiography people table (payroll stays per person)', function () {
    $may = riPeriod(5);
    $a = Employee::query()->create(['normalized_name' => 'juan perez lopez', 'full_name' => 'JUAN PEREZ LOPEZ', 'is_active' => true, 'source_system' => 'noi']);
    $b = Employee::query()->create(['normalized_name' => 'juan perez lopez', 'full_name' => 'JUAN PEREZ LOPEZ', 'is_active' => true, 'source_system' => 'noi']);
    $upload = riUpload($may, 'noi_nomina');
    foreach ([[$a, 1000], [$b, 2500]] as [$e, $amount]) {
        DB::table('fact_noi_movements')->insert(['period_id' => $may->id, 'employee_id' => $e->id, 'report_upload_id' => $upload, 'concept' => 'P001 SUELDO',
            'concept_type' => 'percepcion', 'amount' => $amount, 'quantity' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }

    // Sin rechazo: es la regla histórica (mismo nombre = misma persona) y se suma en una fila.
    $rows = collect(riGestoresRows($may))->filter(fn ($r) => $r['name'] === 'JUAN PEREZ LOPEZ');
    expect($rows)->toHaveCount(1)->and($rows->first()['pagos'])->toEqual(3500.0);

    // Con rechazo explícito (homónimos con clave distinta): dos filas, nunca 3,500 juntos.
    DB::table('employee_match_rejections')->insert(['employee_id_a' => $a->id, 'employee_id_b' => $b->id, 'pair_key' => \App\Models\EmployeeMatchRejection::pairKey($a->id, $b->id),
        'reason' => 'Homónimos con claves 10 y 55', 'created_at' => now(), 'updated_at' => now()]);
    $rows = collect(riGestoresRows($may))->filter(fn ($r) => $r['name'] === 'JUAN PEREZ LOPEZ')->values();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('pagos')->sort()->values()->all())->toEqual([1000.0, 2500.0])
        ->and($rows->pluck('_employee_ids')->map(fn ($ids) => count($ids))->all())->toBe([1, 1])
        ->and(app(\App\Services\PersonIdentityResolverService::class)->findExistingPersonMatch('JUAN PEREZ LOPEZ', $a->id))->toBeNull()
        ->and(app(OkrEmployeeBranchResolver::class)->canonicalIdsFor($a->id))->toBe([$a->id]);
});
