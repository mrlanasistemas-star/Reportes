<?php

namespace App\Models\EstadoResultados;

use App\Models\Period;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Estado de cuenta bancario cargado al Estado de Resultados (tabla `estado_resultados_uploads`).
 *
 * Cada fila es un archivo publicado para un periodo, una cuenta (`account_slot`: administrativa |
 * excedentes, con `account_label` p.ej. «BBVA ****6697») y una parte (`file_part`: hay hasta dos
 * archivos por cuenta). Al volver a subir la misma cuenta/parte la carga anterior queda
 * `reemplazado` (`superseded_at`, `superseded_by_upload_id`).
 *
 * Columnas clave: `layout` (estructura detectada), datos del archivo (`original_name`, `file_hash`,
 * `file_size`, `stored_path`, `has_macros`), contadores (`rows_read`, `movements_count`,
 * `detail_lines_count`, `out_of_period_count`), totales leídos (`total_cargos`, `total_abonos`)
 * contra los de control del archivo (`control_total_*`, `control_ok`), rango de fechas,
 * avisos (`warnings` texto plano y `warnings_detail` estructurado con severidad,
 * `blocking_warnings` cuántos bloquean la aprobación), `status`, `lifecycle` y tiempos
 * `validated_at`/`processed_at`.
 */
class EstadoResultadosUpload extends Model
{
    /** Carga publicada y aún no procesada. */
    public const STATUS_LOADED = 'cargado';

    /** Carga ya incluida en un procesamiento del periodo. */
    public const STATUS_PROCESSED = 'procesado';

    /** Carga sustituida por otra más reciente de la misma cuenta/parte. */
    public const STATUS_REPLACED = 'reemplazado';

    // ── Ciclo de vida del archivo (independiente de `status`) ─────────────
    public const LIFECYCLE_RECIBIDO = 'recibido';

    public const LIFECYCLE_VALIDADO = 'validado';

    public const LIFECYCLE_PROCESADO = 'procesado';

    public const LIFECYCLE_CONCILIADO = 'conciliado';

    public const LIFECYCLE_RECHAZADO = 'rechazado';

    public const LIFECYCLE_DUPLICADO = 'duplicado';

    public const LIFECYCLE_REEMPLAZADO = 'reemplazado';

    /** Etiquetas en español de cada etapa del ciclo de vida. */
    public const LIFECYCLE_LABELS = [
        self::LIFECYCLE_RECIBIDO => 'Recibido',
        self::LIFECYCLE_VALIDADO => 'Validado',
        self::LIFECYCLE_PROCESADO => 'Procesado',
        self::LIFECYCLE_CONCILIADO => 'Conciliado',
        self::LIFECYCLE_RECHAZADO => 'Rechazado',
        self::LIFECYCLE_DUPLICADO => 'Ya estaba cargado',
        self::LIFECYCLE_REEMPLAZADO => 'Reemplazado',
    ];

    /** Aviso sólo informativo: no impide aprobar. */
    public const SEVERITY_INFO = 'informativa';

    /** Aviso bloqueante: impide aprobar el periodo hasta resolverse. */
    public const SEVERITY_BLOCKING = 'bloqueante';

    protected $table = 'estado_resultados_uploads';

    protected $guarded = ['id'];

    protected $casts = [
        'warnings' => 'array',
        'warnings_detail' => 'array',
        'blocking_warnings' => 'integer',
        'has_macros' => 'boolean',
        'control_ok' => 'boolean',
        'first_movement_date' => 'date',
        'last_movement_date' => 'date',
        'superseded_at' => 'datetime',
        'validated_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /**
     * Periodo al que se cargó el estado de cuenta.
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /**
     * Usuario que subió el archivo (columna `uploaded_by`).
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Movimientos bancarios normalizados leídos de este archivo.
     */
    public function movements(): HasMany
    {
        return $this->hasMany(EstadoResultadosMovement::class, 'upload_id');
    }

    /**
     * Scope: sólo cargas no reemplazadas (las que cuentan para el periodo).
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', '!=', self::STATUS_REPLACED);
    }

    /**
     * Ciclo de vida PRESENTADO (función pura, sin consultas):
     *   reemplazado → la carga fue sustituida;
     *   validado    → archivo válido y publicado, aún sin procesar;
     *   conciliado  → procesado, sin movimientos pendientes de clasificar, totales de control
     *                 que no fallan y sin avisos bloqueantes;
     *   procesado   → procesado pero con pendientes / control que no cuadra / aviso bloqueante.
     *
     * @param  string  $status  `status` de la carga (cargado | procesado | reemplazado).
     * @param  bool|null  $controlOk  Resultado de totales de control (null = el archivo no trae control).
     * @param  bool  $processed  Si la carga ya entró a un procesamiento.
     * @param  int  $pendingCount  Movimientos pendientes de clasificar.
     * @param  int  $blockingWarnings  Número de avisos bloqueantes.
     * @return string Una de las constantes LIFECYCLE_*.
     */
    public static function lifecycleFor(string $status, ?bool $controlOk, bool $processed, int $pendingCount, int $blockingWarnings = 0): string
    {
        return match (true) {
            $status === self::STATUS_REPLACED => self::LIFECYCLE_REEMPLAZADO,
            ! $processed => self::LIFECYCLE_VALIDADO,
            $pendingCount === 0 && $controlOk !== false && $blockingWarnings === 0 => self::LIFECYCLE_CONCILIADO,
            default => self::LIFECYCLE_PROCESADO,
        };
    }

    /**
     * Etiqueta en español de una etapa del ciclo de vida; si no se conoce devuelve el valor tal cual.
     *
     * @param  string|null  $lifecycle  Valor LIFECYCLE_*.
     */
    public static function lifecycleLabel(?string $lifecycle): string
    {
        return self::LIFECYCLE_LABELS[$lifecycle] ?? (string) $lifecycle;
    }

    /**
     * Marca como procesadas las cargas ACTIVAS indicadas (status + lifecycle + processed_at).
     * Para el procesador: sustituye al update(['status' => procesado]).
     *
     * Efecto: UPDATE masivo en BD (no dispara eventos de modelo). Ignora ids reemplazados.
     *
     * @param  list<int>  $uploadIds  Cargas que entraron al procesamiento.
     * @return int Número de filas actualizadas.
     */
    public static function markProcessed(array $uploadIds): int
    {
        $ids = array_values(array_unique(array_map('intval', $uploadIds)));
        if ($ids === []) {
            return 0;
        }

        return static::query()->active()->whereIn('id', $ids)->update([
            'status' => self::STATUS_PROCESSED,
            'lifecycle' => self::LIFECYCLE_PROCESADO,
            'processed_at' => now(),
        ]);
    }

    /**
     * Avisos que BLOQUEAN la aprobación ([{code, severity, message}]). Compatible con cargas
     * previas a la migración C2: un control que no cuadra siempre es bloqueante.
     *
     * @param  array|null  $detail  Avisos estructurados (`warnings_detail`).
     * @param  bool|null  $controlOk  false agrega el aviso «control_no_cuadra» si no venía.
     * @return list<array{code:string,severity:string,message:string}>
     */
    public static function blockingWarnings(?array $detail, ?bool $controlOk = null): array
    {
        $out = array_values(array_filter((array) $detail, fn ($w) => ($w['severity'] ?? null) === self::SEVERITY_BLOCKING));
        if ($controlOk === false && ! in_array('control_no_cuadra', array_column($out, 'code'), true)) {
            $out[] = ['code' => 'control_no_cuadra', 'severity' => self::SEVERITY_BLOCKING, 'message' => 'Los totales leídos no coinciden con los totales de control del archivo.'];
        }

        return $out;
    }

    /**
     * Avisos bloqueantes de ESTA carga (atajo de blockingWarnings() con sus propias columnas).
     *
     * @return list<array{code:string,severity:string,message:string}>
     */
    public function blockingWarningsList(): array
    {
        return self::blockingWarnings($this->warnings_detail, $this->control_ok);
    }

    /**
     * Avisos estructurados; para cargas previas a la migración C2 se derivan de `warnings`
     * (texto) como informativos, salvo el control que no cuadra (bloqueante).
     *
     * @return list<array{code:string,severity:string,message:string}>
     */
    public function warningsDetailed(): array
    {
        if (is_array($this->warnings_detail)) {
            return array_values($this->warnings_detail);
        }

        return array_values(array_map(fn ($m) => str_starts_with((string) $m, 'Los totales leídos no coinciden')
            ? ['code' => 'control_no_cuadra', 'severity' => self::SEVERITY_BLOCKING, 'message' => (string) $m]
            : ['code' => 'aviso', 'severity' => self::SEVERITY_INFO, 'message' => (string) $m], (array) $this->warnings));
    }
}
