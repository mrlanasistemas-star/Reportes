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
 */
class ApiConsumer extends Model
{
    use HasApiTokens;

    public const BRANCH_ACCESS_GLOBAL = 'global';

    public const BRANCH_ACCESS_RESTRICTED = 'restricted';

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

    public function isGlobalAccess(): bool
    {
        return $this->branch_access_mode === self::BRANCH_ACCESS_GLOBAL;
    }

    public function hasNoBranchAccess(): bool
    {
        if ($this->branch_access_mode === self::BRANCH_ACCESS_NONE) {
            return true;
        }

        // Defensa en profundidad: 'restricted' con lista vacía/corrupta NUNCA se
        // interpreta como global — se trata como si fuera 'none'.
        return $this->branch_access_mode === self::BRANCH_ACCESS_RESTRICTED
            && (!is_array($this->allowed_branch_ids) || count($this->allowed_branch_ids) === 0);
    }

    public function isRestrictedToBranches(): bool
    {
        return $this->branch_access_mode === self::BRANCH_ACCESS_RESTRICTED && !$this->hasNoBranchAccess();
    }

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

    public function canAccessGeneralScope(): bool
    {
        return $this->isGlobalAccess();
    }

    /** Fase 2.9 — techo real de privilegios: un token nunca puede pedir más de lo que el consumidor tiene. */
    public function allowedAbilities(): array
    {
        return is_array($this->abilities) ? $this->abilities : [];
    }

    public function hasAbility(string $ability): bool
    {
        return in_array($ability, $this->allowedAbilities(), true);
    }
}
