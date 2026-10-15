<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\OkrKpi;
use App\Models\OkrObjective;
use App\Models\Period;
use App\Models\User;
use App\Services\Okr\OkrPlacementAttributionResolver;
use App\Services\Okr\OkrPlacementImportService;
use App\Services\Okr\OkrWeeklyPlacementResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

uses(RefreshDatabase::class);

/**
 * Prioridad 4 del cierre OKR (15-oct-2026) — atribución SEGURA de colocación:
 * número de empleado vigente para la fecha → identidad canónica → código de
 * vendedor → asignación del periodo → sucursal oficial → nombre exacto (solo
 * sin ambigüedad). Lo dudoso queda "pendiente de atribución": suma a la
 * sucursal, nunca a un gestor.
 */
function paPeriod(string $name, string $start, string $end): Period
{
    return Period::query()->create(['name' => $name, 'code' => 'PA-' . uniqid('', true), 'type' => 'monthly', 'year' => 2026, 'month' => (int) substr($end, 5, 2), 'sequence' => 1, 'start_date' => $start, 'end_date' => $end]);
}

function paBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(['normalized_name' => mb_strtolower($name)], ['code' => 'PA' . strtoupper(substr(preg_replace('/\W/', '', $name), 0, 6)) . rand(10, 99), 'name' => $name, 'is_active' => true]);
}

function paEmployee(string $name): Employee
{
    return Employee::query()->create(['full_name' => $name, 'normalized_name' => mb_strtolower($name), 'is_active' => true]);
}

/** @param array<int, array{0:string,1:string,2?:string}> $rows [codigo, nombre, estatus] */
function paDirectory(Period $period, array $rows): void
{
    $sourceId = DB::table('data_sources')->insertGetId(['code' => 'pa_dir_' . uniqid(), 'name' => 'Directorio Lendus', 'created_at' => now(), 'updated_at' => now()]);
    $uploadId = DB::table('report_uploads')->insertGetId([
        'period_id' => $period->id, 'data_source_id' => $sourceId, 'uploaded_by' => User::factory()->create()->id, 'original_name' => 'dir.xlsx',
        'stored_path' => 'x', 'status' => 'processed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($rows as $r) {
        DB::table('lendus_employee_directory')->insert([
            'report_upload_id' => $uploadId, 'codigo' => $r[0], 'nombre' => $r[1], 'normalized_name' => mb_strtolower($r[1]),
            'puesto' => 'GESTOR DE CRÉDITO', 'estatus' => $r[2] ?? 'ACTIVO', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

function paXlsx(array $headers, array $rows): UploadedFile
{
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    foreach ($headers as $i => $h) {
        $sheet->setCellValueByColumnAndRow($i + 1, 1, $h);
    }
    foreach ($rows as $r => $data) {
        foreach ($data as $c => $value) {
            $sheet->setCellValueByColumnAndRow($c + 1, $r + 2, $value);
        }
    }
    $path = storage_path('app/testing-attribution-' . uniqid() . '.xlsx');
    IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

    return new UploadedFile($path, 'colocacion.xlsx', 'application/vnd.ms-excel', null, true);
}

function paObjective(Branch $branch, array $attrs = []): OkrObjective
{
    $user = User::factory()->create();
    $kpi = OkrKpi::query()->firstOrCreate(['code' => 'pa_placement'], [
        'name' => 'Colocación', 'unit' => 'currency', 'type' => OkrKpi::TYPE_CUMULATIVE, 'direction' => OkrKpi::DIRECTION_INCREASE,
        'automation' => OkrKpi::AUTOMATION_AUTOMATIC, 'provider_key' => 'reporteria.placement', 'is_active' => true,
    ]);
    $objective = OkrObjective::query()->create(array_merge([
        'scope_type' => 'branch', 'branch_id' => $branch->id, 'title' => 'Colocación de prueba atribución',
        'responsible_user_id' => $user->id, 'created_by' => $user->id, 'duration_weeks' => 4,
        'start_date' => '2026-06-01', 'end_date' => '2026-06-28', 'lifecycle_status' => OkrObjective::STATUS_ACTIVE,
    ], $attrs));
    $objective->keyResults()->create(['kpi_id' => $kpi->id, 'description' => 'Colocación', 'baseline_value' => 0, 'target_value' => 1_000_000, 'weight' => 100, 'baseline_locked_at' => now()]);

    return $objective;
}

beforeEach(function () {
    $this->abril = paPeriod('Abril 2026', '2026-03-30', '2026-04-26');
    $this->junio = paPeriod('Junio 2026', '2026-05-25', '2026-06-21');
    $this->atlixco = paBranch('ATLIXCO');
    $this->tula = paBranch('TULA');
    $this->attr = fn () => app(OkrPlacementAttributionResolver::class);
});

it('attributes by a UNIQUE employee code even when the file spells the name differently (name change)', function () {
    $david = paEmployee('DAVID GAMBOA CANO');
    paDirectory($this->junio, [['ATLX12513', 'DAVID GAMBOA CANO']]);

    $r = ($this->attr)()->resolve('atlx12513', 'DAVID GAMBOA C.', '2026-06-03', 'ATLIXCO', false);

    expect($r['employee_id'])->toBe($david->id)
        ->and($r['status'])->toBe('attributed')
        ->and($r['method'])->toBe('employee_code')
        ->and($r['note'])->toContain('se usó el número de empleado');
});

it('uses the directory VIGENTE for the movement date when a code was reused by another person historically', function () {
    $old = paEmployee('PERSONA ANTERIOR CODIGO');
    $new = paEmployee('PERSONA ACTUAL CODIGO');
    paDirectory($this->abril, [['HUAM99', 'PERSONA ANTERIOR CODIGO', 'INACTIVO']]);
    paDirectory($this->junio, [['HUAM99', 'PERSONA ACTUAL CODIGO']]);

    expect(($this->attr)()->resolve('HUAM99', null, '2026-06-10', null, true)['employee_id'])->toBe($new->id)
        ->and(($this->attr)()->resolve('HUAM99', null, '2026-04-10', null, true)['employee_id'])->toBe($old->id);
});

it('leaves a movement PENDING when the code is shared by several people in the same directory (real HUAM18 case), unless the exact name picks one of them', function () {
    $a = paEmployee('JOSE MIGUEL LOAIZA GONZALES');
    paEmployee('ESTEBAN BARRERA LOPEZ');
    paEmployee('LIZZETT RODRIGUEZ VAZQUEZ');
    paDirectory($this->junio, [
        ['HUAM18', 'JOSE MIGUEL LOAIZA GONZALES', 'INACTIVO'], ['HUAM18', 'ESTEBAN BARRERA LOPEZ', 'INACTIVO'], ['HUAM18', 'LIZZETT RODRIGUEZ VAZQUEZ', 'INACTIVO'],
    ]);

    $pending = ($this->attr)()->resolve('HUAM18', 'OTRO NOMBRE', '2026-06-10', null, true);
    expect($pending['employee_id'])->toBeNull()
        ->and($pending['status'])->toBe('pending')
        ->and($pending['note'])->toContain('registrado para 3 personas distintas');

    $byName = ($this->attr)()->resolve('HUAM18', 'José Miguel Loaiza Gonzales', '2026-06-10', null, true);
    expect($byName['employee_id'])->toBe($a->id)->and($byName['method'])->toBe('employee_code+name');
});

it('never falls back to the name when the employee code has no worker', function () {
    paEmployee('JUAN PEREZ GOMEZ');
    paDirectory($this->junio, [['ATLX00001', 'OTRA PERSONA']]);

    $r = ($this->attr)()->resolve('ZZZ404', 'JUAN PEREZ GOMEZ', '2026-06-10', null, true);

    expect($r['employee_id'])->toBeNull()->and($r['note'])->toContain('no corresponde a ningún trabajador');
});

it('resolves a code missing from every directory through the vendor code linked in the Lendus portfolio', function () {
    $e = paEmployee('VENDEDOR SOLO EN CARTERA');
    DB::table('fact_portfolios')->insert(['period_id' => $this->junio->id, 'branch_id' => $this->atlixco->id, 'promoter_code' => 'ATLX55555', 'promoter_name' => 'VENDEDOR SOLO EN CARTERA', 'route_name' => 'CHOL', 'client_name' => 'C', 'balance' => 1, 'created_at' => now(), 'updated_at' => now()]);

    $r = ($this->attr)()->resolve('ATLX55555', null, '2026-06-10', 'ATLIXCO', true);

    expect($r['employee_id'])->toBe($e->id)->and($r['method'])->toBe('promoter_code');
});

it('handles a branch change: the period assignment decides when the file has no branch column', function () {
    $e = paEmployee('GESTOR TRANSFERIDO');
    paDirectory($this->abril, [['TLA500', 'GESTOR TRANSFERIDO']]);
    paDirectory($this->junio, [['TLA500', 'GESTOR TRANSFERIDO']]);
    EmployeeBranchAssignment::query()->create(['period_id' => $this->abril->id, 'employee_id' => $e->id, 'branch_id' => $this->tula->id]);
    EmployeeBranchAssignment::query()->create(['period_id' => $this->junio->id, 'employee_id' => $e->id, 'branch_id' => $this->atlixco->id]);

    // Junio: ya está en ATLIXCO → se atribuye.
    expect(($this->attr)()->resolve('TLA500', null, '2026-06-10', 'ATLIXCO', false)['employee_id'])->toBe($e->id);
    // Abril, archivo de ATLIXCO SIN columna de sucursal: en Abril era de TULA → pendiente.
    $pending = ($this->attr)()->resolve('TLA500', null, '2026-04-10', 'ATLIXCO', false);
    expect($pending['employee_id'])->toBeNull()->and($pending['note'])->toContain('en Abril 2026 está asignado a TULA');
    // Con la sucursal explícita en el archivo, el movimiento es de ATLIXCO y el código manda.
    expect(($this->attr)()->resolve('TLA500', null, '2026-04-10', 'ATLIXCO', true)['employee_id'])->toBe($e->id);
});

it('does not auto-assign similar names: only suggests them for reconciliation', function () {
    paEmployee('JUAN PEREZ GOMEZ');

    $r = ($this->attr)()->resolve(null, 'JUAN PERES GOMES', '2026-06-10', 'ATLIXCO', false);

    expect($r['employee_id'])->toBeNull()
        ->and($r['suggestions'])->toContain('JUAN PEREZ GOMEZ')
        ->and($r['note'])->toContain('no se asignan automáticamente');
});

it('blocks a name-only match when the period assignment places that person in another branch', function () {
    $e = paEmployee('GESTOR DE TULA');
    EmployeeBranchAssignment::query()->create(['period_id' => $this->junio->id, 'employee_id' => $e->id, 'branch_id' => $this->tula->id]);

    $r = ($this->attr)()->resolve(null, 'GESTOR DE TULA', '2026-06-10', 'ATLIXCO', false);

    expect($r['employee_id'])->toBeNull()->and($r['note'])->toContain('está asignado a TULA');
    // En su propia sucursal sí se atribuye por nombre exacto.
    expect(($this->attr)()->resolve(null, 'GESTOR DE TULA', '2026-06-10', 'TULA', false)['employee_id'])->toBe($e->id);
});

it('ONE branch file feeds branch and gestores without duplicating money: gestores + pending = branch total', function () {
    $david = paEmployee('DAVID GAMBOA CANO');
    $rafael = paEmployee('RAFAEL PEREZ CHAO');
    paEmployee('JOSE MIGUEL LOAIZA GONZALES');
    paEmployee('ESTEBAN BARRERA LOPEZ');
    paDirectory($this->junio, [
        ['ATLX12513', 'DAVID GAMBOA CANO'], ['ATLX04819', 'RAFAEL PEREZ CHAO'],
        ['HUAM18', 'JOSE MIGUEL LOAIZA GONZALES', 'INACTIVO'], ['HUAM18', 'ESTEBAN BARRERA LOPEZ', 'INACTIVO'],
    ]);
    $objective = paObjective($this->atlixco);

    $headers = ['Código', 'Colaborador', 'Colocación', 'Fecha', 'Contrato', 'Ruta'];
    $file = paXlsx($headers, [
        ['ATLX12513', 'DAVID GAMBOA CANO', 10000, '2026-06-02', 'C-1', 'CHOL'],
        ['ATLX12513', 'DAVID GAMBOA CANO', 10000, '2026-06-02', 'C-1', 'CHOL'],      // repetido: cuenta una vez
        ['ATLX04819', 'RAFAEL P. CHAO', 7000, '2026-06-03', 'C-2', 'TOCH'],           // nombre distinto, código válido
        ['HUAM18', 'ALGUIEN', 3000, '2026-06-04', 'C-3', 'ATLIX-GTE'],               // código compartido → pendiente
        ['ZZZ404', 'DAVID GAMBOA CANO', 2000, '2026-06-04', 'C-4', 'ATLIX-SUR'],     // código sin trabajador → pendiente
        ['ATLX12513', 'DAVID GAMBOA CANO', 5000, '2026-06-05', 'C-5', 'ATOTONILCO'], // ruta de TULA → otra sucursal
        ['ATLX12513', 'DAVID GAMBOA CANO', 4000, '2026-06-05', 'C-6', 'CANCELADO X'], // ruta desconocida → nunca se adivina
    ]);

    $upload = app(OkrPlacementImportService::class)->import($objective, 1, $file, User::factory()->create());

    $byEmployee = $upload->movements()->whereNotNull('employee_id')->get()->groupBy('employee_id')->map->sum('amount');
    $pending = $upload->movements()->whereNull('employee_id')->get();

    expect((float) $upload->total_amount)->toBe(22000.0)
        ->and((float) $byEmployee[$david->id])->toBe(10000.0)
        ->and((float) $byEmployee[$rafael->id])->toBe(7000.0)
        ->and((float) $upload->unattributed_amount)->toBe(5000.0)
        ->and($upload->rows_pending_attribution)->toBe(2)
        ->and($upload->rows_other_branch)->toBe(2)
        ->and($pending->pluck('attribution_status')->unique()->all())->toBe(['pending'])
        ->and($pending->pluck('attribution_note')->implode(' '))->toContain('HUAM18')->toContain('ZZZ404');

    // Suma gestores + pendientes = total de la sucursal (sin doble conteo).
    expect(round($byEmployee->sum() + (float) $upload->unattributed_amount, 2))->toBe((float) $upload->total_amount);
    expect(app(OkrWeeklyPlacementResolver::class)->resolve($objective, 1)['cumulative'])->toBe(22000.0);

    // El desglose por semana muestra el pendiente con su motivo.
    $admin = User::factory()->create(['role' => 'admin']);
    $rows = collect($this->actingAs($admin)->getJson(route('okr.placement-weeks.breakdown', [$objective, 1]))->assertOk()->json('rows'));
    expect($rows->where('pending_attribution', true)->pluck('pending_reason')->implode(' '))->toContain('No se atribuye a nadie');
});

it('an individual objective fed by the same file only sums that gestor, never pending rows', function () {
    $david = paEmployee('DAVID GAMBOA CANO');
    paDirectory($this->junio, [['ATLX12513', 'DAVID GAMBOA CANO'], ['HUAM18', 'A UNO'], ['HUAM18', 'B DOS']]);
    $objective = paObjective($this->atlixco, ['scope_type' => 'employee', 'employee_id' => $david->id]);

    $upload = app(OkrPlacementImportService::class)->import($objective, 1, paXlsx(['Código', 'Colaborador', 'Colocación', 'Fecha'], [
        ['ATLX12513', 'DAVID GAMBOA CANO', 8000, '2026-06-02'],
        ['HUAM18', 'DAVID GAMBOA CANO', 9000, '2026-06-02'],
    ]), User::factory()->create());

    expect((float) $upload->total_amount)->toBe(8000.0)->and($upload->movements()->count())->toBe(1);
});

it('counts an INACTIVE route movement for its official branch (history) while attributing by code', function () {
    $huamantla = paBranch('HUAMANTLA');
    $e = paEmployee('GESTOR HUAMANTLA');
    paDirectory($this->junio, [['HUAM700', 'GESTOR HUAMANTLA']]);
    $objective = paObjective($huamantla);

    $upload = app(OkrPlacementImportService::class)->import($objective, 1, paXlsx(['Código', 'Colaborador', 'Colocación', 'Fecha', 'Ruta'], [
        ['HUAM700', 'GESTOR HUAMANTLA', 1500, '2026-06-02', 'HUAM-CEN (INACTIVA)'],
        ['HUAM700', 'GESTOR HUAMANTLA', 2500, '2026-06-03', 'APIZ-CEN'],
    ]), User::factory()->create());

    expect((float) $upload->total_amount)->toBe(4000.0)
        ->and($upload->movements()->where('employee_id', $e->id)->sum('amount'))->toEqual(4000)
        ->and($upload->rows_other_branch)->toBe(0);
});
