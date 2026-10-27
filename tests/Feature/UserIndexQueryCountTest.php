<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Period;
use App\Models\PeriodEmployeeRoster;
use App\Models\User;
use App\Services\Access\ActionPermissionService;
use App\Services\Access\DataScopeService;
use App\Services\Access\ModuleAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->withoutVite());

/**
 * Rendimiento del módulo Usuarios (27-oct-2026): el número de consultas de
 * /usuarios NO debe crecer con el número de usuarios ni de colaboradores.
 * Antes, cada fila calculaba su sucursal (membresía OKR: ~8 consultas), su
 * alcance por sucursal y la delegación una por una.
 */
function uqSeed(int $n, Period $period, Branch $branch): void
{
    static $seq = 0;
    for ($i = 0; $i < $n; $i++) {
        $seq++;
        $employee = Employee::query()->create(['full_name' => "PERSONA {$seq}", 'normalized_name' => "persona {$seq}", 'is_active' => true]);
        PeriodEmployeeRoster::query()->create([
            'period_id' => $period->id, 'employee_id' => $employee->id, 'employee_key' => "uq{$seq}", 'nombre_normalizado' => $employee->normalized_name,
            'branch_id' => $branch->id, 'branch_name' => $branch->name, 'is_active_for_period' => true, 'movement_type' => 'activo',
        ]);
        // Colaborador sin vínculo (solo aparece en el selector de colaboradores).
        Employee::query()->create(['full_name' => "LIBRE {$seq}", 'normalized_name' => "libre {$seq}", 'is_active' => true]);

        $kind = $seq % 3;
        $user = User::factory()->create([
            'role' => $kind === 0 ? 'gerencial' : 'colaborador',
            'access_enabled_at' => now(),
            'employee_id' => $employee->id,
        ]);
        if ($kind === 0) {
            // Gerencial con sucursales específicas.
            $user->forceFill(['branch_scope' => DataScopeService::MODE_BRANCHES])->save();
            DB::table('user_branch_scopes')->insert(['user_id' => $user->id, 'branch_id' => $branch->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        // $kind 1 y 2: colaborador previo a la Fase 6.4 (alcance = su sucursal vigente).
    }
}

/**
 * Consultas de la carga COMPLETA del módulo: la visita inicial + la petición
 * automática de la prop diferida `employees` (como la hace Inertia en el navegador).
 */
function uqCountIndexQueries(User $actor): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $page = test()->actingAs($actor)->get(route('usuarios.index'))->assertOk()->viewData('page');
    $initial = count(DB::getQueryLog());

    DB::flushQueryLog();
    $deferred = test()->actingAs($actor)->get(route('usuarios.index'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) ($page['version'] ?? ''),
        'X-Inertia-Partial-Component' => 'usuarios/Index',
        'X-Inertia-Partial-Data' => 'employees',
    ])->assertOk()->json('props');
    $total = $initial + count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($deferred)->toHaveKey('employees');

    return $total;
}

function uqScenario(): array
{
    $period = Period::query()->create(['name' => 'Septiembre 2026', 'code' => 'UQ-' . uniqid(), 'type' => 'monthly', 'year' => 2026, 'month' => 9, 'sequence' => 1, 'start_date' => '2026-08-24', 'end_date' => '2026-09-20']);
    $branch = Branch::query()->create(['code' => 'ORI', 'name' => 'ORIZABA', 'normalized_name' => 'orizaba', 'is_active' => true]);

    return [$period, $branch];
}

it('admin: las consultas de /usuarios quedan acotadas aunque crezcan usuarios y colaboradores', function () {
    [$period, $branch] = uqScenario();
    $admin = User::factory()->create(['role' => 'admin', 'access_enabled_at' => now()]);

    uqSeed(3, $period, $branch);
    $small = uqCountIndexQueries($admin);

    uqSeed(30, $period, $branch);
    $large = uqCountIndexQueries($admin);

    fwrite(STDERR, "\n[usuarios.index admin] consultas con 4 usuarios: {$small}; con 34 usuarios: {$large}\n");
    expect($large - $small)->toBeLessThanOrEqual(2);
});

it('actor delegado (no admin): las consultas de /usuarios no crecen por fila', function () {
    [$period, $branch] = uqScenario();
    app(ModuleAccessService::class)->save(['gerencial' => ['dashboard', 'okr', 'usuarios']]);
    app(ActionPermissionService::class)->save(['gerencial' => ['usuarios' => ['ver', 'crear', 'editar', 'gestionar_acceso', 'restablecer', 'invitar', 'asignar_rol']]]);
    $actor = User::factory()->create(['role' => 'gerencial', 'access_enabled_at' => now()]);
    $actor->forceFill(['branch_scope' => DataScopeService::MODE_ALL])->save();

    uqSeed(3, $period, $branch);
    $small = uqCountIndexQueries($actor);

    uqSeed(30, $period, $branch);
    $large = uqCountIndexQueries($actor);

    fwrite(STDERR, "\n[usuarios.index delegado] consultas con 4 usuarios: {$small}; con 34 usuarios: {$large}\n");
    expect($large - $small)->toBeLessThanOrEqual(2);
});

it('la fila conserva sucursal vigente, alcance y permisos calculados en lote', function () {
    [$period, $branch] = uqScenario();
    $admin = User::factory()->create(['role' => 'admin', 'access_enabled_at' => now()]);
    uqSeed(3, $period, $branch);

    $this->actingAs($admin)->get(route('usuarios.index'))->assertInertia(function ($page) use ($admin) {
        $users = collect($page->toArray()['props']['users']);
        $colab = $users->firstWhere('role', 'colaborador');
        $ger = $users->firstWhere('role', 'gerencial');
        $self = $users->firstWhere('id', $admin->id);

        expect($colab['current_branch'])->toBe('ORIZABA')
            ->and($colab['data_scope'])->toBe(['mode' => 'legacy', 'general' => false, 'branch_ids' => [Branch::query()->value('id')], 'label' => 'Solo lo propio (ORIZABA)'])
            ->and($ger['data_scope']['mode'])->toBe('branches')
            ->and($ger['data_scope']['label'])->toBe('ORIZABA')
            ->and($colab['can']['change_role'])->toBeTrue()
            ->and($self['can']['change_role'])->toBeFalse()
            ->and($self['data_scope']['mode'])->toBe('admin');
    });

    // `employees` es diferida: no viaja en la visita inicial, sí en la petición parcial.
    $page = $this->actingAs($admin)->get(route('usuarios.index'))->viewData('page');
    expect($page['props'])->not->toHaveKey('employees')
        ->and($page['deferredProps']['default'] ?? [])->toContain('employees');

    $employees = collect($this->actingAs($admin)->get(route('usuarios.index'), [
        'X-Inertia' => 'true', 'X-Inertia-Version' => (string) ($page['version'] ?? ''),
        'X-Inertia-Partial-Component' => 'usuarios/Index', 'X-Inertia-Partial-Data' => 'employees',
    ])->json('props.employees'));
    $linked = $employees->first(fn ($e) => str_starts_with($e['full_name'], 'PERSONA '));
    $free = $employees->first(fn ($e) => str_starts_with($e['full_name'], 'LIBRE '));
    expect($linked['current_branch'])->toBe('ORIZABA')
        ->and($linked['linked_user_id'])->toBe(User::query()->where('employee_id', $linked['id'])->value('id'))
        ->and($free['linked_user_id'])->toBeNull()
        ->and($free['current_branch'])->toBeNull();
});
