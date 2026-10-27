<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo institucional de las 11 sucursales operativas de OKR
 * (App\Services\Catalog\BranchRouteCatalog::OKR_BRANCHES). Es el ancla estable
 * de atribución para el contrato de la API: `codigo` es fijo y único; un branch
 * apunta aquí vía branches.sucursal_id (materializado por SucursalSeeder desde
 * el catálogo, nunca por parecido en tiempo de ejecución).
 *
 * `activa` es la bandera operativa de la propia sucursal. El estado operativo
 * DERIVADO (que además considera si la ruta del catálogo está inactiva) vive en
 * Branch::derivedActive(); aquí no se inventa un enum de estado nuevo.
 *
 * @property int $id
 * @property string $codigo
 * @property string $nombre
 * @property string|null $region
 * @property bool $activa
 */
class Sucursal extends Model
{
    protected $table = 'sucursales';

    protected $fillable = [
        'codigo',
        'nombre',
        'region',
        'activa',
    ];

    protected $casts = [
        'activa' => 'boolean',
    ];

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }
}
