<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EfectividadCobranzaRun extends Model
{
    /** Fase 5 (auditoría 2026-10-12) — estados reales, nunca mezclados. */
    public const STATUS_QUEUED   = 'queued';
    public const STATUS_RUNNING  = 'running';
    public const STATUS_RETRYING = 'retrying';
    public const STATUS_SUCCESS  = 'success';
    public const STATUS_BLOCKED  = 'blocked';
    public const STATUS_FAILED   = 'failed';

    /** Estados en los que el worker todavía tiene (o tendrá) trabajo pendiente. */
    public const ACTIVE_STATUSES = [self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_RETRYING];

    /** Estados finales: el Job nunca vuelve a calcular una ejecución en estos estados. */
    public const TERMINAL_STATUSES = [self::STATUS_SUCCESS, self::STATUS_BLOCKED, self::STATUS_FAILED];

    /** Categorías de bloqueo/error (ver EfectividadCobranzaIssue). */
    public const FAILURE_TECHNICAL        = 'technical';
    public const FAILURE_MISSING_DATA     = 'missing_data';
    public const FAILURE_MISSING_COLUMNS  = 'missing_columns';
    public const FAILURE_PARTIAL_COVERAGE = 'partial_coverage';
    public const FAILURE_INCOMPATIBLE     = 'incompatible';
    public const FAILURE_WORKER_STALLED   = 'worker_stalled';
    public const FAILURE_TIMEOUT          = 'timeout';

    public const NOTIFICATION_SENDING = 'sending';
    public const NOTIFICATION_SENT    = 'sent';
    public const NOTIFICATION_FAILED  = 'failed';
    public const NOTIFICATION_SKIPPED = 'skipped';

    protected $fillable = [
        'period_id',
        'branch_id',
        'status',
        'current_stage',
        'stage_started_at',
        'heartbeat_at',
        'progress_current',
        'progress_total',
        'stages',
        'sources',
        'issues',
        'log',
        'metadata',
        'coverage',
        'result_rows',
        'output_excel_path',
        'error_message',
        'failure_kind',
        'attempts',
        'queue_job_id',
        'queue_job_uuid',
        'created_by',
        'subscriber_ids',
        'queued_at',
        'started_at',
        'finished_at',
        'notification_status',
        'notification_attempts',
        'notification_error',
        'notified_at',
        'in_app_notified_at',
        'source_fingerprint',
        'saldos_report_upload_id',
        'ingresos_report_upload_id',
    ];

    protected $casts = [
        'metadata'           => 'array',
        'coverage'           => 'array',
        'result_rows'        => 'array',
        'stages'             => 'array',
        'sources'            => 'array',
        'issues'             => 'array',
        'subscriber_ids'     => 'array',
        'attempts'           => 'integer',
        'progress_current'   => 'integer',
        'progress_total'     => 'integer',
        'notification_attempts' => 'integer',
        'queued_at'          => 'datetime',
        'started_at'         => 'datetime',
        'finished_at'        => 'datetime',
        'stage_started_at'   => 'datetime',
        'heartbeat_at'       => 'datetime',
        'notified_at'        => 'datetime',
        'in_app_notified_at' => 'datetime',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function saldosReportUpload(): BelongsTo
    {
        return $this->belongsTo(ReportUpload::class, 'saldos_report_upload_id');
    }

    public function ingresosReportUpload(): BelongsTo
    {
        return $this->belongsTo(ReportUpload::class, 'ingresos_report_upload_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * Usuarios a notificar: el solicitante original y quienes pidieron la
     * misma identidad mientras ya estaba en proceso (se reutilizó la ejecución).
     *
     * @return array<int,int>
     */
    public function recipientUserIds(): array
    {
        return collect([$this->created_by])
            ->merge($this->subscriber_ids ?? [])
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function addSubscriber(?int $userId): void
    {
        if (!$userId || in_array($userId, $this->recipientUserIds(), true)) {
            return;
        }

        $this->update(['subscriber_ids' => [...($this->subscriber_ids ?? []), $userId]]);
    }

    /** Ejecuciones solicitadas por el usuario o a las que quedó suscrito. */
    public function scopeRequestedBy(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('created_by', $userId)
            ->orWhereJsonContains('subscriber_ids', $userId));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    public function scopeForIdentity(Builder $query, int $periodId, ?int $branchId): Builder
    {
        return $query->where('period_id', $periodId)
            ->where(fn ($q) => $branchId ? $q->where('branch_id', $branchId) : $q->whereNull('branch_id'));
    }

    /**
     * Última ejecución EXITOSA para esta identidad exacta (period_id + branch_id,
     * con sucursal NULL = alcance general) — nunca "el último intento", que puede
     * estar failed/running y ocultar un éxito previo válido.
     */
    public static function latestSuccessFor(int $periodId, ?int $branchId): ?self
    {
        return static::query()
            ->forIdentity($periodId, $branchId)
            ->where('status', self::STATUS_SUCCESS)
            ->latest('id')
            ->first();
    }

    public static function latestFor(int $periodId, ?int $branchId): ?self
    {
        return static::query()
            ->forIdentity($periodId, $branchId)
            ->latest('id')
            ->first();
    }
}
