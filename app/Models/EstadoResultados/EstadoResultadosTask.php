<?php

namespace App\Models\EstadoResultados;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarea en segundo plano del Estado de Resultados («Procesar conciliación» / «Aprobar»).
 * Ver RunEstadoResultadosTaskJob. `response` + `http_status` son el resultado que antes
 * devolvía la petición web síncrona (éxito o error con su mismo código y cuerpo).
 *
 * Tabla `estado_resultados_tasks`. Columnas clave: `period_id`, `kind` (procesar | aprobar),
 * `status` (queued | running | ok | error), `user_id`, `input` (parámetros de la petición
 * original, JSON), `progress_percent` y `current_step` (avance que ve el usuario),
 * `http_status` + `response` (resultado final) y tiempos `queued_at`/`started_at`/`finished_at`.
 */
class EstadoResultadosTask extends Model
{
    /** Tarea de «Procesar conciliación» del periodo. */
    public const KIND_PROCESS = 'procesar';

    /** Tarea de «Aprobar» (cerrar versión) del periodo. */
    public const KIND_APPROVE = 'aprobar';

    /** En cola, esperando a un worker. */
    public const STATUS_QUEUED = 'queued';

    /** Un worker la está ejecutando. */
    public const STATUS_RUNNING = 'running';

    /** Terminó con éxito. */
    public const STATUS_OK = 'ok';

    /** Terminó con error (ver `response` / `http_status`). */
    public const STATUS_ERROR = 'error';

    /**
     * Una tarea abierta más vieja que esto se considera abandonada (worker caído o
     * reiniciado a media corrida) y deja de bloquear nuevas tareas del periodo.
     * Holgura sobre el timeout del job (1800 s).
     */
    public const STALE_AFTER_SECONDS = 2 * 3600;

    protected $table = 'estado_resultados_tasks';

    protected $guarded = ['id'];

    protected $casts = [
        'input' => 'array',
        'response' => 'array',
        'progress_percent' => 'integer',
        'http_status' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Usuario que solicitó la tarea.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Indica si la tarea ya terminó (con éxito o con error).
     *
     * @return bool true si `status` es ok o error.
     */
    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_OK, self::STATUS_ERROR], true);
    }

    /**
     * Scope: tareas en cola o en curso que siguen vigentes (no abandonadas), es decir,
     * encoladas hace menos de STALE_AFTER_SECONDS. Se usa para impedir tareas simultáneas.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING])
            ->where('queued_at', '>=', now()->subSeconds(self::STALE_AFTER_SECONDS));
    }

    /**
     * Cierra como error las tareas abiertas abandonadas del periodo, para que la pantalla no
     * se quede esperando para siempre. No toca tareas vigentes.
     *
     * Efecto: UPDATE masivo en BD (status=error, http_status=500, mensaje en `response`,
     * current_step «Interrumpida», finished_at=ahora). Al ser update de query builder no pasa
     * por los casts, por eso `response` se codifica a JSON a mano.
     *
     * @param  int  $periodId  Periodo cuyas tareas abandonadas se cierran.
     */
    public static function closeAbandoned(int $periodId): void
    {
        static::query()->where('period_id', $periodId)
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING])
            ->where('queued_at', '<', now()->subSeconds(self::STALE_AFTER_SECONDS))
            ->update([
                'status' => self::STATUS_ERROR,
                'http_status' => 500,
                'response' => json_encode(['message' => 'La tarea no terminó (el proceso en segundo plano se detuvo). Vuelve a intentarlo; si se repite, avisa a Sistemas.'], JSON_UNESCAPED_UNICODE),
                'current_step' => 'Interrumpida',
                'finished_at' => now(),
            ]);
    }

    /**
     * Estado público para la pantalla (sin datos internos como `input`).
     *
     * @return array<string, mixed> id, tipo, estado, avance, paso actual, código HTTP, respuesta
     *                              (sólo si ya terminó) y tiempos en ISO-8601.
     */
    public function toStatusArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'finished' => $this->isFinished(),
            'progress_percent' => (int) $this->progress_percent,
            'current_step' => $this->current_step,
            'http_status' => $this->http_status,
            'response' => $this->isFinished() ? $this->response : null,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
