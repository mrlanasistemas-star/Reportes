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
 */
class EstadoResultadosVersion extends Model
{
    public const APROBADO = 'aprobado';

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

    private static ?bool $guarantees = null;

    /** ¿Ya se aplicó la migración 2026_11_06_0000C1 (current_period_id / input_signature)? */
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

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(EstadoResultadosVersionSnapshot::class, 'version_id');
    }
}
