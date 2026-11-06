<?php

namespace App\Models\EstadoResultados;

use App\Models\Period;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstadoResultadosUpload extends Model
{
    public const STATUS_LOADED = 'cargado';

    public const STATUS_PROCESSED = 'procesado';

    public const STATUS_REPLACED = 'reemplazado';

    // ── Ciclo de vida del archivo (independiente de `status`) ─────────────
    public const LIFECYCLE_RECIBIDO = 'recibido';

    public const LIFECYCLE_VALIDADO = 'validado';

    public const LIFECYCLE_PROCESADO = 'procesado';

    public const LIFECYCLE_CONCILIADO = 'conciliado';

    public const LIFECYCLE_RECHAZADO = 'rechazado';

    public const LIFECYCLE_DUPLICADO = 'duplicado';

    public const LIFECYCLE_REEMPLAZADO = 'reemplazado';

    public const LIFECYCLE_LABELS = [
        self::LIFECYCLE_RECIBIDO => 'Recibido',
        self::LIFECYCLE_VALIDADO => 'Validado',
        self::LIFECYCLE_PROCESADO => 'Procesado',
        self::LIFECYCLE_CONCILIADO => 'Conciliado',
        self::LIFECYCLE_RECHAZADO => 'Rechazado',
        self::LIFECYCLE_DUPLICADO => 'Ya estaba cargado',
        self::LIFECYCLE_REEMPLAZADO => 'Reemplazado',
    ];

    public const SEVERITY_INFO = 'informativa';

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

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(EstadoResultadosMovement::class, 'upload_id');
    }

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

    public static function lifecycleLabel(?string $lifecycle): string
    {
        return self::LIFECYCLE_LABELS[$lifecycle] ?? (string) $lifecycle;
    }

    /**
     * Marca como procesadas las cargas ACTIVAS indicadas (status + lifecycle + processed_at).
     * Para el procesador: sustituye al update(['status' => procesado]).
     *
     * @param  list<int>  $uploadIds
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

    /** @return list<array{code:string,severity:string,message:string}> */
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
