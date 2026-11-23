<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * Fase 1 — identidad de un SISTEMA consumidor de la API (Auditoría Operativa,
 * futuros sistemas). Nunca un usuario humano: los tokens Sanctum de un
 * consumidor viven aquí, separados de `users` a propósito.
 *
 * Fase 2.5 (auditoría de seguridad 2026-10-11) — `branch_access_mode` es un
 * estado EXPLÍCITO de 3 valores. Antes de esto, `allowed_branch_ids = null` y
 * `allowed_branch_ids = []` eran indistinguibles (ambos daban acceso global) —
 * un bug real. Ahora ningún array vacío puede ampliar privilegios por
 * accidente: si `branch_access_mode = 'restricted'` pero el array queda vacío
 * por error de datos, se trata como 'none' (deniega todo), NUNCA como global.
 *
 * Tabla: `api_consumers`. Cada fila es un sistema externo autorizado.
 *
 * Columnas clave:
 * - `name`/`system_code`/`description`: identificación del sistema.
 * - `abilities` (array): habilidades (permisos) máximas que pueden tener sus tokens.
 * - `allowed_branch_ids` (array): sucursales permitidas cuando el modo es `restricted`.
 * - `branch_access_mode`: una de BRANCH_ACCESS_MODES.
 * - `is_active` (bool): si puede usar la API.
 * - `last_used_at` (datetime): último uso.
 */
class ApiConsumer extends Model
{
    use HasApiTokens;

    /** Acceso a todas las sucursales y al alcance general. */
    public const BRANCH_ACCESS_GLOBAL = 'global';

    /** Acceso solo a las sucursales de `allowed_branch_ids`. */
    public const BRANCH_ACCESS_RESTRICTED = 'restricted';

    /** Sin acceso a ninguna sucursal. */
    public const BRANCH_ACCESS_NONE = 'none';

    public const BRANCH_ACCESS_MODES = [self::BRANCH_ACCESS_GLOBAL, self::BRANCH_ACCESS_RESTRICTED, self::BRANCH_ACCESS_NONE];

    protected $fillable = [
        'name',
        'system_code',
        'description',
        'abilities',
        'allowed_branch_ids',
        'branch_access_mode',
        'is_active',
        'last_used_at',
    ];

    protected $casts = [
        'abilities' => 'array',
        'allowed_branch_ids' => 'array',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    protected $hidden = [];

    /**
     * Indica si el consumidor tiene acceso global a sucursales.
     *
     * @return bool true si `branch_access_mode` es `global`.
     */
    public function isGlobalAccess(): bool
    {
        return $this->branch_access_mode === self::BRANCH_ACCESS_GLOBAL;
    }

    /**
     * Indica si el consumidor NO tiene acceso a ninguna sucursal: modo `none`, o
     * modo `restricted` con lista vacía o inválida (defensa en profundidad).
     *
     * @return bool true si se le debe negar cualquier sucursal.
     */
    public function hasNoBranchAccess(): bool
    {
        if ($this->branch_access_mode === self::BRANCH_ACCESS_NONE) {
            return true;
        }

        // Defensa en profundidad: 'restricted' con lista vacía/corrupta NUNCA se
        // interpreta como global — se trata como si fuera 'none'.
        return $this->branch_access_mode === self::BRANCH_ACCESS_RESTRICTED
            && (! is_array($this->allowed_branch_ids) || count($this->allowed_branch_ids) === 0);
    }

    /**
     * Indica si el consumidor está limitado a una lista válida (no vacía) de sucursales.
     *
     * @return bool true si es `restricted` con al menos una sucursal permitida.
     */
    public function isRestrictedToBranches(): bool
    {
        return $this->branch_access_mode === self::BRANCH_ACCESS_RESTRICTED && ! $this->hasNoBranchAccess();
    }

    /**
     * Indica si el consumidor puede consultar datos de una sucursal.
     * Nota: la comparación con `allowed_branch_ids` es estricta (int).
     *
     * @param  int|null  $branchId  Sucursal solicitada; null solo pasa con acceso global.
     * @return bool true si tiene permiso sobre esa sucursal.
     */
    public function canAccessBranch(?int $branchId): bool
    {
        if ($this->hasNoBranchAccess()) {
            return false;
        }

        if ($this->isGlobalAccess()) {
            return true;
        }

        return $branchId !== null && in_array($branchId, $this->allowed_branch_ids, true);
    }

    /**
     * Indica si puede consultar el alcance general (datos de toda la empresa).
     * Solo los consumidores con acceso global.
     *
     * @return bool true si tiene acceso global.
     */
    public function canAccessGeneralScope(): bool
    {
        return $this->isGlobalAccess();
    }

    /**
     * Fase 2.9 — techo real de privilegios: un token nunca puede pedir más de lo que el consumidor tiene.
     *
     * @return array<int, string> Habilidades permitidas (vacío si `abilities` no es arreglo).
     */
    public function allowedAbilities(): array
    {
        return is_array($this->abilities) ? $this->abilities : [];
    }

    /**
     * Indica si el consumidor tiene una habilidad concreta dentro de su techo de privilegios.
     *
     * @param  string  $ability  Habilidad a verificar.
     * @return bool true si está en allowedAbilities().
     */
    public function hasAbility(string $ability): bool
    {
        return in_array($ability, $this->allowedAbilities(), true);
    }
}
