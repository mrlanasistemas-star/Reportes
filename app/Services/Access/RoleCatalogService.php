<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Catálogo de roles (16-oct-2026) — los 3 del sistema + los que crea el
 * administrador. Fuente única para validar `users.role`, para saber qué roles
 * se configuran en ModuleAccessService/ActionPermissionService y para resolver
 * el NIVEL BASE de un rol personalizado (User::isGerencial()).
 */
class RoleCatalogService
{
    public const LEVELS = [User::ROLE_GERENCIAL, User::ROLE_COLABORADOR];

    private const CACHE_KEY = 'roles_catalog.v1';

    private const FALLBACK = [
        ['key' => User::ROLE_ADMIN, 'name' => 'Administrador', 'description' => 'Acceso total al sistema. No se configura.', 'base_level' => User::ROLE_ADMIN, 'is_system' => true],
        ['key' => User::ROLE_GERENCIAL, 'name' => 'Gerencial', 'description' => 'Gestiona módulos operativos y los OKR de su equipo.', 'base_level' => User::ROLE_GERENCIAL, 'is_system' => true],
        ['key' => User::ROLE_COLABORADOR, 'name' => 'Colaborador', 'description' => 'Consulta y da seguimiento a sus propios OKR.', 'base_level' => User::ROLE_COLABORADOR, 'is_system' => true],
    ];

    /** @var array<int, array{key:string,name:string,description:?string,base_level:string,is_system:bool}>|null */
    private ?array $memo = null;

    /** @return array<int, array{key:string,name:string,description:?string,base_level:string,is_system:bool}> */
    public function all(): array
    {
        if ($this->memo !== null) {
            return $this->memo;
        }

        try {
            $rows = Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('roles')
                ->orderByDesc('is_system')->orderBy('id')
                ->get(['key', 'name', 'description', 'base_level', 'is_system'])
                ->map(fn ($r) => ['key' => $r->key, 'name' => $r->name, 'description' => $r->description, 'base_level' => $r->base_level, 'is_system' => (bool) $r->is_system])
                ->all());
        } catch (\Throwable) {
            $rows = []; // tabla aún sin migrar
        }

        return $this->memo = $rows !== [] ? $rows : self::FALLBACK;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_column($this->all(), 'key');
    }

    /** Roles cuyos módulos/acciones se configuran (todos menos admin). @return list<string> */
    public function configurableKeys(): array
    {
        return array_values(array_filter($this->keys(), fn ($k) => $k !== User::ROLE_ADMIN));
    }

    public function baseLevel(?string $key): ?string
    {
        foreach ($this->all() as $role) {
            if ($role['key'] === $key) {
                return $role['base_level'];
            }
        }

        return null;
    }

    public function label(?string $key): string
    {
        foreach ($this->all() as $role) {
            if ($role['key'] === $key) {
                return $role['name'];
            }
        }

        return (string) $key;
    }

    /** Clave única a partir del nombre ("Supervisor de zona" → supervisor_de_zona). */
    public function makeKey(string $name): string
    {
        $base = Str::of($name)->ascii()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->limit(32, '')->value() ?: 'rol';
        $key = $base;
        $i = 2;
        while (in_array($key, $this->keys(), true) || DB::table('roles')->where('key', $key)->exists()) {
            $key = $base . '_' . $i++;
        }

        return $key;
    }

    public function forget(): void
    {
        $this->memo = null;
        Cache::forget(self::CACHE_KEY);
    }
}
