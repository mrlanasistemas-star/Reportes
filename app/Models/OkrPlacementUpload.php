<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OkrPlacementUpload extends Model
{
    public const STATUS_ACTIVE = 'active';
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
    ];

    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'total_amount' => 'float',
        'unattributed_amount' => 'float',
    ];

    public function objective(): BelongsTo
    {
        return $this->belongsTo(OkrObjective::class, 'okr_objective_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(OkrPlacementMovement::class, 'okr_placement_upload_id');
    }

    public function replacedUpload(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_upload_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
