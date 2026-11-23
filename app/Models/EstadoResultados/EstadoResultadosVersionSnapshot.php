<?php

namespace App\Models\EstadoResultados;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dataset canónico congelado de un alcance dentro de una versión aprobada. Inmutable.
 *
 * Tabla `estado_resultados_version_snapshots` (única por version_id + scope_key). Columnas:
 * `scope_key`/`scope_label` (alcance: consolidado o sucursal), `dataset` (JSON completo del
 * reporte tal como se aprobó), `dataset_hash` (hash de cifras) y `payload_hash` (sha256 del JSON
 * guardado, para verificar integridad al leer).
 */
class EstadoResultadosVersionSnapshot extends Model
{
    /** Sin columna updated_at: el snapshot nunca se modifica. */
    public const UPDATED_AT = null;

    protected $table = 'estado_resultados_version_snapshots';

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    /**
     * Candados de inmutabilidad: cualquier update o delete vía Eloquent lanza \LogicException.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('El snapshot de una versión aprobada es inmutable.');
        });
        static::deleting(function () {
            throw new \LogicException('El snapshot de una versión aprobada no se puede eliminar.');
        });
    }

    /**
     * Versión aprobada a la que pertenece el snapshot.
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(EstadoResultadosVersion::class, 'version_id');
    }

    /**
     * Dataset decodificado; verifica que el JSON guardado no haya sido alterado.
     *
     * @return array Dataset del reporte aprobado.
     *
     * @throws \RuntimeException Si el sha256 del JSON no coincide con `payload_hash`.
     * @throws \JsonException Si el JSON guardado no es válido.
     */
    public function datasetVerified(): array
    {
        if (! hash_equals($this->payload_hash, hash('sha256', $this->dataset))) {
            throw new \RuntimeException('El snapshot aprobado (versión '.$this->version_id.', '.$this->scope_key.') no supera la verificación de integridad.');
        }

        return json_decode($this->dataset, true, 512, JSON_THROW_ON_ERROR);
    }
}
