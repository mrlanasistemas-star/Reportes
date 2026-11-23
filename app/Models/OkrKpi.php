<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo KPI del módulo OKR — ver docs/MANUAL-DESARROLLADOR.md. `code` es la llave técnica
 * estable (nunca el nombre visual). `provider_key` identifica la entrada en
 * OkrKpiProviderRegistry que resuelve el valor real desde Reportería.
 *
 * Tabla: `okr_kpis`. Cada fila es un indicador medible que se puede usar como
 * base de un resultado clave.
 *
 * Columnas clave:
 * - `unit`: unidad de medida (pesos, %, conteo...).
 * - `type`: TYPE_* (acumulado, saldo o porcentaje).
 * - `direction`: DIRECTION_* (si mejorar es subir o bajar).
 * - `automation`: AUTOMATION_* (automático, manual o híbrido).
 * - `scopes` (array): alcances en los que aplica; vacío = todos.
 * - `config` (array): configuración adicional del KPI.
 * - `is_active` (bool): si está disponible para nuevos KR.
 */
class OkrKpi extends Model
{
    /** Valor que se acumula en el periodo (ej. colocación). */
    public const TYPE_CUMULATIVE = 'cumulative';

    /** Valor de saldo a una fecha (ej. cartera). */
    public const TYPE_BALANCE = 'balance';

    /** Valor porcentual (ej. mora %). */
    public const TYPE_PERCENTAGE = 'percentage';

    /** Mejorar significa que el valor suba. */
    public const DIRECTION_INCREASE = 'increase';

    /** Mejorar significa que el valor baje. */
    public const DIRECTION_DECREASE = 'decrease';

    /** El valor se obtiene automáticamente de Reportería. */
    public const AUTOMATION_AUTOMATIC = 'automatic';

    /** El valor se captura a mano. */
    public const AUTOMATION_MANUAL = 'manual';

    /** Combinación de valor automático y captura manual. */
    public const AUTOMATION_HYBRID = 'hybrid';

    protected $fillable = [
        'code', 'name', 'description', 'unit', 'type', 'direction',
        'automation', 'provider_key', 'scopes', 'is_active', 'config',
    ];

    protected $casts = [
        'scopes' => 'array',
        'config' => 'array',
        'is_active' => 'boolean',
    ];

    /** Resultados clave que usan este KPI. */
    public function keyResults(): HasMany
    {
        return $this->hasMany(OkrKeyResult::class, 'kpi_id');
    }

    /**
     * Indica si el KPI se calcula automáticamente: debe ser `automatic` y tener
     * un proveedor registrado (`provider_key`).
     *
     * @return bool true si el valor real se obtiene sin captura manual.
     */
    public function isAutomatic(): bool
    {
        return $this->automation === self::AUTOMATION_AUTOMATIC && $this->provider_key !== null;
    }

    /**
     * Indica si el KPI se puede usar en el alcance dado.
     *
     * @param  string  $scopeType  Tipo de alcance del objetivo (ej. general, sucursal, colaborador).
     * @return bool true si `scopes` está vacío o incluye el alcance.
     */
    public function supportsScope(string $scopeType): bool
    {
        if (empty($this->scopes)) {
            return true; // sin restricción explícita = aplica a los 3 alcances
        }

        return in_array($scopeType, $this->scopes, true);
    }
}
