<?php

namespace App\Models;

use App\Services\Efectividad\V4\EfectividadV4Calculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ejecución del cálculo de Efectividad de Cobranza (en segundo plano).
 *
 * Tabla: `efectividad_cobranza_runs`. Cada fila es un cálculo para un periodo y
 * sucursal (NULL = alcance general) con un motor y metodología concretos. Lo
 * procesa un Job en cola por etapas (con latido/heartbeat para detectar workers
 * detenidos), guarda el resultado y el Excel, y notifica al solicitante y a los
 * suscriptores por correo y dentro del sistema.
 *
 * Columnas clave:
 * - `engine_version`/`metodologia`: versión técnica del motor y metodología financiera (sellos separados).
 * - `mode`: MODE_SEMANAL o MODE_DIARIO; `base_date`/`cutoff_date` (date).
 * - `status`: STATUS_*; `failure_kind`: FAILURE_*; `error_message`.
 * - `current_stage`/`stage_started_at`/`heartbeat_at`/`progress_current`/`progress_total`/`stages` (array): progreso.
 * - `sources`/`issues`/`coverage`/`result_rows`/`metadata` (array): fuentes, problemas, cobertura y resultado.
 * - `output_excel_path`: Excel generado.
 * - `attempts`, `queue_job_id`/`queue_job_uuid`: reintentos y Job en cola.
 * - `created_by`/`subscriber_ids` (array): solicitante y usuarios suscritos.
 * - `cancel_requested_at`/`cancelled_by`/`cancelled_at`: cancelación.
 * - `notification_*`, `notified_at`, `in_app_notified_at`: estado de notificación.
 * - `source_fingerprint`, `saldos_report_upload_id`, `ingresos_report_upload_id`: archivos fuente usados.
 * - `incidents_count` (int): número de incidencias de la bitácora.
 */
class EfectividadCobranzaRun extends Model
{
    /** Fase 5 (auditoría 2026-10-12) — estados reales, nunca mezclados. En cola. */
    public const STATUS_QUEUED = 'queued';

    /** Calculando. */
    public const STATUS_RUNNING = 'running';

    /** Reintentando tras un fallo transitorio. */
    public const STATUS_RETRYING = 'retrying';

    /** Terminó con éxito. */
    public const STATUS_SUCCESS = 'success';

    /** Bloqueado por datos (faltan archivos/columnas, cobertura parcial...). */
    public const STATUS_BLOCKED = 'blocked';

    /** Falló por un error técnico. */
    public const STATUS_FAILED = 'failed';

    /** Fase 4 (cancelación real) — nunca se mezcla con `failed` (técnico) ni `blocked` (datos). */
    public const STATUS_CANCELLED = 'cancelled';

    /** Estados en los que el worker todavía tiene (o tendrá) trabajo pendiente. */
    public const ACTIVE_STATUSES = [self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_RETRYING];

    /** Estados finales: el Job nunca vuelve a calcular una ejecución en estos estados. */
    public const TERMINAL_STATUSES = [self::STATUS_SUCCESS, self::STATUS_BLOCKED, self::STATUS_FAILED, self::STATUS_CANCELLED];

    /** Categorías de bloqueo/error (ver EfectividadCobranzaIssue). Error técnico. */
    public const FAILURE_TECHNICAL = 'technical';

    /** Faltan datos/archivos fuente. */
    public const FAILURE_MISSING_DATA = 'missing_data';

    /** Faltan columnas requeridas en un archivo. */
    public const FAILURE_MISSING_COLUMNS = 'missing_columns';

    /** Las fuentes no cubren todo el rango/sucursales requeridos. */
    public const FAILURE_PARTIAL_COVERAGE = 'partial_coverage';

    /** Fuentes incompatibles con el motor. */
    public const FAILURE_INCOMPATIBLE = 'incompatible';

    /** El worker dejó de dar señales de vida (heartbeat). */
    public const FAILURE_WORKER_STALLED = 'worker_stalled';

    /** Se excedió el tiempo máximo. */
    public const FAILURE_TIMEOUT = 'timeout';

    /** Notificación en envío. */
    public const NOTIFICATION_SENDING = 'sending';

    /** Notificación enviada. */
    public const NOTIFICATION_SENT = 'sent';

    /** Notificación fallida. */
    public const NOTIFICATION_FAILED = 'failed';

    /** Notificación omitida (no aplicaba). */
    public const NOTIFICATION_SKIPPED = 'skipped';

    protected $fillable = [
        'period_id',
        'branch_id',
        'engine_version',
        'metodologia',
        'mode',
        'base_date',
        'cutoff_date',
        'incidents_count',
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
        'cancel_requested_at',
        'cancelled_by',
        'cancelled_at',
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
        'metadata' => 'array',
        'coverage' => 'array',
        'result_rows' => 'array',
        'stages' => 'array',
        'sources' => 'array',
        'issues' => 'array',
        'subscriber_ids' => 'array',
        'attempts' => 'integer',
        'progress_current' => 'integer',
        'progress_total' => 'integer',
        'notification_attempts' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'stage_started_at' => 'datetime',
        'heartbeat_at' => 'datetime',
        'notified_at' => 'datetime',
        'in_app_notified_at' => 'datetime',
        'base_date' => 'date',
        'cutoff_date' => 'date',
        'incidents_count' => 'integer',
    ];

    /**
     * Motor aprobado anterior (CALCULADORA DE EFECTIVIDAD DE COBRANZA APROBADO.xlsm).
     * Reconectado (rediseño premium, oct-2026) como el motor "Periódica": periodos
     * SIN fotografías diarias (un corte de Saldos por Cliente + histórico de
     * Ingresos Cobranza) — la operación real del usuario cada 4 semanas. Es una
     * metodología DISTINTA de V4 (otra macro aprobada), nunca se presenta como V4.
     */
    public const ENGINE_APROBADA_2026_10 = 'aprobada-2026-10';

    /** Cálculo de la semana completa. */
    public const MODE_SEMANAL = 'SEMANAL';

    /** Cálculo acumulado hasta una fecha de corte (`cutoff_date`). */
    public const MODE_DIARIO = 'DIARIO';

    /**
     * Versión vigente del motor V4 (MR_LANA_EFECTIVIDAD.xlsm, periodos semanales con fotografías).
     *
     * @return string Versión según EfectividadV4Calculator::ENGINE_VERSION.
     */
    public static function currentEngineVersion(): string
    {
        return EfectividadV4Calculator::ENGINE_VERSION;
    }

    /**
     * Metodología financiera vigente (p. ej. V7), INDEPENDIENTE de la versión
     * técnica del motor. La metodología puede cambiar (V6→V7) sin que cambie
     * `engine_version`; por eso se sella y se filtra por separado: así la API
     * nunca entrega un resultado V6 como si fuera V7.
     *
     * @return string Metodología según EfectividadV4Calculator::METODOLOGIA.
     */
    public static function currentMethodology(): string
    {
        return EfectividadV4Calculator::METODOLOGIA;
    }

    /**
     * ¿Esta ejecución se calculó con la metodología vigente? Las ejecuciones sin
     * sello (`metodologia` NULL, anteriores a esta columna) se consideran NO
     * vigentes: deben regenerarse, nunca se asumen V7.
     *
     * @return bool true si `metodologia` coincide con la vigente.
     */
    public function isCurrentMethodology(): bool
    {
        return $this->metodologia === self::currentMethodology();
    }

    /**
     * Motores que el sistema sigue generando activamente hoy (ninguno es "obsoleto" por sí mismo).
     *
     * @return array<int, string> Versión V4 vigente y motor Aprobada/Periódica.
     */
    public static function supportedEngines(): array
    {
        return [EfectividadV4Calculator::ENGINE_VERSION, self::ENGINE_APROBADA_2026_10];
    }

    /**
     * "Vigente" ya no significa "es V4": significa que el motor con el que se
     * generó esta ejecución sigue siendo uno de los que el sistema genera
     * activamente (V4 para semanas con fotografías, Aprobada/Periódica para
     * periodos de 4 semanas sin ellas). Solo un motor realmente RETIRADO
     * (ninguno de los dos) cuenta como obsoleto por motor.
     *
     * @return bool true si `engine_version` está en supportedEngines().
     */
    public function isCurrentEngine(): bool
    {
        return in_array($this->engine_version, self::supportedEngines(), true);
    }

    /**
     * Indica si la ejecución usó el motor "Periódica" (Aprobada oct-2026, sin fotografías diarias).
     *
     * @return bool true si `engine_version` es ENGINE_APROBADA_2026_10.
     */
    public function isPeriodicaEngine(): bool
    {
        return $this->engine_version === self::ENGINE_APROBADA_2026_10;
    }

    /** Incidencias de la bitácora de auditoría de la ejecución, en orden (`position`). */
    public function incidents(): HasMany
    {
        return $this->hasMany(EfectividadCobranzaRunIncident::class)->orderBy('position');
    }

    /** Periodo calculado. */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /** Sucursal calculada (null = alcance general). */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Usuario que solicitó la ejecución. */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Archivo de Saldos por Cliente usado como fuente. */
    public function saldosReportUpload(): BelongsTo
    {
        return $this->belongsTo(ReportUpload::class, 'saldos_report_upload_id');
    }

    /** Archivo de Ingresos Cobranza usado como fuente. */
    public function ingresosReportUpload(): BelongsTo
    {
        return $this->belongsTo(ReportUpload::class, 'ingresos_report_upload_id');
    }

    /**
     * Indica si la ejecución sigue pendiente de trabajo (en cola, corriendo o reintentando).
     *
     * @return bool true si `status` está en ACTIVE_STATUSES.
     */
    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * Cancelación solicitada pero aún no confirmada por el Job (sigue activa).
     *
     * @return bool true si `cancel_requested_at` tiene fecha.
     */
    public function isCancelRequested(): bool
    {
        return $this->cancel_requested_at !== null;
    }

    /**
     * Indica si todavía se puede pedir la cancelación: está activa y no se pidió antes.
     *
     * @return bool true si se puede cancelar.
     */
    public function canBeCancelled(): bool
    {
        return $this->isActive() && ! $this->isCancelRequested();
    }

    /**
     * Indica si la ejecución está en un estado final (éxito, bloqueada, fallida o cancelada).
     *
     * @return bool true si `status` está en TERMINAL_STATUSES.
     */
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

    /**
     * Suscribe a un usuario para que reciba la notificación de esta ejecución
     * (cuando pidió la misma identidad mientras ya estaba en proceso).
     * No hace nada si el ID es nulo/0 o ya es destinatario.
     *
     * Efecto secundario: guarda `subscriber_ids` en BD (update()).
     *
     * @param  int|null  $userId  Usuario a suscribir.
     */
    public function addSubscriber(?int $userId): void
    {
        if (! $userId || in_array($userId, $this->recipientUserIds(), true)) {
            return;
        }

        $this->update(['subscriber_ids' => [...($this->subscriber_ids ?? []), $userId]]);
    }

    /**
     * Quién puede abrir una ejecución por su ID (detalle, progreso, Excel, incidencias):
     * admin cualquiera; los demás roles con acceso a Reportería, solo las que
     * solicitaron o a las que quedaron suscritos — la misma regla del historial.
     * Un usuario sin acceso gerencial (o null) nunca la ve.
     *
     * @param  User|null  $user  Usuario que intenta abrir la ejecución.
     * @return bool true si puede verla.
     */
    public function isVisibleTo(?User $user): bool
    {
        if (! $user || ! $user->hasManagerialAccess()) {
            return false;
        }

        return $user->isAdmin() || in_array((int) $user->id, $this->recipientUserIds(), true);
    }

    /**
     * Scope: ejecuciones solicitadas por el usuario o a las que quedó suscrito.
     *
     * @param  Builder  $query  Consulta a filtrar.
     * @param  int  $userId  Usuario.
     * @return Builder La consulta filtrada.
     */
    public function scopeRequestedBy(Builder $query, int $userId): Builder
    {
        return $query->where(fn (Builder $q) => $q
            ->where('created_by', $userId)
            ->orWhereJsonContains('subscriber_ids', $userId));
    }

    /**
     * Scope: solo ejecuciones activas (en cola, corriendo o reintentando).
     *
     * @param  Builder  $query  Consulta a filtrar.
     * @return Builder La consulta filtrada.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

    /**
     * Scope: ejecuciones de un periodo y sucursal exactos (sucursal null = alcance general).
     *
     * @param  Builder  $query  Consulta a filtrar.
     * @param  int  $periodId  Periodo.
     * @param  int|null  $branchId  Sucursal, o null para el alcance general.
     * @return Builder La consulta filtrada.
     */
    public function scopeForIdentity(Builder $query, int $periodId, ?int $branchId): Builder
    {
        return $query->where('period_id', $periodId)
            ->where(fn ($q) => $branchId ? $q->where('branch_id', $branchId) : $q->whereNull('branch_id'));
    }

    /**
     * Última ejecución EXITOSA para esta identidad exacta (period_id + branch_id,
     * con sucursal NULL = alcance general) — nunca "el último intento", que puede
     * estar failed/running y ocultar un éxito previo válido.
     *
     * @param  int  $periodId  Periodo.
     * @param  int|null  $branchId  Sucursal, o null para el alcance general.
     * @return self|null La última ejecución exitosa, o null.
     */
    public static function latestSuccessFor(int $periodId, ?int $branchId): ?self
    {
        return static::query()
            ->forIdentity($periodId, $branchId)
            ->where('status', self::STATUS_SUCCESS)
            ->latest('id')
            ->first();
    }

    /**
     * Identidad V4: periodo semanal + sucursal + modo (+ fecha de corte en DIARIO).
     * Solo considera ejecuciones del motor vigente Y de la metodología vigente
     * (V7): una ejecución con el mismo `engine_version` pero metodología anterior
     * (o sin sello) NO es parte de la identidad vigente — debe regenerarse.
     *
     * @param  Builder  $query  Consulta a filtrar.
     * @param  int  $periodId  Periodo semanal.
     * @param  int|null  $branchId  Sucursal, o null para el alcance general.
     * @param  string  $mode  MODE_SEMANAL o MODE_DIARIO.
     * @param  string|null  $cutoff  Fecha de corte (solo se aplica en modo DIARIO).
     * @return Builder La consulta filtrada.
     */
    public function scopeForV4Identity(Builder $query, int $periodId, ?int $branchId, string $mode, ?string $cutoff): Builder
    {
        return $query->forIdentity($periodId, $branchId)
            ->where('engine_version', self::currentEngineVersion())
            ->where('metodologia', self::currentMethodology())
            ->where('mode', $mode)
            ->when($mode === self::MODE_DIARIO, fn ($q) => $q->whereDate('cutoff_date', $cutoff));
    }

    /**
     * Último intento (de cualquier estado y motor) para un periodo y sucursal.
     *
     * @param  int  $periodId  Periodo.
     * @param  int|null  $branchId  Sucursal, o null para el alcance general.
     * @return self|null La ejecución más reciente, o null.
     */
    public static function latestFor(int $periodId, ?int $branchId): ?self
    {
        return static::query()
            ->forIdentity($periodId, $branchId)
            ->latest('id')
            ->first();
    }
}
