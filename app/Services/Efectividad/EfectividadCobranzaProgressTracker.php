<?php

namespace App\Services\Efectividad;

use App\Enums\EfectividadAprobadaStage;
use App\Enums\EfectividadCobranzaStage;
use App\Models\EfectividadCobranzaRun;

/**
 * Persiste en la BD la etapa real, el avance medible y la última actividad
 * (heartbeat) de una ejecución de Efectividad de Cobranza.
 *
 *  - Cambios de etapa: se escriben siempre (son pocos: 8 por ejecución).
 *  - Avance dentro de una etapa medible: se escribe por bloques — cada
 *    `efectividad.progress_every_rows` registros o cada
 *    `efectividad.progress_every_seconds` segundos, lo que ocurra primero.
 *    Nunca una escritura por registro.
 *
 * La línea de tiempo (`stages`) conserva por etapa: estado, inicio, fin,
 * registros procesados/totales y un resumen legible — es lo que ve el usuario
 * en el detalle de la ejecución, también después de terminar.
 */
class EfectividadCobranzaProgressTracker
{
    /** @var array<string,array<string,mixed>> línea de tiempo por etapa (se guarda en runs.stages) */
    private array $stages;

    /** Etapa en curso. */
    private EfectividadCobranzaStage|EfectividadAprobadaStage|null $current = null;

    /** Último conteo escrito en BD (para escribir por bloques). */
    private int $lastWrittenCount = 0;

    /** Momento (microtime) de la última escritura de avance. */
    private float $lastWriteAt = 0.0;

    /** @param  EfectividadCobranzaRun  $run  ejecución cuyo progreso se persiste */
    public function __construct(private readonly EfectividadCobranzaRun $run)
    {
        $this->stages = $run->stages ?? [];
    }

    /**
     * Ejecución asociada.
     */
    public function run(): EfectividadCobranzaRun
    {
        return $this->run;
    }

    /**
     * Inicia una etapa: la marca «running» en la línea de tiempo y actualiza etapa actual,
     * inicio, heartbeat, avance (0/total si es medible) y log de la ejecución (guarda en BD).
     *
     * @param  EfectividadCobranzaStage|EfectividadAprobadaStage  $stage  etapa
     * @param  int|null  $total  registros totales (null = etapa no medible)
     * @param  string|null  $detail  resumen inicial
     */
    public function start(EfectividadCobranzaStage|EfectividadAprobadaStage $stage, ?int $total = null, ?string $detail = null): void
    {
        $this->current = $stage;
        $this->lastWrittenCount = 0;
        $this->lastWriteAt = microtime(true);

        $this->stages[$stage->value] = [
            'status' => 'running',
            'started_at' => now()->toISOString(),
            'finished_at' => null,
            'processed' => $total !== null ? 0 : null,
            'total' => $total,
            'summary' => $detail,
        ];

        $this->run->forceFill([
            'current_stage' => $stage->value,
            'stage_started_at' => now(),
            'heartbeat_at' => now(),
            'progress_current' => $total !== null ? 0 : null,
            'progress_total' => $total,
            'stages' => $this->stages,
            'log' => $stage->description(),
        ])->save();
    }

    /**
     * Avance medible de la etapa actual. Escribe solo cada N registros / N
     * segundos; el último valor siempre se persiste en complete().
     *
     * @param  int  $processed  registros procesados hasta ahora
     */
    public function advance(int $processed): void
    {
        if (! $this->current) {
            return;
        }

        $everyRows = max(1, (int) config('efectividad.progress_every_rows', 5000));
        $everySeconds = max(1, (int) config('efectividad.progress_every_seconds', 3));

        $dueByRows = ($processed - $this->lastWrittenCount) >= $everyRows;
        $dueByTime = (microtime(true) - $this->lastWriteAt) >= $everySeconds;

        if (! $dueByRows && ! $dueByTime) {
            return;
        }

        $this->lastWrittenCount = $processed;
        $this->lastWriteAt = microtime(true);
        $this->stages[$this->current->value]['processed'] = $processed;

        // Escritura mínima (2 columnas) — sin re-serializar la línea de tiempo.
        EfectividadCobranzaRun::query()->whereKey($this->run->id)->update([
            'progress_current' => $processed,
            'heartbeat_at' => now(),
        ]);
        $this->run->progress_current = $processed;
    }

    /** Actividad sin avance cuantificable (p.ej. antes/después de una llamada larga). */
    public function heartbeat(): void
    {
        EfectividadCobranzaRun::query()->whereKey($this->run->id)->update(['heartbeat_at' => now()]);
    }

    /**
     * Cierra una etapa como «done» (fin, procesados, resumen y detalles) y guarda la línea de
     * tiempo y el heartbeat.
     *
     * @param  EfectividadCobranzaStage|EfectividadAprobadaStage  $stage  etapa
     * @param  string|null  $summary  resumen legible
     * @param  array<string,mixed>  $extra  conteos/resumen adicionales para el detalle
     * @param  int|null  $processed  registros procesados finales
     */
    public function complete(EfectividadCobranzaStage|EfectividadAprobadaStage $stage, ?string $summary = null, array $extra = [], ?int $processed = null): void
    {
        $entry = $this->stages[$stage->value] ?? ['started_at' => now()->toISOString()];
        $entry['status'] = 'done';
        $entry['finished_at'] = now()->toISOString();
        if ($processed !== null) {
            $entry['processed'] = $processed;
        }
        if ($summary !== null) {
            $entry['summary'] = $summary;
        }
        if ($extra !== []) {
            $entry['details'] = $extra;
        }
        $this->stages[$stage->value] = $entry;

        $this->run->forceFill([
            'heartbeat_at' => now(),
            'progress_current' => $processed ?? $this->run->progress_current,
            'stages' => $this->stages,
        ])->save();
    }

    /** Reemplaza el resumen de una etapa ya registrada (p.ej. con conteos conocidos después). */
    public function summarize(EfectividadCobranzaStage|EfectividadAprobadaStage $stage, string $summary): void
    {
        if (! isset($this->stages[$stage->value])) {
            return;
        }

        $this->stages[$stage->value]['summary'] = $summary;
        $this->run->forceFill(['stages' => $this->stages])->save();
    }

    /** Marca la etapa actual como detenida (bloqueo por datos o error técnico). */
    public function halt(string $status, ?string $summary = null): void
    {
        if (! $this->current) {
            return;
        }

        $entry = $this->stages[$this->current->value] ?? ['started_at' => now()->toISOString()];
        $entry['status'] = $status; // blocked | failed
        $entry['finished_at'] = now()->toISOString();
        if ($summary !== null) {
            $entry['summary'] = $summary;
        }
        $this->stages[$this->current->value] = $entry;

        $this->run->forceFill(['stages' => $this->stages])->save();
    }

    /**
     * Etapa en curso (null si no se ha iniciado ninguna).
     */
    public function current(): EfectividadCobranzaStage|EfectividadAprobadaStage|null
    {
        return $this->current;
    }
}
