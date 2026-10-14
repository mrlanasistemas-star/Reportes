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
        'okr' => ['label' => 'OKR', 'url' => '/okr', 'description' => 'Objetivos, check-ins y seguimiento.'],
    ];

    public const CONFIGURABLE_ROLES = [User::ROLE_GERENCIAL, User::ROLE_COLABORADOR];

    private const DEFAULTS = [
        User::ROLE_GERENCIAL => ['dashboard', 'carga', 'periodos', 'colaboradores', 'reportes', 'efectividad', 'okr'],
        User::ROLE_COLABORADOR => ['okr'],
    ];

    private const CACHE_KEY = 'role_module_access.v1';

    /** @return array<string, list<string>> rol => módulos permitidos (solo roles configurables). */
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
        foreach (self::CONFIGURABLE_ROLES as $role) {
            $matrix[$role] = array_values(array_intersect(array_keys(self::MODULES), $stored[$role] ?? []));
        }

        return $matrix;
    }

    /** @return list<string> */
    public function modulesFor(?User $user): array
    {
        if (! $user) {
            return [];
        }
        if ($user->isAdmin()) {
            return array_keys(self::MODULES);
        }

        return $this->matrix()[$user->isGerencial() ? User::ROLE_GERENCIAL : User::ROLE_COLABORADOR] ?? [];
    }

    public function allows(?User $user, string $module): bool
    {
        return in_array($module, $this->modulesFor($user), true);
    }

    /** Primer módulo permitido; si no tiene ninguno, su perfil (siempre accesible). */
    public function landingUrlFor(?User $user): string
    {
        $first = $this->modulesFor($user)[0] ?? null;

        return $first ? self::MODULES[$first]['url'] : '/settings/profile';
    }

    /** @param array<string, list<string>> $matrix */
    public function save(array $matrix): void
    {
        DB::transaction(function () use ($matrix) {
            $now = now();
            foreach (self::CONFIGURABLE_ROLES as $role) {
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

    /** Catálogo para la UI. */
    public function catalog(): array
    {
        return collect(self::MODULES)
            ->map(fn ($m, $key) => ['key' => $key, 'label' => $m['label'], 'description' => $m['description']])
            ->values()
            ->all();
    }
}
