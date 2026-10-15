<?php

namespace App\Services\Access;

use App\Models\Branch;
use App\Models\User;
use App\Services\Okr\OkrEmployeeBranchResolver;
use App\Services\Reporting\OperativeBranchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Alcance de DATOS por sucursal (Fase 6.4) — fuente ÚNICA de "¿de qué
 * sucursales puede consultar información este usuario?". Complementa a
 * ModuleAccessService (qué módulos) y ActionPermissionService (qué acciones):
 * aquí se decide SOBRE QUÉ sucursales.
 *
 * Reglas:
 *   - Administrador: alcance general siempre (no se configura).
 *   - users.branch_scope = 'all': alcance general (elegido explícitamente).
 *   - users.branch_scope = 'branches': solo las sucursales de user_branch_scopes
 *     (lista vacía = ninguna sucursal: no ve datos por sucursal).
 *   - users.branch_scope = NULL (usuarios previos a esta fase, migración
 *     controlada): nivel gerencial → alcance general (como hasta hoy, hasta que
 *     un administrador lo restrinja); nivel colaborador → solo lo propio: la
 *     sucursal vigente de su colaborador vinculado (o ninguna).
 *
 * Alcance general = puede ver el consolidado/reporte general. Alcance
 * restringido = solo vistas por sucursal/gestor de SUS sucursales; todo lo que
 * agrega todas las sucursales (consolidado, radiografía general, comparativo
 * general, originales cargados) responde 403 con un mensaje claro.
 *
 * Sin caché entre peticiones a propósito: un cambio de alcance aplica en la
 * siguiente petición del usuario (sin privilegios residuales).
 */
class DataScopeService
{
    public const MODE_ALL = 'all';
    public const MODE_BRANCHES = 'branches';

    /** @var array<int, ?list<int>> memo por instancia (una petición). */
    private array $memo = [];

    public function __construct(private readonly OperativeBranchService $operative)
    {
    }

    public function isGeneral(?User $user): bool
    {
        return $user !== null && $this->allowedBranchIds($user) === null;
    }

    public function isRestricted(?User $user): bool
    {
        return $user !== null && ! $this->isGeneral($user);
    }

    /** @return list<int>|null null = todas las sucursales (alcance general). */
    public function allowedBranchIds(User $user): ?array
    {
        if (array_key_exists($user->id, $this->memo)) {
            return $this->memo[$user->id];
        }

        return $this->memo[$user->id] = $this->computeAllowed($user);
    }

    /** @return list<int>|null */
    private function computeAllowed(User $user): ?array
    {
        if ($user->isAdmin()) {
            return null;
        }

        $mode = $this->modeOf($user);

        if ($mode === self::MODE_ALL) {
            return null;
        }

        if ($mode === self::MODE_BRANCHES) {
            return $this->storedBranchIds($user);
        }

        // NULL — usuario previo a la Fase 6.4.
        if ($user->isGerencial()) {
            return null;
        }

        // Colaborador: solo lo propio — la sucursal vigente de su colaborador vinculado.
        if ($user->employee_id) {
            $own = app(OkrEmployeeBranchResolver::class)->currentBranchIdFor((int) $user->employee_id);

            return $own ? [(int) $own] : [];
        }

        return [];
    }

    public function modeOf(User $user): ?string
    {
        if (! $this->schemaReady()) {
            return null;
        }
        $mode = $user->getAttribute('branch_scope');

        return in_array($mode, [self::MODE_ALL, self::MODE_BRANCHES], true) ? $mode : null;
    }

    /** @return list<int> */
    public function storedBranchIds(User $user): array
    {
        if (! $this->schemaReady()) {
            return [];
        }

        return DB::table('user_branch_scopes')->where('user_id', $user->id)
            ->orderBy('branch_id')->pluck('branch_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    public function allows(User $user, ?int $branchId): bool
    {
        $allowed = $this->allowedBranchIds($user);

        return $allowed === null || ($branchId !== null && in_array((int) $branchId, $allowed, true));
    }

    /** 403 con mensaje claro si la sucursal no está en el alcance del usuario. */
    public function authorizeBranch(User $user, ?int $branchId): void
    {
        if ($branchId === null || $this->allows($user, $branchId)) {
            return;
        }

        $name = Branch::query()->whereKey($branchId)->value('name') ?? 'la sucursal solicitada';

        abort(403, "No tienes acceso a la información de {$name}. Tu alcance autorizado: {$this->label($user)}. "
            . 'Si necesitas consultarla, pide a un administrador que amplíe tu alcance en Usuarios.');
    }

    /** Vistas/exportes que agregan TODAS las sucursales: solo alcance general. */
    public function requireGeneral(User $user, string $what = 'Este reporte'): void
    {
        if ($this->isGeneral($user)) {
            return;
        }

        abort(403, "{$what} incluye todas las sucursales y solo está disponible para usuarios con alcance general. "
            . "Consulta la información de tus sucursales ({$this->label($user)}) o pide a un administrador que amplíe tu alcance.");
    }

    /**
     * Sucursal efectiva para pantallas con selector opcional (Dashboard): general
     * sin selección → null (todas); restringido sin selección → su primera
     * sucursal; una sucursal fuera del alcance → 403.
     */
    public function resolveBranch(User $user, ?int $requested): ?int
    {
        if ($requested) {
            $this->authorizeBranch($user, $requested);

            return $requested;
        }

        if ($this->isGeneral($user)) {
            return null;
        }

        $default = $this->defaultBranchId($user);
        if ($default === null) {
            abort(403, 'Tu usuario no tiene sucursales autorizadas, así que no hay información que mostrar. Pide a un administrador que te asigne al menos una sucursal en Usuarios.');
        }

        return $default;
    }

    public function defaultBranchId(User $user): ?int
    {
        $allowed = $this->allowedBranchIds($user);
        if ($allowed === null || $allowed === []) {
            return null;
        }

        // Prefiere una sucursal operativa (las que tienen radiografía) en orden alfabético.
        $first = Branch::query()->whereIn('id', $allowed)->whereIn('name', $this->operative->names())->orderBy('name')->value('id');

        return (int) ($first ?? $allowed[0]);
    }

    /**
     * Filtra un catálogo de sucursales (arreglos/objetos con id) al alcance del usuario.
     *
     * @template T
     * @param iterable<T> $options
     * @return list<T>
     */
    public function filterBranchOptions(User $user, iterable $options, string $key = 'id'): array
    {
        $allowed = $this->allowedBranchIds($user);
        $out = [];
        foreach ($options as $option) {
            $id = is_array($option) ? ($option[$key] ?? null) : ($option->{$key} ?? null);
            if ($allowed === null || ($id !== null && in_array((int) $id, $allowed, true))) {
                $out[] = $option;
            }
        }

        return $out;
    }

    /** @return list<string>|null null = todas. Nombres en mayúsculas tal como están en branches.name. */
    public function allowedBranchNames(User $user): ?array
    {
        $allowed = $this->allowedBranchIds($user);
        if ($allowed === null) {
            return null;
        }

        return Branch::query()->whereIn('id', $allowed)->orderBy('name')->pluck('name')->all();
    }

    public function allowsBranchName(User $user, ?string $name): bool
    {
        $names = $this->allowedBranchNames($user);
        if ($names === null) {
            return true;
        }
        if ($name === null || trim($name) === '') {
            return false;
        }
        $needle = mb_strtoupper(trim($name));

        return in_array($needle, array_map(fn ($n) => mb_strtoupper(trim($n)), $names), true);
    }

    /**
     * Sucursal del colaborador: la de su roster del periodo; si no, su
     * asignación del periodo; si no, su sucursal vigente (misma fuente que OKR).
     */
    public function employeeBranchId(int $employeeId, ?int $periodId = null): ?int
    {
        if ($periodId) {
            $fromRoster = DB::table('period_employee_rosters')->where('period_id', $periodId)
                ->where('employee_id', $employeeId)->whereNotNull('branch_id')->value('branch_id');
            if ($fromRoster) {
                return (int) $fromRoster;
            }
            $fromAssignment = DB::table('employee_branch_assignments')->where('period_id', $periodId)
                ->where('employee_id', $employeeId)->whereNotNull('branch_id')->value('branch_id');
            if ($fromAssignment) {
                return (int) $fromAssignment;
            }
        }

        $current = app(OkrEmployeeBranchResolver::class)->currentBranchIdFor($employeeId);

        return $current ? (int) $current : null;
    }

    public function allowsEmployee(User $user, int $employeeId, ?int $periodId = null): bool
    {
        if ($this->isGeneral($user)) {
            return true;
        }
        if ($user->employee_id && (int) $user->employee_id === $employeeId) {
            return true; // lo propio
        }
        $branchId = $this->employeeBranchId($employeeId, $periodId);

        return $branchId !== null && $this->allows($user, $branchId);
    }

    public function authorizeEmployee(User $user, int $employeeId, ?int $periodId = null): void
    {
        if ($this->allowsEmployee($user, $employeeId, $periodId)) {
            return;
        }

        abort(403, "Ese colaborador no pertenece a tus sucursales autorizadas ({$this->label($user)}). "
            . 'Si necesitas consultarlo, pide a un administrador que amplíe tu alcance en Usuarios.');
    }

    /** Texto legible del alcance: "Todas las sucursales" o la lista de nombres. */
    public function label(User $user): string
    {
        $names = $this->allowedBranchNames($user);
        if ($names === null) {
            return 'Todas las sucursales';
        }

        // Colaborador previo a la Fase 6.4: «solo lo propio» (su sucursal vigente, si tiene).
        if (! $user->isAdmin() && $this->modeOf($user) === null && ! $user->isGerencial()) {
            return $names === [] ? 'Solo lo propio' : 'Solo lo propio (' . implode(', ', $names) . ')';
        }

        return $names === [] ? 'ninguna sucursal asignada' : implode(', ', $names);
    }

    /** Resumen para la UI (tabla de Usuarios, props compartidas). */
    public function describe(User $user): array
    {
        $allowed = $this->allowedBranchIds($user);

        return [
            'mode' => $user->isAdmin() ? 'admin' : ($this->modeOf($user) ?? 'legacy'),
            'general' => $allowed === null,
            'branch_ids' => $allowed ?? [],
            'label' => $this->label($user),
        ];
    }

    /** Catálogo de sucursales asignables (las 13 operativas). @return list<array{id:int,name:string}> */
    public function assignableBranches(): array
    {
        return Branch::query()->whereIn('name', $this->operative->names())->orderBy('name')
            ->get(['id', 'name'])->map(fn ($b) => ['id' => (int) $b->id, 'name' => $b->name])->values()->all();
    }

    /**
     * Delegación: ¿el actor puede conceder este alcance? Nadie concede más del
     * propio — un actor restringido solo asigna subconjuntos de sus sucursales y
     * nunca "todas".
     *
     * @param list<int> $branchIds
     */
    public function canGrant(User $actor, string $mode, array $branchIds): bool
    {
        if ($this->isGeneral($actor)) {
            return true;
        }
        if ($mode === self::MODE_ALL) {
            return false;
        }
        $own = $this->allowedBranchIds($actor) ?? [];

        return array_diff(array_map('intval', $branchIds), $own) === [];
    }

    /** ¿El alcance del objetivo cabe dentro del del actor? (para administrarlo sin escalar). */
    public function contains(User $actor, User $target): bool
    {
        if ($this->isGeneral($actor)) {
            return true;
        }
        $targetAllowed = $this->allowedBranchIds($target);
        if ($targetAllowed === null) {
            return false;
        }

        return array_diff($targetAllowed, $this->allowedBranchIds($actor) ?? []) === [];
    }

    /**
     * Guarda el alcance de un usuario (valida delegación). Devuelve el texto
     * legible del alcance nuevo.
     *
     * @param list<int> $branchIds
     */
    public function save(User $target, string $mode, array $branchIds, User $actor): string
    {
        $branchIds = array_values(array_unique(array_filter(array_map('intval', $branchIds))));
        $validIds = array_column($this->assignableBranches(), 'id');

        if (array_diff($branchIds, $validIds) !== []) {
            abort(422, 'Una de las sucursales elegidas no es una sucursal operativa válida.');
        }
        if (! $this->canGrant($actor, $mode, $branchIds)) {
            abort(403, 'No puedes conceder un alcance mayor al tuyo: solo puedes asignar sucursales que tú mismo tienes autorizadas.');
        }

        DB::transaction(function () use ($target, $mode, $branchIds, $actor) {
            $target->forceFill(['branch_scope' => $mode])->save();
            DB::table('user_branch_scopes')->where('user_id', $target->id)->delete();
            if ($mode === self::MODE_BRANCHES && $branchIds !== []) {
                $now = now();
                DB::table('user_branch_scopes')->insert(array_map(fn ($id) => [
                    'user_id' => $target->id, 'branch_id' => $id, 'granted_by' => $actor->id,
                    'created_at' => $now, 'updated_at' => $now,
                ], $branchIds));
            }
        });

        unset($this->memo[$target->id]);

        return $this->label($target->fresh());
    }

    private static ?bool $schemaReady = null;

    private function schemaReady(): bool
    {
        return self::$schemaReady ??= Schema::hasTable('user_branch_scopes') && Schema::hasColumn('users', 'branch_scope');
    }
}
