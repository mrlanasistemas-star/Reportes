<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Archivo semanal de colocación subido para medir un objetivo OKR.
 *
 * Tabla: `okr_placement_uploads`. Cada fila es la carga de colocación de una
 * semana de un objetivo (OkrPlacementImportService). Solo una carga por
 * objetivo/semana está activa; al subir otra, la anterior queda `superseded`.
 *
 * Columnas clave:
 * - `week_number`/`week_start`/`week_end` (date): semana que cubre.
 * - `original_filename`/`stored_path`/`disk`/`uploaded_by`: archivo y quién lo subió.
 * - `status`: STATUS_ACTIVE o STATUS_SUPERSEDED.
 * - `replaced_upload_id`: carga a la que sustituyó.
 * - `total_amount`/`unattributed_amount` (float): monto total y monto sin gestor atribuido.
 * - `rows_*`: contadores de filas (total, fuera de semana, duplicadas, de otra
 *   sucursal, canceladas, fuera del rango del objetivo, pendientes de atribución).
 * - `coverage_status`: COVERAGE_* (si el archivo cubrió a todos los empleados).
 * - `declared_complete` (bool): el usuario declaró que el archivo está completo.
 * - `current_guard`: 1 solo en la carga activa (ver booted()).
 */
class OkrPlacementUpload extends Model
{
    /** Carga vigente de la semana. */
    public const STATUS_ACTIVE = 'active';

    /** Carga reemplazada por otra más reciente de la misma semana. */
    public const STATUS_SUPERSEDED = 'superseded';

    /** 18: el archivo de sucursal cubrió a TODOS los empleados con asignación activa a esa sucursal. */
    public const COVERAGE_FULL = 'full';

    /** 18: el archivo de sucursal dejó fuera al menos un empleado con asignación activa — ausencia ≠ 0. */
    public const COVERAGE_PARTIAL = 'partial';

    /** 18: no se pudo determinar el roster real de la sucursal (sin asignaciones registradas) — se trata igual que 'partial', nunca se asume cobertura. */
    public const COVERAGE_UNKNOWN = 'unknown';

    protected $fillable = [
        'okr_objective_id', 'week_number', 'week_start', 'week_end',
        'original_filename', 'stored_path', 'disk', 'uploaded_by', 'status',
        'replaced_upload_id', 'total_amount', 'rows_count', 'unattributed_amount',
        'rows_outside_week_range', 'coverage_status',
        'rows_duplicated', 'rows_other_branch', 'rows_cancelled', 'rows_out_of_objective_range',
        'rows_pending_attribution', 'declared_complete',
    ];

    /**
     * 4.9 (19-oct-2026): `current_guard` = 1 solo en la carga ACTIVA; el índice
     * único (objetivo, semana, current_guard) impide dos cargas activas de la
     * misma semana aunque lleguen dos solicitudes simultáneas.
     */
    protected static function booted(): void
    {
        static::saving(function (self $upload) {
            $upload->current_guard = $upload->status === self::STATUS_ACTIVE ? 1 : null;
        });
    }

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'total_amount' => 'float',
        'unattributed_amount' => 'float',
        'declared_complete' => 'boolean',
    ];

    /** Objetivo OKR al que pertenece la carga. */
    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    /** Usuario que subió el archivo. */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** Movimientos de colocación leídos del archivo. */
    public function movements(): HasMany
    {
        return $this->hasMany(OkrPlacementMovement::class, 'okr_placement_upload_id');
    }

    /** Carga anterior a la que esta sustituyó. */
    public function replacedUpload(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_upload_id');
    }

    /**
     * Indica si esta es la carga vigente de su semana.
     *
     * @return bool true si `status` es `active`.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
