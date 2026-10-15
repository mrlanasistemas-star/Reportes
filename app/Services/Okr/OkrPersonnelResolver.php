<?php

namespace App\Services\Okr;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Datos laborales VIGENTES para el módulo OKR (15-oct-2026): puesto real,
 * estatus, sucursal y estructura de mando, a partir del directorio de
 * colaboradores de Lendus que Reportería ya importa por periodo
 * (lendus_employee_directory). No existe en Reportes una relación explícita
 * de "jefe inmediato" ni integración activa con MR LANA People
 * (employee_external_identities está vacío) — por eso:
 *
 *  - El puesto se toma TAL CUAL del directorio vigente (nunca inferido).
 *  - El jefe inmediato solo se SUGIERE a partir de la estructura vigente de
 *    la sucursal (subgerente activo único para gestores; gerente activo
 *    único para subgerente/coordinación) y SIEMPRE se muestra como
 *    sugerencia con su fuente: un usuario autorizado lo confirma o corrige
 *    antes de emitir un documento. Si hay ambigüedad (dos subgerentes, sin
 *    gerente, etc.) no se sugiere nada.
 *  - "Vigente" = la carga cuyo PERIODO es el más reciente por fecha — nunca
 *    el mayor id de carga (una carga de Mayo puede ser posterior a la de Junio).
 */
class OkrPersonnelResolver
{
    private ?array $directoryCache = null;

    /** @return array{upload_id:int, period_id:?int, period_label:?string, period_end:?string}|null */
    public function currentDirectory(): ?array
    {
        if ($this->directoryCache !== null) {
            return $this->directoryCache ?: null;
        }

        $row = DB::table('lendus_employee_directory as d')
            ->join('report_uploads as u', 'u.id', '=', 'd.report_upload_id')
            ->leftJoin('periods as p', 'p.id', '=', 'u.period_id')
            ->select('d.report_upload_id', 'u.period_id', 'p.name as period_label', 'p.end_date as period_end')
            ->orderByDesc('p.end_date')
            ->orderByDesc('d.report_upload_id')
            ->first();

        $this->directoryCache = $row ? [
            'upload_id' => (int) $row->report_upload_id,
            'period_id' => $row->period_id ? (int) $row->period_id : null,
            'period_label' => $row->period_label,
            'period_end' => $row->period_end,
        ] : [];

        return $this->directoryCache ?: null;
    }

    /** Filas del directorio vigente para una persona (todas sus variantes de nombre canónico). */
    private function directoryRowsFor(int $employeeId): \Illuminate\Support\Collection
    {
        $directory = $this->currentDirectory();
        $norm = Employee::query()->whereKey($employeeId)->value('normalized_name');
        if (!$directory || !$norm) {
            return collect();
        }

        return DB::table('lendus_employee_directory')
            ->where('report_upload_id', $directory['upload_id'])
            ->where('normalized_name', $norm)
            ->get();
    }

    /**
     * @return array{position:?string, status:?string, branch_id:?int, branch_name:?string, code:?string, source:?string}
     */
    public function profileFor(int $employeeId): array
    {
        $rows = $this->directoryRowsFor($employeeId);
        // Si hay varias filas (dos códigos), se prefiere la ACTIVA.
        $row = $rows->sortBy(fn ($r) => mb_strtoupper((string) $r->estatus) === 'ACTIVO' ? 0 : 1)->first();
        $directory = $this->currentDirectory();

        return [
            'position' => $row && trim((string) $row->puesto) !== '' ? trim($row->puesto) : null,
            'status' => $row?->estatus,
            'branch_id' => $row?->inferred_branch_id ? (int) $row->inferred_branch_id : null,
            'branch_name' => $row?->inferred_branch_name,
            'code' => $row?->codigo,
            'source' => $row ? 'Directorio de colaboradores Lendus · ' . ($directory['period_label'] ?? 'periodo vigente') : null,
        ];
    }

    /** Puestos por id de empleado (lote, para listas). @return array<int, ?string> */
    public function positionsFor(array $employeeIds): array
    {
        $directory = $this->currentDirectory();
        $employees = Employee::query()->whereIn('id', $employeeIds)->pluck('normalized_name', 'id');
        if (!$directory || $employees->isEmpty()) {
            return array_fill_keys($employeeIds, null);
        }

        $rows = DB::table('lendus_employee_directory')
            ->where('report_upload_id', $directory['upload_id'])
            ->whereIn('normalized_name', $employees->filter()->values()->all())
            ->get(['normalized_name', 'puesto', 'estatus'])
            ->sortBy(fn ($r) => mb_strtoupper((string) $r->estatus) === 'ACTIVO' ? 0 : 1)
            ->unique('normalized_name')
            ->keyBy('normalized_name');

        $map = [];
        foreach ($employeeIds as $id) {
            $norm = $employees[$id] ?? null;
            $puesto = $norm && isset($rows[$norm]) ? trim((string) $rows[$norm]->puesto) : '';
            $map[$id] = $puesto !== '' ? $puesto : null;
        }

        return $map;
    }

    /**
     * normalized_name que en el directorio vigente aparecen SOLO como
     * INACTIVO (ninguna fila activa) — segunda señal de baja además de la
     * nómina NOI (15-oct-2026).
     *
     * @return string[]
     */
    public function inactiveOnlyNames(): array
    {
        $directory = $this->currentDirectory();
        if (!$directory) {
            return [];
        }

        return DB::table('lendus_employee_directory')
            ->where('report_upload_id', $directory['upload_id'])
            ->whereNotNull('normalized_name')
            ->select('normalized_name', DB::raw("SUM(CASE WHEN UPPER(estatus) = 'ACTIVO' THEN 1 ELSE 0 END) as activos"))
            ->groupBy('normalized_name')
            ->get()
            ->filter(fn ($r) => (int) $r->activos === 0)
            ->pluck('normalized_name')
            ->all();
    }

    /** Puestos ACTIVOS de una sucursal en el directorio vigente (sin vacantes). */
    private function activeByPosition(int $branchId, string $position): \Illuminate\Support\Collection
    {
        $directory = $this->currentDirectory();
        if (!$directory) {
            return collect();
        }

        return DB::table('lendus_employee_directory')
            ->where('report_upload_id', $directory['upload_id'])
            ->where('inferred_branch_id', $branchId)
            ->whereRaw('UPPER(estatus) = ?', ['ACTIVO'])
            ->whereRaw('UPPER(puesto) = ?', [mb_strtoupper($position)])
            ->where('nombre', 'not like', 'VACANTE%')
            ->get(['nombre', 'normalized_name', 'puesto'])
            ->unique('normalized_name')
            ->values();
    }

    /**
     * Sugerencia de jefe inmediato con su fundamento — nunca un hecho hasta
     * que un usuario autorizado la confirme en la revisión del documento.
     *
     * @return array{name:?string, position:?string, employee_id:?int, basis:string, is_suggestion:bool}
     */
    public function suggestImmediateBoss(int $employeeId, int $branchId): array
    {
        $profile = $this->profileFor($employeeId);
        $position = mb_strtoupper((string) $profile['position']);
        $directory = $this->currentDirectory();
        $periodText = $directory['period_label'] ?? 'periodo vigente';
        $selfNorm = Employee::query()->whereKey($employeeId)->value('normalized_name');

        $none = fn (string $basis) => ['name' => null, 'position' => null, 'employee_id' => null, 'basis' => $basis, 'is_suggestion' => true];

        if ($position === '') {
            return $none('Sin puesto vigente en el directorio — captura el jefe inmediato manualmente.');
        }

        $chain = match (true) {
            str_contains($position, 'GESTOR') => ['SUBGERENTE', 'GERENTE'],
            $position === 'SUBGERENTE', str_starts_with($position, 'COORDINADOR') && !str_contains($position, 'REGIONAL') => ['GERENTE'],
            default => [],
        };
        if ($chain === []) {
            return $none("El puesto {$profile['position']} no tiene jefe inmediato deducible en la sucursal — captúralo manualmente.");
        }

        foreach ($chain as $bossPosition) {
            $candidates = $this->activeByPosition($branchId, $bossPosition)->reject(fn ($r) => $r->normalized_name === $selfNorm)->values();
            if ($candidates->count() > 1) {
                return $none("Hay {$candidates->count()} {$bossPosition} activos en la sucursal — elige el jefe inmediato real.");
            }
            if ($candidates->count() === 1) {
                $boss = $candidates->first();

                return [
                    'name' => $boss->nombre,
                    'position' => $boss->puesto,
                    'employee_id' => Employee::query()->where('normalized_name', $boss->normalized_name)->orderBy('id')->value('id'),
                    'basis' => "Sugerido: {$boss->puesto} activo de la sucursal según el directorio Lendus ({$periodText}). Confírmalo antes de emitir.",
                    'is_suggestion' => true,
                ];
            }
        }

        return $none('No hay subgerente ni gerente activo registrado en la sucursal — captura el jefe inmediato.');
    }

    /** Gerente activo ÚNICO de la sucursal (sugerencia de titular del OKR de sucursal). */
    public function suggestBranchManager(int $branchId): ?array
    {
        $managers = $this->activeByPosition($branchId, 'GERENTE');
        if ($managers->count() !== 1) {
            return null;
        }
        $m = $managers->first();
        $employeeId = Employee::query()->where('normalized_name', $m->normalized_name)->orderBy('id')->value('id');

        return $employeeId ? ['employee_id' => (int) $employeeId, 'name' => $m->nombre, 'position' => $m->puesto] : null;
    }
}
