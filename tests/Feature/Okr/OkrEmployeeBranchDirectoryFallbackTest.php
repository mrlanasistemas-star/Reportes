<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\EmployeeBranchAssignment;
use App\Models\Period;
use App\Models\User;
use App\Services\Okr\OkrEmployeeBranchResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Corrección 15-oct-2026 (cierre de errores OKR) — caso reproducido: DAVID
 * GAMBOA CANO (oficina/ruta CHOL) y RAFAEL PEREZ CHAO (oficina/ruta TOCH)
 * pertenecen oficialmente a ATLIXCO (mismo catálogo de equivalencias que
 * BranchResolverService ya usa para el resto de Reportería), pero son altas
 * recientes SIN ninguna fila todavía en `employee_branch_assignments`
 * (ningún movimiento transaccional — NOI/cobranza/colocación — procesado
 * para ellos). Antes esto se interpretaba como "no pertenece a ninguna
 * sucursal" y bloqueaba la asignación/activación de su OKR individual.
 */
function fbPeriod(): Period
{
    return Period::query()->create(['name' => 'Oct 2026', 'code' => 'FB-' . uniqid('', true), 'type' => 'monthly', 'year' => 2026, 'month' => 10, 'sequence' => 1, 'start_date' => '2026-10-01', 'end_date' => '2026-10-31']);
}

function fbDirectoryUpload(Period $period): int
{
    $sourceId = DB::table('data_sources')->insertGetId(['code' => 'lendus_dir_' . uniqid(), 'name' => 'Directorio Lendus', 'created_at' => now(), 'updated_at' => now()]);
    $uploader = User::factory()->create();

    return DB::table('report_uploads')->insertGetId([
        'period_id' => $period->id, 'data_source_id' => $sourceId, 'uploaded_by' => $uploader->id, 'original_name' => 'dir.xlsx',
        'stored_path' => 'x', 'status' => 'processed', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

// Todas las filas de UNA misma carga (report_upload_id) — igual que el Excel
// real de personal, donde todos los colaboradores vienen en el mismo archivo.
function fbDirectoryRow(int $uploadId, string $nombre, string $puesto, string $estatus, ?int $branchId): void
{
    DB::table('lendus_employee_directory')->insert([
        'report_upload_id' => $uploadId, 'nombre' => $nombre, 'normalized_name' => mb_strtolower($nombre), 'puesto' => $puesto,
        'estatus' => $estatus, 'inferred_branch_id' => $branchId, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('resolves the official branch from the Lendus directory (CHOL/TOCH -> ATLIXCO) when there is still no employee_branch_assignments row', function () {
    $atlixco = Branch::query()->create(['code' => 'ATX', 'name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'is_active' => true]);
    $orizaba = Branch::query()->create(['code' => 'ORI', 'name' => 'ORIZABA', 'normalized_name' => 'orizaba', 'is_active' => true]);
    $period = fbPeriod();

    $david = Employee::query()->create(['full_name' => 'DAVID GAMBOA CANO', 'normalized_name' => 'david gamboa cano', 'is_active' => true]);
    $rafael = Employee::query()->create(['full_name' => 'RAFAEL PEREZ CHAO', 'normalized_name' => 'rafael perez chao', 'is_active' => true]);

    // Alta reciente: NINGUNA fila en employee_branch_assignments para ninguno de los dos.
    expect(EmployeeBranchAssignment::query()->whereIn('employee_id', [$david->id, $rafael->id])->exists())->toBeFalse();

    // El directorio Lendus vigente YA resolvió su oficina/ruta (CHOL/TOCH) a ATLIXCO.
    $uploadId = fbDirectoryUpload($period);
    fbDirectoryRow($uploadId, 'DAVID GAMBOA CANO', 'GESTOR DE CRÉDITO', 'ACTIVO', $atlixco->id);
    fbDirectoryRow($uploadId, 'RAFAEL PEREZ CHAO', 'GESTOR DE CRÉDITO', 'ACTIVO', $atlixco->id);

    $resolver = app(OkrEmployeeBranchResolver::class);

    expect($resolver->currentBranchIdFor($david->id))->toBe($atlixco->id);
    expect($resolver->currentBranchIdFor($rafael->id))->toBe($atlixco->id);
    expect($resolver->employeeBelongsToBranch($david->id, $atlixco->id))->toBeTrue();
    expect($resolver->employeeBelongsToBranch($rafael->id, $atlixco->id))->toBeTrue();
    expect($resolver->employeeBelongsToBranch($david->id, $orizaba->id))->toBeFalse();

    // También deben aparecer en el buscador de colaboradores de ATLIXCO (wizard de asignación).
    $names = $resolver->employeesForBranch($atlixco->id)->pluck('full_name');
    expect($names)->toContain('DAVID GAMBOA CANO');
    expect($names)->toContain('RAFAEL PEREZ CHAO');
});

it('never lets the directory fallback override an explicit employee_branch_assignments row pointing elsewhere', function () {
    $atlixco = Branch::query()->create(['code' => 'ATX', 'name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'is_active' => true]);
    $orizaba = Branch::query()->create(['code' => 'ORI', 'name' => 'ORIZABA', 'normalized_name' => 'orizaba', 'is_active' => true]);
    $period = fbPeriod();

    $employee = Employee::query()->create(['full_name' => 'GESTOR CON ASIGNACION REAL', 'normalized_name' => 'gestor con asignacion real', 'is_active' => true]);
    EmployeeBranchAssignment::query()->create(['period_id' => $period->id, 'employee_id' => $employee->id, 'branch_id' => $orizaba->id]);

    // El directorio (desactualizado o con una ruta mal mapeada) dice ATLIXCO — la
    // asignación transaccional confirmada manda, nunca el fallback.
    fbDirectoryRow(fbDirectoryUpload($period), 'GESTOR CON ASIGNACION REAL', 'GESTOR DE CRÉDITO', 'ACTIVO', $atlixco->id);

    $resolver = app(OkrEmployeeBranchResolver::class);
    expect($resolver->currentBranchIdFor($employee->id))->toBe($orizaba->id);
    expect($resolver->employeeBelongsToBranch($employee->id, $atlixco->id))->toBeFalse();
});

it('excludes vacancy placeholders (ACTIVO but not a real person) from branch eligibility', function () {
    $atlixco = Branch::query()->create(['code' => 'ATX', 'name' => 'ATLIXCO', 'normalized_name' => 'atlixco', 'is_active' => true]);
    $period = fbPeriod();

    fbDirectoryRow(fbDirectoryUpload($period), 'VACANTE SUBGERENTE MIACATLAN', 'SUBGERENTE', 'ACTIVO', $atlixco->id);
    // Si por alguna razón existiera un Employee real con ese nombre de plaza, igual se excluye.
    $vacante = Employee::query()->create(['full_name' => 'VACANTE SUBGERENTE MIACATLAN', 'normalized_name' => 'vacante subgerente miacatlan', 'is_active' => true]);

    $resolver = app(OkrEmployeeBranchResolver::class);
    expect($resolver->employeesForBranch($atlixco->id)->pluck('full_name'))->not->toContain('VACANTE SUBGERENTE MIACATLAN');
    expect($resolver->employeeIsActive($vacante->id))->toBeFalse();
});
