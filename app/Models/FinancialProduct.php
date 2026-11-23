<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo de productos financieros (tipos de crédito que coloca la empresa).
 *
 * Tabla: `financial_products`. Cada fila es un producto (ej. CRECE, CREDITO DIARIO).
 *
 * Columnas clave:
 * - `code`: código del producto.
 * - `name`: nombre tal como se muestra.
 * - `normalized_name`: nombre normalizado para emparejar contra las fuentes.
 * - `is_active` (bool): si el producto sigue vigente.
 */
class FinancialProduct extends Model
{
    protected $fillable = [
        'code',
        'name',
        'normalized_name',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /** Colocaciones (créditos otorgados) de este producto. */
    public function placements(): HasMany
    {
        return $this->hasMany(Placement::class);
    }
}
