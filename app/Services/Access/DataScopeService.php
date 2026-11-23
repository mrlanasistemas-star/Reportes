<?php

namespace App\Services\Access;

use App\Models\Branch;
use App\Models\User;
use App\Services\Okr\OkrEmployeeBranchResolver;
use App\Services\Reporting\OperativeBranchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
 *
 * Lo usan controladores de reportes, Dashboard, OKR, API y el módulo Usuarios
 * (ver/editar alcance). Lee/escribe `users.branch_scope` y `user_branch_scopes`.
 */
class DataScopeService
{
    /** users.branch_scope: alcance general (todas las sucursales). */
    public const MODE_ALL = 'all';

    /** users.branch_scope: solo las sucursales listadas en user_branch_scopes. */
    public const MODE_BRANCHES = 'branches';

    /** @var array<int, ?list<int>> memo por instancia (una petición). */
    private array $memo = [];

    /** @var array<string, list<string>> nombres por conjunto de ids (una petición). */
    private array $namesMemo = [];

    /**
     * @param  OperativeBranchService  $operative  Lista de las sucursales operativas (las que tienen radiografía).
     */
    public function __construct(private readonly OperativeBranchService $operative) {}

    /**
     * ¿El usuario tiene alcance general (todas las sucursales)?
     *
     * @param  User|null  $user  Usuario autenticado (null = false).
     */
    public function isGeneral(?User $user): bool
    {
        return $user !== null && $this->allowedBranchIds($user) === null;
    }

    /**
     * ¿El usuario existe y su alcance está restringido a ciertas sucursales?
     *
     * @param  User|null  $user  Usuario autenticado (null = false).
     */
    public function isRestricted(?User $user): bool
    {
        return $user !== null && ! $this->isGeneral($user);
    }

    /**
     * Sucursales que el usuario puede consultar (memo por instancia).
     *
     * @param  User  $user  Usuario.
     * @return list<int>|null null = todas las sucursales (alcance general).
     */
    public function allowedBranchIds(User $user): ?array
    {
        if (array_key_exists($user->id, $this->memo)) {
            return $this->memo[$user->id];
        }

        return $this->memo[$user->id] = $this->computeAllowed($user);
    }

    /**
     * Precalcula en LOTE el alcance de varios usuarios (tabla del módulo
     * Usuarios) con un número fijo de consultas, en vez de 1–N consultas por
     * fila. Mismas reglas que computeAllowed(); solo llena el memo de ESTA
     * instancia (sigue sin haber caché entre peticiones).
     *
     * @param  iterable<User>  $users  Usuarios de la tabla.
     * @param  OkrEmployeeBranchResolver|null  $resolver  Resolver de sucursal vigente a reutilizar (si no, se toma del contenedor).
     */
    public function preload(iterable $users, ?OkrEmployeeBranchResolver $resolver = null): void
    {
        $pending = collect($users)->filter(fn ($u) => $u instanceof User && ! array_key_exists($u->id, $this->memo))->values();
        if ($pending->isEmpty()) {
            return;
        }

        $modes = $pending->mapWithKeys(fn (User $u) => [$u->id => $u->isAdmin() ? null : $this->modeOf($u)])->all();

        $stored = [];
        $branchModeIds = $pending->filter(fn (User $u) => ! $u->isAdmin() && $modes[$u->id] === self::MODE_BRANCHES)->pluck('id')->all();
        if ($branchModeIds !== []) {
            foreach (array_chunk($branchModeIds, 500) as $chunk) {
                DB::table('user_branch_scopes')->whereIn('user_id', $chunk)->orderBy('branch_id')->get(['user_id', 'branch_id'])
                    ->each(function ($r) use (&$stored) {
                        $stored[(int) $r->user_id][] = (int) $r->branch_id;
                    });
            }
        }

        // Colaboradores previos a la Fase 6.4: su sucursal vigente, resuelta en una sola pasada.
        $legacy = $pending->filter(fn (User $u) => ! $u->isAdmin() && $modes[$u->id] === null && ! $u->isGerencial() && $u->employee_id);
        $ownBranch = [];
        $employeeIds = $legacy->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        if ($employeeIds !== []) {
            foreach (($resolver ?? app(OkrEmployeeBranchResolver::class))->membershipMap($employeeIds) as $employeeId => $membership) {
                $ownBranch[(int) $employeeId] = $membership['branch_id'] ?? null;
            }
        }

        foreach ($pending as $u) {
            $mode = $modes[$u->id];
            $this->memo[$u->id] = match (true) {
                $u->isAdmin(), $mode === self::MODE_ALL => null,
                $mode === self::MODE_BRANCHES => $stored[$u->id] ?? [],
                $u->isGerencial() => null,
                (bool) $u->employee_id => ($own = $ownBranch[(int) $u->employee_id] ?? null) ? [(int) $own] : [],
                default => [],
            };
        }
    }

    /**
     * Calcula (sin memo) las sucursales permitidas aplicando las reglas de la clase:
     * admin o 'all' = null; 'branches' = las guardadas; NULL heredado =
     * gerencial → null, colaborador → sucursal vigente de su colaborador
     * vinculado (OkrEmployeeBranchResolver) o ninguna.
     *
     * @param  User  $user  Usuario.
     * @return list<int>|null
     */
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

    /**
     * Modo de alcance guardado en `users.branch_scope`.
     *
     * @param  User  $user  Usuario.
     * @return string|null MODE_ALL, MODE_BRANCHES o null (heredado / valor desconocido / esquema sin migrar).
     */
    public function modeOf(User $user): ?string
    {
        if (! $this->schemaReady()) {
            return null;
        }
        $mode = $user->getAttribute('branch_scope');

        return in_array($mode, [self::MODE_ALL, self::MODE_BRANCHES], true) ? $mode : null;
    }

    /**
     * Sucursales guardadas en `user_branch_scopes` para el usuario (consulta BD).
     *
     * @param  User  $user  Usuario.
     * @return list<int> Ids ordenados; [] si el esquema no está migrado.
     */
    public function storedBranchIds(User $user): array
    {
        if (! $this->schemaReady()) {
            return [];
        }

        return DB::table('user_branch_scopes')->where('user_id', $user->id)
            ->orderBy('branch_id')->pluck('branch_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * ¿La sucursal está dentro del alcance? Con alcance general siempre true;
     * restringido y branchId null = false.
     *
     * @param  User  $user  Usuario.
     * @param  int|null  $branchId  Sucursal consultada.
     */
    public function allows(User $user, ?int $branchId): bool
    {
        $allowed = $this->allowedBranchIds($user);

        return $allowed === null || ($branchId !== null && in_array((int) $branchId, $allowed, true));
    }

    /**
     * 403 con mensaje claro si la sucursal no está en el alcance del usuario.
     *
     * branchId null no se valida (pasa). Consulta el nombre de la sucursal para el mensaje.
     *
     * @param  User  $user  Usuario.
     * @param  int|null  $branchId  Sucursal solicitada.
     *
     * @throws HttpException 403
     */
    public function authorizeBranch(User $user, ?int $branchId): void
    {
        if ($branchId === null || $this->allows($user, $branchId)) {
            return;
        }

        $name = Branch::query()->whereKey($branchId)->value('name') ?? 'la sucursal solicitada';

        abort(403, "No tienes acceso a la información de {$name}. Tu alcance autorizado: {$this->label($user)}. "
            .'Si necesitas consultarla, pide a un administrador que amplíe tu alcance en Usuarios.');
    }

    /**
     * Vistas/exportes que agregan TODAS las sucursales: solo alcance general.
     *
     * @param  User  $user  Usuario.
     * @param  string  $what  Sujeto del mensaje de error (p. ej. "El consolidado").
     *
     * @throws HttpException 403 si el alcance es restringido.
     */
    public function requireGeneral(User $user, string $what = 'Este reporte'): void
    {
        if ($this->isGeneral($user)) {
            return;
        }

        abort(403, "{$what} incluye todas las sucursales y solo está disponible para usuarios con alcance general. "
            ."Consulta la información de tus sucursales ({$this->label($user)}) o pide a un administrador que amplíe tu alcance.");
    }

    /**
     * Sucursal efectiva para pantallas con selector opcional (Dashboard): general
     * sin selección → null (todas); restringido sin selección → su primera
     * sucursal; una sucursal fuera del alcance → 403.
     *
     * @param  User  $user  Usuario.
     * @param  int|null  $requested  Sucursal elegida en el selector (null/0 = sin selección).
     * @return int|null Sucursal efectiva o null = todas.
     *
     * @throws HttpException 403 si está fuera de alcance o no tiene sucursales.
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

    /**
     * Sucursal por defecto de un usuario restringido: la primera operativa de su
     * alcance en orden alfabético; si ninguna es operativa, la primera de su lista.
     *
     * @param  User  $user  Usuario.
     * @return int|null null si tiene alcance general o ninguna sucursal.
     */
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
     *
     * @param  User  $user  Usuario.
     * @param  iterable<T>  $options  Opciones del selector.
     * @param  string  $key  Llave/propiedad que contiene el id de sucursal.
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

    /**
     * Nombres de las sucursales del alcance (para filtrar datos que se guardan por nombre).
     *
     * @param  User  $user  Usuario.
     * @return list<string>|null null = todas. Nombres en mayúsculas tal como están en branches.name.
     */
    public function allowedBranchNames(User $user): ?array
    {
        $allowed = $this->allowedBranchIds($user);
        if ($allowed === null) {
            return null;
        }

        // Memo por conjunto de sucursales: la tabla de Usuarios repite los mismos
        // conjuntos en muchas filas (una consulta por conjunto distinto, no por fila).
        $key = implode(',', $allowed);

        return $this->namesMemo[$key] ??= Branch::query()->whereIn('id', $allowed)->orderBy('name')->pluck('name')->all();
    }

    /**
     * ¿El nombre de sucursal está en el alcance? Compara sin distinguir
     * mayúsculas y sin espacios extremos; nombre vacío = false si es restringido.
     *
     * @param  User  $user  Usuario.
     * @param  string|null  $name  Nombre de sucursal.
     */
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
     *
     * Consulta period_employee_rosters y employee_branch_assignments.
     *
     * @param  int  $employeeId  Colaborador.
     * @param  int|null  $periodId  Periodo (null = solo sucursal vigente).
     * @return int|null Sucursal o null si no se puede determinar.
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

    /**
     * ¿Puede ver datos del colaborador? Sí con alcance general, si es su propio
     * colaborador vinculado, o si la sucursal del colaborador (employeeBranchId())
     * está en su alcance.
     *
     * @param  User  $user  Usuario.
     * @param  int  $employeeId  Colaborador.
     * @param  int|null  $periodId  Periodo para resolver la sucursal.
     */
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

    /**
     * Igual que allowsEmployee() pero aborta con 403 y mensaje en español.
     *
     * @param  User  $user  Usuario.
     * @param  int  $employeeId  Colaborador.
     * @param  int|null  $periodId  Periodo para resolver la sucursal.
     *
     * @throws HttpException 403
     */
    public function authorizeEmployee(User $user, int $employeeId, ?int $periodId = null): void
    {
        if ($this->allowsEmployee($user, $employeeId, $periodId)) {
            return;
        }

        abort(403, "Ese colaborador no pertenece a tus sucursales autorizadas ({$this->label($user)}). "
            .'Si necesitas consultarlo, pide a un administrador que amplíe tu alcance en Usuarios.');
    }

    /**
     * Texto legible del alcance: "Todas las sucursales" o la lista de nombres.
     *
     * Para colaboradores heredados (sin branch_scope) muestra "Solo lo propio".
     *
     * @param  User  $user  Usuario.
     */
    public function label(User $user): string
    {
        $names = $this->allowedBranchNames($user);
        if ($names === null) {
            return 'Todas las sucursales';
        }

        // Colaborador previo a la Fase 6.4: «solo lo propio» (su sucursal vigente, si tiene).
        if (! $user->isAdmin() && $this->modeOf($user) === null && ! $user->isGerencial()) {
            return $names === [] ? 'Solo lo propio' : 'Solo lo propio ('.implode(', ', $names).')';
        }

        return $names === [] ? 'ninguna sucursal asignada' : implode(', ', $names);
    }

    /**
     * Resumen para la UI (tabla de Usuarios, props compartidas).
     *
     * @param  User  $user  Usuario.
     * @return array{mode:string, general:bool, branch_ids:list<int>, label:string} mode = admin | all | branches | legacy.
     */
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

    /**
     * Catálogo de sucursales asignables (las 13 operativas).
     *
     * @return list<array{id:int,name:string}>
     */
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
     * @param  User  $actor  Usuario que concede.
     * @param  string  $mode  MODE_ALL o MODE_BRANCHES.
     * @param  list<int>  $branchIds  Sucursales a conceder.
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

    /**
     * ¿El alcance del objetivo cabe dentro del del actor? (para administrarlo sin escalar).
     *
     * Un objetivo con alcance general solo cabe en un actor con alcance general.
     *
     * @param  User  $actor  Usuario que administra.
     * @param  User  $target  Usuario administrado.
     */
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
     * Valida que las sucursales sean operativas (422) y que el actor pueda
     * concederlas (403). En transacción: actualiza users.branch_scope y
     * reemplaza las filas de user_branch_scopes (con granted_by). Limpia el
     * memo del usuario objetivo.
     *
     * @param  User  $target  Usuario cuyo alcance se cambia.
     * @param  string  $mode  MODE_ALL o MODE_BRANCHES.
     * @param  list<int>  $branchIds  Sucursales (solo se guardan con MODE_BRANCHES).
     * @param  User  $actor  Usuario que hace el cambio.
     * @return string Texto legible del nuevo alcance.
     *
     * @throws HttpException 422/403
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

    /** Resultado memorizado (por proceso) de la verificación de esquema. */
    private static ?bool $schemaReady = null;

    /**
     * ¿Existen la tabla user_branch_scopes y la columna users.branch_scope?
     * Se verifica una sola vez por proceso.
     */
    private function schemaReady(): bool
    {
        return self::$schemaReady ??= Schema::hasTable('user_branch_scopes') && Schema::hasColumn('users', 'branch_scope');
    }
}
