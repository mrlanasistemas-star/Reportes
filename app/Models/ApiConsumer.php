<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * Fase 1 — identidad de un SISTEMA consumidor de la API (Auditoría Operativa,
 * futuros sistemas). Nunca un usuario humano: los tokens Sanctum de un
 * consumidor viven aquí, separados de `users` a propósito.
 */
class ApiConsumer extends Model
{
    use HasApiTokens;

    protected $fillable = [
        'name',
        'system_code',
        'description',
        'abilities',
        'allowed_branch_ids',
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

    /** null = sin restricción de sucursal (acceso general permitido). */
    public function isRestrictedToBranches(): bool
    {
        return is_array($this->allowed_branch_ids) && count($this->allowed_branch_ids) > 0;
    }

    public function canAccessBranch(?int $branchId): bool
    {
        if (!$this->isRestrictedToBranches()) {
            return true;
        }

        return $branchId !== null && in_array($branchId, $this->allowed_branch_ids, true);
    }

    public function canAccessGeneralScope(): bool
    {
        return !$this->isRestrictedToBranches();
    }
}
