<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo KPI del módulo OKR — ver docs/OKR.md. `code` es la llave técnica
 * estable (nunca el nombre visual). `provider_key` identifica la entrada en
 * OkrKpiProviderRegistry que resuelve el valor real desde Reportería.
 */
class OkrKpi extends Model
{
    public const TYPE_CUMULATIVE = 'cumulative';
    public const TYPE_BALANCE    = 'balance';
    public const TYPE_PERCENTAGE = 'percentage';

    public const DIRECTION_INCREASE = 'increase';
    public const DIRECTION_DECREASE = 'decrease';

    public const AUTOMATION_AUTOMATIC = 'automatic';
    public const AUTOMATION_MANUAL    = 'manual';
    public const AUTOMATION_HYBRID    = 'hybrid';

    protected $fillable = [
        'code', 'name', 'description', 'unit', 'type', 'direction',
        'automation', 'provider_key', 'scopes', 'is_active', 'config',
    ];

    protected $casts = [
        'scopes'    => 'array',
        'config'    => 'array',
        'is_active' => 'boolean',
    ];

    public function keyResults(): HasMany
    {
        return $this->hasMany(OkrKeyResult::class, 'kpi_id');
    }

    public function isAutomatic(): bool
    {
        return $this->automation === self::AUTOMATION_AUTOMATIC && $this->provider_key !== null;
    }

    public function supportsScope(string $scopeType): bool
    {
        if (empty($this->scopes)) {
            return true; // sin restricción explícita = aplica a los 3 alcances
        }

        return in_array($scopeType, $this->scopes, true);
    }
}
