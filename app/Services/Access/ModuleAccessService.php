<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Módulos que puede ver cada rol (14-oct-2026) — fuente única para el
 * middleware EnsureModuleAccess, el menú (prop compartida auth.modules) y el
 * aterrizaje después del login. El admin siempre ve todo y no se configura;
 * gerencial y colaborador se editan en "Usuarios y accesos".
 *
 * Persiste en la tabla `role_module_access` (rol, módulo) con caché permanente
 * (CACHE_KEY) que se invalida al guardar. Además recorta los módulos de alcance
 * general a usuarios restringidos por sucursal (GENERAL_SCOPE_MODULES).
 */
class ModuleAccessService
{
    /** Orden = orden del menú y prioridad para elegir la página de inicio. */
    public const MODULES = [
        'dashboard' => ['label' => 'Dashboard', 'url' => '/dashboard', 'description' => 'Resumen del periodo más reciente.'],
        'carga' => ['label' => 'Carga de archivos', 'url' => '/historico-general', 'description' => 'Subir fuentes, incidencias y generar radiografía.'],
        'periodos' => ['label' => 'Periodos', 'url' => '/periodos', 'description' => 'Crear, abrir y cerrar periodos.'],
        'colaboradores' => ['label' => 'Colaboradores', 'url' => '/asignaciones-empleado-sucursal', 'description' => 'Sucursal por periodo, altas y bajas.'],
        'reportes' => ['label' => 'Reportes mensuales', 'url' => '/reportes-mensuales', 'description' => 'Radiografía, comparativo, validaciones y exportes.'],
        'efectividad' => ['label' => 'Efectividad de Cobranza', 'url' => '/efectividad-cobranza', 'description' => 'Calculadora de efectividad por ruta.'],
        // 05-nov-2026 — Estado de Resultados gerencial (3 EBITDA). Información consolidada
        // de toda la empresa (Corporativo/DG): sólo el Administrador hasta que se asigne en Roles.
        'estado_resultados' => ['label' => 'Estado de Resultados', 'url' => '/estado-resultados', 'description' => 'Ingresos, OPEX, EBITDA y utilidad neta del mes; conciliación de bancos y exportes.'],
        'okr' => ['label' => 'OKR', 'url' => '/okr', 'description' => 'Objetivos, check-ins y seguimiento.'],
        // Administración (prioridad 8) — por defecto solo el Administrador; se
        // pueden delegar, pero nadie puede otorgar más de lo que él mismo tiene
        // (ver AccessDelegationGuard).
        'usuarios' => ['label' => 'Usuarios', 'url' => '/usuarios', 'description' => 'Altas, accesos, invitaciones y contraseñas.'],
        'roles' => ['label' => 'Roles y permisos', 'url' => '/roles', 'description' => 'Roles, módulos y acciones permitidas.'],
    ];

    /** Roles configurables de respaldo si el catálogo de roles no devuelve ninguno. */
    public const CONFIGURABLE_ROLES = [User::ROLE_GERENCIAL, User::ROLE_COLABORADOR];

    /** Matriz usada cuando la tabla `role_module_access` aún no existe. */
    private const DEFAULTS = [
        User::ROLE_GERENCIAL => ['dashboard', 'carga', 'periodos', 'colaboradores', 'reportes', 'efectividad', 'okr'],
        User::ROLE_COLABORADOR => ['okr'],
    ];

    /** Clave de caché de la matriz rol → módulos. */
    private const CACHE_KEY = 'role_module_access.v1';

    /**
     * Módulos que operan sobre TODAS las sucursales a la vez (cargar fuentes de
     * la empresa, incidencias/colaboradores de todo el periodo, generar la
     * radiografía general, abrir/cerrar/eliminar periodos). Un usuario con
     * alcance RESTRINGIDO por sucursal (DataScopeService) no los recibe aunque
     * su rol los tenga: si no, vería y modificaría datos de sucursales ajenas.
     */
    public const GENERAL_SCOPE_MODULES = ['carga', 'periodos', 'estado_resultados'];

    /**
     * Matriz rol → módulos permitidos según lo guardado en BD (con caché).
     *
     * Solo incluye roles configurables y módulos existentes en MODULES (en el
     * orden del menú). Si la tabla no existe devuelve DEFAULTS.
     *
     * @return array<string, list<string>> rol => módulos permitidos (solo roles configurables).
     */
    public function matrix(): array
    {
        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('role_module_access')
                ->get(['role', 'module'])
                ->groupBy('role')
                ->map(fn ($rows) => $rows->pluck('module')->all())
                ->all());
        } catch (\Throwable) {
            // Tabla aún sin migrar — comportamiento previo.
            return self::DEFAULTS;
        }

        $matrix = [];
        foreach ($this->configurableRoles() as $role) {
            $matrix[$role] = array_values(array_intersect(array_keys(self::MODULES), $stored[$role] ?? []));
        }

        return $matrix;
    }

    /**
     * 16-oct-2026 — roles del sistema + personalizados (todos menos admin).
     *
     * @return list<string>
     */
    private function configurableRoles(): array
    {
        return app(RoleCatalogService::class)->configurableKeys() ?: self::CONFIGURABLE_ROLES;
    }

    /**
     * Clave de permisos del usuario: su propio rol si es configurable; si no, su nivel base.
     *
     * @param  User  $user  Usuario no administrador.
     * @return string Clave de rol a buscar en la matriz.
     */
    private function permissionKeyFor(User $user): string
    {
        return in_array($user->role, $this->configurableRoles(), true)
            ? $user->role
            : ($user->isGerencial() ? User::ROLE_GERENCIAL : User::ROLE_COLABORADOR);
    }

    /**
     * Guarda los módulos de un solo rol (atajo de save()).
     *
     * @param  string  $role  Clave del rol.
     * @param  list<string>  $modules  Módulos permitidos.
     */
    public function saveRole(string $role, array $modules): void
    {
        $this->save([$role => $modules]);
    }

    /**
     * Borra todos los módulos asignados a un rol (al eliminar el rol) e invalida la caché.
     *
     * @param  string  $role  Clave del rol.
     */
    public function deleteRole(string $role): void
    {
        DB::table('role_module_access')->where('role', $role)->delete();
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Módulos efectivos del usuario (los que ve en menú y puede abrir).
     *
     * Regla: sin usuario = ninguno; admin = todos; resto = los de su rol menos
     * GENERAL_SCOPE_MODULES si su alcance por sucursal está restringido.
     *
     * @param  User|null  $user  Usuario autenticado.
     * @return list<string>
     */
    public function modulesFor(?User $user): array
    {
        if (! $user) {
            return [];
        }
        if ($user->isAdmin()) {
            return array_keys(self::MODULES);
        }

        $modules = $this->roleModulesFor($user);
        if (array_intersect($modules, self::GENERAL_SCOPE_MODULES) !== [] && app(DataScopeService::class)->isRestricted($user)) {
            $modules = array_values(array_diff($modules, self::GENERAL_SCOPE_MODULES));
        }

        return $modules;
    }

    /**
     * Módulos del ROL del usuario, antes de aplicar su alcance por sucursal.
     *
     * @param  User  $user  Usuario.
     * @return list<string>
     */
    public function roleModulesFor(User $user): array
    {
        return $user->isAdmin() ? array_keys(self::MODULES) : ($this->matrix()[$this->permissionKeyFor($user)] ?? []);
    }

    /**
     * ¿Su rol tiene el módulo, pero su alcance restringido por sucursal se lo impide?
     *
     * Sirve para mostrar un mensaje específico en lugar de un 403 genérico.
     *
     * @param  User|null  $user  Usuario autenticado.
     * @param  string  $module  Clave del módulo.
     */
    public function blockedByScope(?User $user, string $module): bool
    {
        return $user !== null
            && in_array($module, self::GENERAL_SCOPE_MODULES, true)
            && in_array($module, $this->roleModulesFor($user), true)
            && ! $this->allows($user, $module);
    }

    /**
     * ¿El usuario puede entrar al módulo? (incluye el recorte por alcance).
     *
     * @param  User|null  $user  Usuario autenticado.
     * @param  string  $module  Clave del módulo.
     */
    public function allows(?User $user, string $module): bool
    {
        return in_array($module, $this->modulesFor($user), true);
    }

    /**
     * Primer módulo permitido; si no tiene ninguno, su perfil (siempre accesible).
     *
     * Se usa como redirección después del login.
     *
     * @param  User|null  $user  Usuario autenticado.
     * @return string URL relativa de aterrizaje.
     */
    public function landingUrlFor(?User $user): string
    {
        $first = $this->modulesFor($user)[0] ?? null;

        return $first ? self::MODULES[$first]['url'] : '/settings/profile';
    }

    /**
     * Guarda la matriz rol → módulos en `role_module_access` dentro de una transacción.
     *
     * Por cada rol enviado y configurable: borra sus filas e inserta las nuevas
     * (descarta módulos desconocidos). Los roles no enviados no se tocan.
     * Invalida la caché al terminar.
     *
     * @param  array<string, list<string>>  $matrix  rol => módulos.
     */
    public function save(array $matrix): void
    {
        DB::transaction(function () use ($matrix) {
            $now = now();
            // Solo los roles enviados (y configurables) — guardar uno no borra los demás.
            foreach (array_intersect(array_keys($matrix), $this->configurableRoles()) as $role) {
                $modules = array_values(array_intersect(array_keys(self::MODULES), $matrix[$role] ?? []));
                DB::table('role_module_access')->where('role', $role)->delete();
                DB::table('role_module_access')->insert(array_map(
                    fn ($module) => ['role' => $role, 'module' => $module, 'created_at' => $now, 'updated_at' => $now],
                    $modules,
                ));
            }
        });

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Catálogo para la UI.
     *
     * @return list<array{key:string, label:string, description:string}>
     */
    public function catalog(): array
    {
        return collect(self::MODULES)
            ->map(fn ($m, $key) => ['key' => $key, 'label' => $m['label'], 'description' => $m['description']])
            ->values()
            ->all();
    }
}
