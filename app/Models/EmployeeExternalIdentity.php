<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 1.4 — correspondencia explícita de un Employee con un identificador de
 * sistema externo (futuro MR Lana People, Auditoría, etc.). El vínculo nunca
 * se crea por coincidencia automática de nombre — ver
 * App\Services\Api\EmployeeExternalIdentityService::link().
 */
class EmployeeExternalIdentity extends Model
{
    protected $fillable = [
        'employee_id',
        'system_code',
        'external_id',
        'external_type',
        'verified_at',
        'verification_method',
        'active',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
        'active' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
