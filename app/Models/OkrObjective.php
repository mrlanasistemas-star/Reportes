<?php

namespace App\Models;

use App\Services\Okr\OkrCalendarService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Objective del módulo OKR — cualitativo (ver docs/MANUAL-DESARROLLADOR.md, sección "no
 * confundir Objective/KR/KPI"). FASE ACTUAL: solo scope_type='branch'|'employee'
 * (Sucursal → Gestor) — parent_id deja la puerta abierta a niveles futuros sin
 * desarrollarlos ahora.
 *
 * Tabla: `okr_objectives` (con borrado lógico, SoftDeletes). Cada fila es un
 * objetivo de un ciclo de N semanas para una sucursal o un gestor, con sus
 * resultados clave, check-ins, evidencias, alertas, cargas de colocación y
 * documentos (carta compromiso, warnings).
 *
 * Columnas clave:
 * - `parent_id`: objetivo padre (un OKR de gestor cuelga del de su sucursal).
 * - `scope_type`: SCOPE_BRANCH o SCOPE_EMPLOYEE; `branch_id`/`employee_id` según el alcance.
 * - `accountable_employee_id`: titular de un OKR de sucursal.
 * - `responsible_user_id`/`created_by`: responsable administrativo y creador.
 * - `start_date`/`end_date` (date), `duration_weeks`: calendario del ciclo.
 * - `lifecycle_status`: STATUS_*; `health_status`: HEALTH_*; `final_status`: FINAL_*.
 * - `activated_at`/`closed_at` (datetime): activación y cierre.
 */
class OkrObjective extends Model
{
    use SoftDeletes;

    /** OKR de sucursal. */
    public const SCOPE_BRANCH = 'branch';

    /** OKR individual de un gestor. */
    public const SCOPE_EMPLOYEE = 'employee';

    /** Ciclo de vida: borrador (aún no activo). */
    public const STATUS_DRAFT = 'draft';

    /** Ciclo de vida: activo (en seguimiento). */
    public const STATUS_ACTIVE = 'active';

    /** Ciclo de vida: cerrado (solo lectura). */
    public const STATUS_CLOSED = 'closed';

    /** Ciclo de vida: cancelado (solo lectura). */
    public const STATUS_CANCELLED = 'cancelled';

    /** Semáforo: adelantado respecto a lo esperado. */
    public const HEALTH_AHEAD = 'ahead';

    /** Semáforo: en ruta. */
    public const HEALTH_ON_TRACK = 'on_track';

    /** Semáforo: en riesgo. */
    public const HEALTH_RISK = 'risk';

    /** Semáforo: fuera de ruta. */
    public const HEALTH_OFF_TRACK = 'off_track';

    /** Resultado final: cumplido. */
    public const FINAL_COMPLETED = 'completed';

    /** Resultado final: cumplido parcialmente. */
    public const FINAL_PARTIALLY_COMPLETED = 'partially_completed';

    /** Resultado final: no cumplido. */
    public const FINAL_NOT_COMPLETED = 'not_completed';

    protected $fillable = [
        'parent_id', 'scope_type', 'branch_id', 'employee_id', 'accountable_employee_id', 'title',
        'responsible_user_id', 'created_by', 'start_date', 'end_date',
        'duration_weeks', 'lifecycle_status', 'health_status', 'final_status',
        'activated_at', 'closed_at',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'activated_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /** Objetivo padre (ej. el OKR de sucursal del que deriva un OKR individual). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Objetivos hijos (ej. OKR individuales de los gestores de la sucursal). */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** Sucursal del objetivo. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Gestor evaluado en un OKR individual. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Usuario responsable administrativo del objetivo. */
    public function responsibleUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    /** Usuario que creó el objetivo. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Resultados clave del objetivo. */
    public function keyResults(): HasMany
    {
        return $this->hasMany(OkrKeyResult::class, 'okr_objective_id');
    }

    /** Check-ins semanales del objetivo. */
    public function checkIns(): HasMany
    {
        return $this->hasMany(OkrCheckIn::class, 'okr_objective_id');
    }

    /** Acciones correctivas del objetivo. */
    public function correctiveActions(): HasMany
    {
        return $this->hasMany(OkrCorrectiveAction::class, 'okr_objective_id');
    }

    /** Evidencias subidas al objetivo. */
    public function evidences(): HasMany
    {
        return $this->hasMany(OkrEvidence::class, 'okr_objective_id');
    }

    /** Alertas internas del objetivo. */
    public function alerts(): HasMany
    {
        return $this->hasMany(OkrAlert::class, 'okr_objective_id');
    }

    /** Cargas semanales de colocación del objetivo. */
    public function placementUploads(): HasMany
    {
        return $this->hasMany(OkrPlacementUpload::class, 'okr_objective_id');
    }

    /**
     * Carta VIGENTE (no anulada) — 15-oct-2026: una anulada se conserva en commitmentLetters().
     * Si hubiera varias emitidas, toma la de mayor id.
     */
    public function commitmentLetter(): HasOne
    {
        return $this->hasOne(OkrCommitmentLetter::class, 'okr_objective_id')->ofMany(['id' => 'max'], fn ($q) => $q->where('status', OkrCommitmentLetter::STATUS_ISSUED));
    }

    /** Todas las cartas compromiso del objetivo, incluidas las anuladas. */
    public function commitmentLetters(): HasMany
    {
        return $this->hasMany(OkrCommitmentLetter::class, 'okr_objective_id');
    }

    /** Titular real de un OKR de sucursal (colaborador que acepta la responsabilidad general). */
    public function accountableEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'accountable_employee_id');
    }

    /**
     * Colaborador EVALUADO por este OKR: el gestor de un OKR individual o el
     * titular de un OKR de sucursal. Nunca el creador ni el responsable
     * administrativo (15-oct-2026).
     *
     * @return int|null ID del Employee evaluado, o null si no hay titular.
     */
    public function subjectEmployeeId(): ?int
    {
        return $this->scope_type === self::SCOPE_EMPLOYEE ? $this->employee_id : $this->accountable_employee_id;
    }

    /** Warnings (llamadas de atención) emitidos para el objetivo, incluidos los anulados. */
    public function warnings(): HasMany
    {
        return $this->hasMany(OkrWarning::class, 'okr_objective_id');
    }

    /**
     * Indica si el objetivo está activo (en seguimiento).
     *
     * @return bool true si `lifecycle_status` es `active`.
     */
    public function isActive(): bool
    {
        return $this->lifecycle_status === self::STATUS_ACTIVE;
    }

    /**
     * Parte 12 del cierre (04-oct-2026): closed/cancelled son de SOLO
     * LECTURA — ningún check-in, evidencia, carga semanal de colocación ni
     * edición de meta/peso nuevos (ver CheckInController, EvidenceController,
     * PlacementUploadController, ObjectiveController::updateGoal/updateWeights).
     *
     * @return bool true si el objetivo está cerrado o cancelado.
     */
    public function isReadOnly(): bool
    {
        return in_array($this->lifecycle_status, [self::STATUS_CLOSED, self::STATUS_CANCELLED], true);
    }

    /**
     * Semana actual (1-based) contando desde start_date — 0 si todavía no
     * inicia (ver OkrCalendarService, fuente única de esta aritmética).
     *
     * @param  \DateTimeInterface|null  $asOf  Fecha de referencia; null = hoy.
     * @return int Número de semana del ciclo (0 si aún no inicia).
     */
    public function currentWeekNumber(?\DateTimeInterface $asOf = null): int
    {
        return app(OkrCalendarService::class)
            ->currentWeekNumber($this->start_date, (int) $this->duration_weeks, $asOf);
    }

    /**
     * Indica si el ciclo del objetivo ya inició a la fecha dada.
     *
     * @param  \DateTimeInterface|null  $asOf  Fecha de referencia; null = hoy.
     * @return bool true si la semana actual es 1 o mayor.
     */
    public function hasStarted(?\DateTimeInterface $asOf = null): bool
    {
        return $this->currentWeekNumber($asOf) >= 1;
    }
}
