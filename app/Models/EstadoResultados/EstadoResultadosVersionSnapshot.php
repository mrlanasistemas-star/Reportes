<?php

namespace App\Models\EstadoResultados;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dataset canónico congelado de un alcance dentro de una versión aprobada. Inmutable. */
class EstadoResultadosVersionSnapshot extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'estado_resultados_version_snapshots';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('El snapshot de una versión aprobada es inmutable.');
        });
        static::deleting(function () {
            throw new \LogicException('El snapshot de una versión aprobada no se puede eliminar.');
        });
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(EstadoResultadosVersion::class, 'version_id');
    }

    /** Dataset decodificado; verifica que el JSON guardado no haya sido alterado. */
    public function datasetVerified(): array
    {
        if (! hash_equals($this->payload_hash, hash('sha256', $this->dataset))) {
            throw new \RuntimeException('El snapshot aprobado (versión '.$this->version_id.', '.$this->scope_key.') no supera la verificación de integridad.');
        }

        return json_decode($this->dataset, true, 512, JSON_THROW_ON_ERROR);
    }
}
