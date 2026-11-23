<?php

namespace App\Models\EstadoResultados;

use App\Models\Period;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

/**
 * Versión APROBADA (cierre) del Estado de Resultados de un periodo. Las cifras,
 * insumos y hashes son inmutables: sólo cambian los campos de ciclo de vida
 * (nueva versión abierta / sustituida).
 *
 * Tabla `estado_resultados_versions` (única por period_id + version). Columnas clave:
 *  - `version`: consecutivo por periodo; `status`: aprobado | sustituido.
 *  - `current_period_id`: igual a period_id sólo en la versión vigente (índice único → a lo más
 *    una versión vigente por periodo); null en las sustituidas.
 *  - `rules_version`, `criterion`: reglas y criterio con que se aprobó.
 *  - `dataset_hash` (cifras del CONSOLIDADO), `snapshot_hash` (todos los snapshots + insumos),
 *    `input_signature` (firma de insumos).
 *  - `scopes`, `sources` (archivos + sha256, firma ERP, corrida), `classifications` (bitácora
 *    completa), `adjustments` (ajustes vigentes), `evaluation` (validaciones al aprobar).
 *  - Aprobación (`approved_by`, `approved_at`, `approval_notes`), reapertura (`reopened_at`,
 *    `reopened_by`, `reopen_reason`) y sustitución (`superseded_at`, `superseded_by_version_id`).
 */
class EstadoResultadosVersion extends Model
{
    /** Versión aprobada y vigente (o reabierta si tiene `reopened_at`). */
    public const APROBADO = 'aprobado';

    /** Versión reemplazada por una aprobación posterior. */
    public const SUSTITUIDO = 'sustituido';

    /** Columnas que pueden cambiar después de aprobar (todo lo demás es inmutable). */
    private const MUTABLE = ['status', 'current_period_id', 'reopened_at', 'reopened_by', 'reopen_reason', 'superseded_at', 'superseded_by_version_id', 'updated_at'];

    protected $table = 'estado_resultados_versions';

    protected $guarded = ['id'];

    protected $casts = [
        'scopes' => 'array',
        'sources' => 'array',
        'classifications' => 'array',
        'adjustments' => 'array',
        'evaluation' => 'array',
        'approved_at' => 'datetime',
        'reopened_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    /**
     * Candados de inmutabilidad a nivel modelo.
     *
     * - updating: si se intenta cambiar cualquier columna fuera de MUTABLE lanza \LogicException.
     * - deleting: siempre lanza \LogicException (una versión aprobada no se elimina por Eloquent).
     */
    protected static function booted(): void
    {
        static::updating(function (self $v) {
            $illegal = array_diff(array_keys($v->getDirty()), self::MUTABLE);
            if ($illegal !== []) {
                throw new \LogicException('Una versión aprobada del Estado de Resultados es inmutable ('.implode(', ', $illegal).').');
            }
        });
        static::deleting(function () {
            throw new \LogicException('Una versión aprobada del Estado de Resultados no se puede eliminar.');
        });
    }

    /** Caché en memoria (por proceso) del resultado de hasGuaranteeColumns(). */
    private static ?bool $guarantees = null;

    /**
     * ¿Ya se aplicó la migración 2026_11_06_0000C1 (current_period_id / input_signature)?
     *
     * Permite que el código funcione antes y después de la migración. Consulta el esquema;
     * fuera de pruebas guarda el resultado en memoria estática para no repetir la consulta
     * (en pruebas se re-evalúa cada vez porque el esquema cambia entre tests).
     */
    public static function hasGuaranteeColumns(): bool
    {
        if (app()->runningUnitTests()) {
            return Schema::hasColumn((new self)->getTable(), 'current_period_id');
        }

        return self::$guarantees ??= Schema::hasColumn((new self)->getTable(), 'current_period_id');
    }

    /** Vigente y cerrada: el periodo no admite cambios. */
    public function isLocked(): bool
    {
        return $this->status === self::APROBADO && $this->reopened_at === null;
    }

    /**
     * Periodo aprobado.
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /**
     * Usuario que aprobó la versión (columna `approved_by`).
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Usuario que reabrió el periodo para una nueva versión (columna `reopened_by`).
     */
    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    /**
     * Datasets congelados por alcance (consolidado y sucursales) de esta versión.
     */
    public function snapshots(): HasMany
    {
        return $this->hasMany(EstadoResultadosVersionSnapshot::class, 'version_id');
    }
}
