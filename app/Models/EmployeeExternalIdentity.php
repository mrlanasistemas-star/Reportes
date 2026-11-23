<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 1.4 — correspondencia explícita de un Employee con un identificador de
 * sistema externo (futuro MR Lana People, Auditoría, etc.). El vínculo nunca
 * se crea por coincidencia automática de nombre — ver
 * App\Services\Api\EmployeeExternalIdentityService::link().
 *
 * Tabla: `employee_external_identities`. Cada fila vincula un colaborador con su
 * ID en otro sistema.
 *
 * Columnas clave:
 * - `system_code`: código del sistema externo.
 * - `external_id`/`external_type`: identificador y tipo de entidad en ese sistema.
 * - `verified_at` (datetime) / `verification_method`: cuándo y cómo se verificó el vínculo.
 * - `active` (bool): si el vínculo sigue vigente.
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

    /** Colaborador interno al que corresponde la identidad externa. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
