<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Objective del módulo OKR — cualitativo (ver docs/OKR.md, sección "no
 * confundir Objective/KR/KPI"). FASE ACTUAL: solo scope_type='branch'|'employee'
 * (Sucursal → Gestor) — parent_id deja la puerta abierta a niveles futuros sin
 * desarrollarlos ahora.
 */
class OkrObjective extends Model
{
    use SoftDeletes;

    public const SCOPE_BRANCH   = 'branch';
    public const SCOPE_EMPLOYEE = 'employee';

    public const STATUS_DRAFT     = 'draft';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_CLOSED    = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const HEALTH_AHEAD     = 'ahead';
    public const HEALTH_ON_TRACK  = 'on_track';
    public const HEALTH_RISK      = 'risk';
    public const HEALTH_OFF_TRACK = 'off_track';

    public const FINAL_COMPLETED           = 'completed';
    public const FINAL_PARTIALLY_COMPLETED = 'partially_completed';
    public const FINAL_NOT_COMPLETED       = 'not_completed';

    protected $fillable = [
        'parent_id', 'scope_type', 'branch_id', 'employee_id', 'title',
        'responsible_user_id', 'created_by', 'start_date', 'end_date',
        'duration_weeks', 'lifecycle_status', 'health_status', 'final_status',
        'activated_at', 'closed_at',
    ];

    protected $casts = [
        'start_date'   => 'date',
        'end_date'     => 'date',
        'activated_at' => 'datetime',
        'closed_at'    => 'datetime',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function keyResults(): HasMany
    {
        return $this->hasMany(OkrKeyResult::class, 'okr_objective_id');
    }

    public function checkIns(): HasMany
    {
        return $this->hasMany(OkrCheckIn::class, 'okr_objective_id');
    }

    public function correctiveActions(): HasMany
    {
        return $this->hasMany(OkrCorrectiveAction::class, 'okr_objective_id');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(OkrEvidence::class, 'okr_objective_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(OkrAlert::class, 'okr_objective_id');
    }

    public function isActive(): bool
    {
        return $this->lifecycle_status === self::STATUS_ACTIVE;
    }

    /**
     * Semana actual (1-based) contando desde start_date — 0 si todavía no
     * inicia (ver OkrCalendarService, fuente única de esta aritmética).
     */
    public function currentWeekNumber(?\DateTimeInterface $asOf = null): int
    {
        return app(\App\Services\Okr\OkrCalendarService::class)
            ->currentWeekNumber($this->start_date, (int) $this->duration_weeks, $asOf);
    }

    public function hasStarted(?\DateTimeInterface $asOf = null): bool
    {
        return $this->currentWeekNumber($asOf) >= 1;
    }
}
