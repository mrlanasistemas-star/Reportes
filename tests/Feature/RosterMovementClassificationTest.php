<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Models\User;
use App\Services\Okr\OkrEmployeeBranchResolver;
use App\Services\PeriodEmployeeRosterService;
use App\Services\RotacionDerivedFromNoiService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/**
 * Movimientos laborales con evidencia (18-oct-2026): dejar de aparecer en la
 * nómina NOI NO es una baja. Solo la evidencia (directorio laboral INACTIVO,
 * finiquito en NOI o conciliación autorizada) produce movement_type='baja', que
 * es lo único que cuentan Rotación, reportes y la elegibilidad OKR.
 */
function rmPeriod(string $name, int $month): Period
{
    $start = sprintf('2026-%02d-01', $month);

    return Period::query()->create([
        'name' => $name, 'code' => 'RM-'.$month.'-'.uniqid(), 'type' => 'monthly', 'year' => 2026, 'month' => $month,
        'sequence' => 1, 'start_date' => $start, 'end_date' => date('Y-m-t', strtotime($start)),
    ]);
}

function rmBranch(string $name): Branch
{
    return Branch::query()->firstOrCreate(['normalized_name' => mb_strtolower($name)], ['code' => substr(strtoupper($name), 0, 6).rand(10, 99), 'name' => $name, 'is_active' => true]);
}

function rmSourceId(string $code): int
{
    return (int) (DB::table('data_sources')->where('code', $code)->value('id')
        ?? DB::table('data_sources')->insertGetId(['code' => $code, 'name' => $code, 'created_at' => now(), 'updated_at' => now()]));
}

function rmUpload(Period $period, string $code): int
{
    static $user = null;
    $user ??= User::factory()->create();
    if (! User::query()->whereKey($user->id)->exists()) {
        $user = User::factory()->create();
    }

    return (int) DB::table('report_uploads')->insertGetId([
        'period_id' => $period->id, 'data_source_id' => rmSourceId($code), 'uploaded_by' => $user->id, 'original_name' => "{$code}.xls",
        'stored_path' => 'x', 'status' => 'processed', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function rmEmployee(string $name): Employee
{
    return Employee::query()->firstOrCreate(['normalized_name' => mb_strtolower($name)], ['full_name' => $name, 'is_active' => true]);
}

/** Nómina NOI del periodo: [nombre => sucursal] (o [nombre => [sucursal, concepto]]). */
function rmPayroll(Period $period, array $people): void
{
    $upload = rmUpload($period, 'noi_nomina');
    foreach ($people as $name => $spec) {
        [$branchName, $concept] = is_array($spec) ? $spec : [$spec, 'P001 SUELDO'];
        $e = rmEmployee($name);
        DB::table('fact_noi_movements')->insert([
            'period_id' => $period->id, 'employee_id' => $e->id, 'report_upload_id' => $upload, 'concept' => $concept,
            'amount' => 1000, 'quantity' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        EmployeeBranchAssignment::query()->updateOrCreate(['period_id' => $period->id, 'employee_id' => $e->id], ['branch_id' => rmBranch($branchName)->id]);
    }
}

/** Directorio laboral cargado en $period: [nombre => [estatus, puesto?, codigo?]]. */
function rmDirectory(Period $period, array $rows): void
{
    $upload = rmUpload($period, 'lendus_directorio_empleados');
    foreach ($rows as $name => $row) {
        DB::table('lendus_employee_directory')->insert([
            'report_upload_id' => $upload, 'codigo' => $row[2] ?? null, 'nombre' => $name, 'normalized_name' => mb_strtolower($name),
            'puesto' => $row[1] ?? 'GESTOR DE CRÉDITO', 'estatus' => $row[0], 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

/**
 * 2026_07_21_000002 solo vuelve nullable fact_rotacion.report_upload_id en MySQL;
 * en SQLite de pruebas se replica aquí para poder insertar la rotación derivada.
 */
function rmAllowDerivedRotation(): void
{
    if (DB::connection()->getDriverName() === 'sqlite') {
        Schema::table('fact_rotacion', fn (Blueprint $t) => $t->unsignedBigInteger('report_upload_id')->nullable()->change());
    }
}

function rmBuild(Period ...$periods): array
{
    $svc = app(PeriodEmployeeRosterService::class);
    $out = [];
    foreach ($periods as $p) {
        $out[$p->id] = $svc->buildForPeriod($p);
    }

    return $out;
}

function rmRow(Period $period, string $name): object
{
    return DB::table('period_employee_rosters')->where('period_id', $period->id)->where('nombre_normalizado', mb_strtolower($name))->firstOrFail();
}

it('only an INACTIVO in the labor directory confirms a baja; a mere payroll absence is a POSIBLE baja that never counts', function () {
    $abr = rmPeriod('Abril 2026', 4);
    $may = rmPeriod('Mayo 2026', 5);
    rmPayroll($abr, ['ANA SALIO RAMIREZ' => 'ATLIXCO', 'BETO OMITIDO NOI' => 'ATLIXCO', 'CARLA SIGUE ACTIVA' => 'ATLIXCO']);
    rmPayroll($may, ['CARLA SIGUE ACTIVA' => 'ATLIXCO']);
    rmDirectory($may, ['ANA SALIO RAMIREZ' => ['INACTIVO'], 'CARLA SIGUE ACTIVA' => ['ACTIVO']]);

    $stats = rmBuild($abr, $may)[$may->id];

    $ana = rmRow($may, 'ANA SALIO RAMIREZ');
    expect($ana->movement_type)->toBe('baja')
        ->and($ana->reconciliation_status)->toBe('baja_confirmada')
        ->and((bool) $ana->requires_review)->toBeFalse()
        ->and(json_decode($ana->movement_evidence, true)['items'])->not->toBeEmpty();

    $beto = rmRow($may, 'BETO OMITIDO NOI');
    expect($beto->movement_type)->toBe('ausencia')
        ->and($beto->reconciliation_status)->toBe('posible_baja')
        ->and((bool) $beto->requires_review)->toBeTrue();

    expect($stats['bajas'])->toBe(1)->and($stats['bajas_pendientes'])->toBe(1);
    // Abril no tiene periodo anterior: plantilla inicial, nunca "altas" infladas.
    expect(rmRow($abr, 'CARLA SIGUE ACTIVA')->reconciliation_status)->toBe('plantilla_inicial')
        ->and(rmRow($abr, 'CARLA SIGUE ACTIVA')->movement_type)->toBe('activo');
});

it('treats an absence followed by a return (vacaciones/omisión) as NO localizado, and the return is not an alta', function () {
    $abr = rmPeriod('Abril 2026', 4);
    $may = rmPeriod('Mayo 2026', 5);
    $jun = rmPeriod('Junio 2026', 6);
    rmPayroll($abr, ['DIANA VACACIONES PEREZ' => 'TLAXCALA', 'OTRO GESTOR UNO' => 'TLAXCALA']);
    rmPayroll($may, ['OTRO GESTOR UNO' => 'TLAXCALA']);
    rmPayroll($jun, ['DIANA VACACIONES PEREZ' => 'TLAXCALA', 'OTRO GESTOR UNO' => 'TLAXCALA']);

    rmBuild($abr, $may);
    expect(rmRow($may, 'DIANA VACACIONES PEREZ')->reconciliation_status)->toBe('vigencia_desconocida'); // sin directorio: no se inventa

    rmBuild($jun, $may); // reconstruir Mayo cuando Junio ya existe
    $mayRow = rmRow($may, 'DIANA VACACIONES PEREZ');
    expect($mayRow->movement_type)->toBe('ausencia')
        ->and($mayRow->reconciliation_status)->toBe('no_localizado')
        ->and($mayRow->reconciliation_note)->toContain('Junio 2026');

    rmBuild($jun);
    $junRow = rmRow($jun, 'DIANA VACACIONES PEREZ');
    expect($junRow->movement_type)->toBe('activo')
        ->and($junRow->reconciliation_status)->toBe('retorno_tras_ausencia');
});

it('flags "sin actividad comercial" as informative only — it stays active, never a baja', function () {
    $abr = rmPeriod('Abril 2026', 4);
    $may = rmPeriod('Mayo 2026', 5);
    rmPayroll($abr, ['GESTOR SIN COLOCAR' => 'ATLIXCO', 'GESTOR QUE COLOCA' => 'ATLIXCO']);
    rmPayroll($may, ['GESTOR SIN COLOCAR' => 'ATLIXCO', 'GESTOR QUE COLOCA' => 'ATLIXCO']);
    rmDirectory($may, ['GESTOR SIN COLOCAR' => ['ACTIVO', 'GESTOR DE CRÉDITO'], 'GESTOR QUE COLOCA' => ['ACTIVO', 'GESTOR DE CRÉDITO']]);
    DB::table('fact_placements')->insert(['period_id' => $may->id, 'promoter_name' => 'GESTOR QUE COLOCA', 'normalized_promoter_name' => 'gestor que coloca', 'amount' => 5000, 'created_at' => now(), 'updated_at' => now()]);

    rmBuild($abr, $may);

    $idle = rmRow($may, 'GESTOR SIN COLOCAR');
    expect($idle->is_active_for_period)->toBeTruthy()
        ->and($idle->movement_type)->toBe('activo')
        ->and($idle->reconciliation_status)->toBe('sin_actividad_comercial')
        ->and((bool) $idle->requires_review)->toBeFalse();
    expect(rmRow($may, 'GESTOR QUE COLOCA')->reconciliation_status)->toBe('activo_confirmado');
});

it('records a branch change as a movement, not as alta + baja', function () {
    $abr = rmPeriod('Abril 2026', 4);
    $may = rmPeriod('Mayo 2026', 5);
    rmPayroll($abr, ['GESTOR TRANSFERIDO' => 'ATLIXCO']);
    rmPayroll($may, ['GESTOR TRANSFERIDO' => 'TLAXCALA']);

    $stats = rmBuild($abr, $may)[$may->id];

    expect(rmRow($may, 'GESTOR TRANSFERIDO')->reconciliation_status)->toBe('cambio_sucursal')
        ->and($stats['altas'])->toBe(0)
        ->and($stats['bajas'])->toBe(0);
});

it('marks a reingreso only after a CONFIRMED baja, and counts it as alta', function () {
    $abr = rmPeriod('Abril 2026', 4);
    $may = rmPeriod('Mayo 2026', 5);
    $jun = rmPeriod('Junio 2026', 6);
    rmPayroll($abr, ['ELENA REINGRESA' => 'ATLIXCO', 'BASE UNO' => 'ATLIXCO']);
    rmPayroll($may, ['BASE UNO' => 'ATLIXCO', 'ELENA REINGRESA' => ['ATLIXCO', 'P050 FINIQUITO']]);
    // Finiquito pagado en Mayo, ausente en Junio → baja confirmada en Junio.
    $jul = rmPeriod('Julio 2026', 7);
    rmPayroll($jun, ['BASE UNO' => 'ATLIXCO']);
    rmPayroll($jul, ['BASE UNO' => 'ATLIXCO', 'ELENA REINGRESA' => 'ATLIXCO']);

    rmBuild($abr, $may, $jun, $jul);

    expect(rmRow($jun, 'ELENA REINGRESA')->reconciliation_status)->toBe('baja_confirmada')
        ->and(rmRow($jun, 'ELENA REINGRESA')->reconciliation_note)->toContain('FINIQUITO');
    $back = rmRow($jul, 'ELENA REINGRESA');
    expect($back->movement_type)->toBe('alta')->and($back->reconciliation_status)->toBe('reingreso');
});

it('pairs a probable duplicate (same person, name captured differently) and counts neither alta nor baja', function () {
    $abr = rmPeriod('Abril 2026', 4);
    $may = rmPeriod('Mayo 2026', 5);
    rmPayroll($abr, ['JUAN CARLOS PEREZ LOPEZ' => 'ATLIXCO', 'BASE DOS' => 'ATLIXCO']);
    rmPayroll($may, ['JUAN CARLOS PERES LOPEZ' => 'ATLIXCO', 'BASE DOS' => 'ATLIXCO']);

    $stats = rmBuild($abr, $may)[$may->id];

    $gone = rmRow($may, 'JUAN CARLOS PEREZ LOPEZ');
    $new = rmRow($may, 'JUAN CARLOS PERES LOPEZ');
    expect($gone->reconciliation_status)->toBe('registro_duplicado')->and($gone->movement_type)->toBe('ausencia')
        ->and($new->reconciliation_status)->toBe('registro_duplicado')->and($new->movement_type)->toBe('activo')
        ->and((bool) $new->requires_review)->toBeTrue();
    expect($stats['altas'])->toBe(0)->and($stats['bajas'])->toBe(0);
});

it('turns a NOI / directory contradiction into an incidence for authorized reconciliation, never an automatic baja', function () {
    $abr = rmPeriod('Abril 2026', 4);
    $may = rmPeriod('Mayo 2026', 5);
    rmPayroll($abr, ['FALTA EN NOI ACTIVO' => 'ATLIXCO', 'COBRA PERO INACTIVO' => 'ATLIXCO']);
    rmPayroll($may, ['COBRA PERO INACTIVO' => 'ATLIXCO']);
    rmDirectory($may, ['FALTA EN NOI ACTIVO' => ['ACTIVO'], 'COBRA PERO INACTIVO' => ['INACTIVO']]);

    $stats = rmBuild($abr, $may)[$may->id];

    $absent = rmRow($may, 'FALTA EN NOI ACTIVO');
    expect($absent->movement_type)->toBe('ausencia')
        ->and($absent->reconciliation_status)->toBe('contradiccion_fuentes')
        ->and((bool) $absent->requires_review)->toBeTrue();
    $paid = rmRow($may, 'COBRA PERO INACTIVO');
    expect($paid->is_active_for_period)->toBeTruthy()
        ->and($paid->reconciliation_status)->toBe('contradiccion_fuentes');
    expect($stats['bajas'])->toBe(0);
});

it('flags a mass payroll drop (likely incomplete NOI file) as no localizado instead of mass bajas', function () {
    $abr = rmPeriod('Abril 2026', 4);
    $may = rmPeriod('Mayo 2026', 5);
    $people = [];
    foreach (range(1, 12) as $i) {
        $people["COLABORADOR NUMERO {$i} ATLIXCO"] = 'ATLIXCO';
    }
    rmPayroll($abr, $people);
    rmPayroll($may, array_slice($people, 0, 4, true));
    rmDirectory($may, ['ALGUIEN MAS' => ['ACTIVO']]);

    $stats = rmBuild($abr, $may)[$may->id];

    expect($stats['mass_drop_detected'])->toBeTrue()
        ->and($stats['bajas'])->toBe(0)
        ->and(DB::table('period_employee_rosters')->where('period_id', $may->id)->where('reconciliation_status', 'no_localizado')->count())->toBe(8);
});

it('feeds the rotation index ONLY with confirmed bajas and reports the rest as pending', function () {
    $abr = rmPeriod('Abril 2026', 4);
    $may = rmPeriod('Mayo 2026', 5);
    rmPayroll($abr, ['ROT CONFIRMADA' => 'ATLIXCO', 'ROT POSIBLE' => 'ATLIXCO', 'ROT ACTIVA' => 'ATLIXCO']);
    rmPayroll($may, ['ROT ACTIVA' => 'ATLIXCO']);
    rmDirectory($may, ['ROT CONFIRMADA' => ['INACTIVO'], 'ROT ACTIVA' => ['ACTIVO']]);
    rmBuild($abr);
    rmAllowDerivedRotation();

    $res = app(RotacionDerivedFromNoiService::class)->deriveForPeriod($may);

    expect($res['bajas'])->toBe(1)
        ->and($res['bajas_pendientes'])->toBe(1)
        ->and((int) DB::table('fact_rotacion')->where('period_id', $may->id)->where('source', 'derived_noi')->sum('bajas'))->toBe(1);
});

it('applies an authorized reconciliation with a log entry, keeps it across rebuilds and refreshes rotation', function () {
    $abr = rmPeriod('Abril 2026', 4);
    $may = rmPeriod('Mayo 2026', 5);
    rmPayroll($abr, ['PEDRO POR CONCILIAR' => 'ATLIXCO', 'BASE TRES' => 'ATLIXCO']);
    rmPayroll($may, ['BASE TRES' => 'ATLIXCO']);
    rmBuild($abr, $may);
    rmAllowDerivedRotation();
    expect(rmRow($may, 'PEDRO POR CONCILIAR')->movement_type)->toBe('ausencia');

    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin)
        ->post(route('asignaciones-empleado-sucursal.review-movement', $may), [
            'nombre_normalizado' => 'pedro por conciliar', 'decision' => 'confirmar_baja', 'note' => 'RH confirma renuncia del 20/05.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $row = rmRow($may, 'PEDRO POR CONCILIAR');
    expect($row->movement_type)->toBe('baja')->and($row->reconciliation_status)->toBe('baja_confirmada')
        ->and($row->reconciliation_note)->toContain('conciliación autorizada');
    expect(DB::table('period_employee_movement_reviews')->where('period_id', $may->id)->where('decision', 'confirmar_baja')->where('reviewed_by', $admin->id)->count())->toBe(1);
    expect((int) DB::table('fact_rotacion')->where('period_id', $may->id)->where('source', 'derived_noi')->sum('bajas'))->toBe(1);

    rmBuild($may); // "Actualizar BD" otra vez: la decisión se conserva
    expect(rmRow($may, 'PEDRO POR CONCILIAR')->reconciliation_status)->toBe('baja_confirmada');

    // Descartar después (corrección) también queda en bitácora y la última manda.
    $this->actingAs($admin)->post(route('asignaciones-empleado-sucursal.review-movement', $may), [
        'nombre_normalizado' => 'pedro por conciliar', 'decision' => 'descartar_baja', 'note' => 'Error de captura: estaba de vacaciones.',
    ])->assertSessionHas('success');
    expect(rmRow($may, 'PEDRO POR CONCILIAR')->reconciliation_status)->toBe('ausencia_justificada')
        ->and(DB::table('period_employee_movement_reviews')->where('period_id', $may->id)->count())->toBe(2);
});

it('requires a reason and an authorized role to reconcile', function () {
    $abr = rmPeriod('Abril 2026', 4);
    $may = rmPeriod('Mayo 2026', 5);
    rmPayroll($abr, ['SIN PERMISO PRUEBA' => 'ATLIXCO', 'BASE CUATRO' => 'ATLIXCO']);
    rmPayroll($may, ['BASE CUATRO' => 'ATLIXCO']);
    rmBuild($abr, $may);

    $admin = User::factory()->create(['role' => 'admin']);
    $this->actingAs($admin)->post(route('asignaciones-empleado-sucursal.review-movement', $may), [
        'nombre_normalizado' => 'sin permiso prueba', 'decision' => 'confirmar_baja', 'note' => '',
    ])->assertSessionHasErrors('note');

    $gerencial = User::factory()->create(['role' => 'gerencial']);
    $this->actingAs($gerencial)->post(route('asignaciones-empleado-sucursal.review-movement', $may), [
        'nombre_normalizado' => 'sin permiso prueba', 'decision' => 'confirmar_baja', 'note' => 'Intento sin permiso.',
    ])->assertForbidden();

    expect(rmRow($may, 'SIN PERMISO PRUEBA')->movement_type)->toBe('ausencia')
        ->and(DB::table('period_employee_movement_reviews')->count())->toBe(0);
});

it('OKR eligibility excludes confirmed bajas but keeps a posible baja visible with a warning', function () {
    $abr = rmPeriod('Abril 2026', 4);
    $may = rmPeriod('Mayo 2026', 5);
    rmPayroll($abr, ['GESTOR BAJA REAL' => 'ATLIXCO', 'GESTOR AUSENTE DUDA' => 'ATLIXCO', 'GESTOR VIGENTE' => 'ATLIXCO']);
    rmPayroll($may, ['GESTOR VIGENTE' => 'ATLIXCO']);
    rmDirectory($may, ['GESTOR BAJA REAL' => ['INACTIVO'], 'GESTOR VIGENTE' => ['ACTIVO']]);
    rmBuild($abr, $may);

    $resolver = app(OkrEmployeeBranchResolver::class);
    $real = Employee::query()->where('normalized_name', 'gestor baja real')->value('id');
    $doubt = Employee::query()->where('normalized_name', 'gestor ausente duda')->value('id');
    $active = Employee::query()->where('normalized_name', 'gestor vigente')->value('id');

    expect($resolver->employeeIsActive($real))->toBeFalse()
        ->and($resolver->employeeIsActive($active))->toBeTrue()
        ->and($resolver->employeeIsActive($doubt))->toBeTrue();

    $codes = collect($resolver->statusFlags([$doubt])[$doubt])->pluck('code')->all();
    expect($codes)->toContain('pending_reconciliation')->toContain('baja_sin_noi');
});

it('keeps previous periods visible in the labor history and never shows unconfirmed absences as bajas', function () {
    $abr = rmPeriod('Abril 2026', 4);
    $may = rmPeriod('Mayo 2026', 5);
    rmPayroll($abr, ['HIST UNO' => 'ATLIXCO', 'HIST DOS' => 'ATLIXCO']);
    rmPayroll($may, ['HIST UNO' => 'ATLIXCO']);
    rmBuild($abr, $may);
    $abrBefore = DB::table('period_employee_rosters')->where('period_id', $abr->id)->orderBy('id')->get(['nombre_normalizado', 'movement_type', 'reconciliation_status'])->toArray();

    $admin = User::factory()->create(['role' => 'admin']);
    $props = $this->actingAs($admin)->get(route('asignaciones-empleado-sucursal.index', ['period_id' => $may->id]))
        ->assertOk()->viewData('page')['props'];

    expect($props['summary']['leavers'])->toBe(0)
        ->and($props['summary']['pendientes_conciliacion'])->toBe(1)
        ->and($props['summary']['plantilla'])->toBe(1)
        ->and(collect($props['labor']['pending'])->pluck('nombre_normalizado')->all())->toBe(['hist dos'])
        ->and(collect($props['labor_history'])->pluck('label')->all())->toContain('Abril 2026', 'Mayo 2026')
        ->and($props['can_reconcile'])->toBeTrue();

    // Construir/consultar Mayo no reescribe Abril.
    expect(DB::table('period_employee_rosters')->where('period_id', $abr->id)->orderBy('id')->get(['nombre_normalizado', 'movement_type', 'reconciliation_status'])->toArray())->toEqual($abrBefore);
});
