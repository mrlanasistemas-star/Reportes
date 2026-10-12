<?php

namespace App\Services\Efectividad;

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
    /** @var array<string,array<string,mixed>> */
    private array $stages;

    private ?EfectividadCobranzaStage $current = null;

    private int $lastWrittenCount = 0;

    private float $lastWriteAt = 0.0;

    public function __construct(private readonly EfectividadCobranzaRun $run)
    {
        $this->stages = $run->stages ?? [];
    }

    public function run(): EfectividadCobranzaRun
    {
        return $this->run;
    }

    public function start(EfectividadCobranzaStage $stage, ?int $total = null, ?string $detail = null): void
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
     * @param  array<string,mixed>  $extra  conteos/resumen adicionales para el detalle
     */
    public function complete(EfectividadCobranzaStage $stage, ?string $summary = null, array $extra = [], ?int $processed = null): void
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
    public function summarize(EfectividadCobranzaStage $stage, string $summary): void
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

    public function current(): ?EfectividadCobranzaStage
    {
        return $this->current;
    }
}
