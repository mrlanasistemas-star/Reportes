<?php

namespace App\Services\EstadoResultados\Reporting;

use App\Models\EstadoResultados\EstadoResultadosVersion;
use App\Models\Period;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ¿El periodo está cerrado por una versión aprobada vigente? Lo consultan TODOS
 * los puntos que modifican datos del ER (carga, procesamiento, clasificación,
 * ajustes) — también fuera de HTTP, en los servicios.
 */
class PeriodLockGuard
{
    private static ?bool $tableExists = null;

    public function latest(Period|int $period): ?EstadoResultadosVersion
    {
        if (! $this->available()) {
            return null;
        }

        return EstadoResultadosVersion::query()
            ->where('period_id', $period instanceof Period ? $period->id : $period)
            ->orderByDesc('version')
            ->first();
    }

    public function lockedVersion(Period|int $period): ?EstadoResultadosVersion
    {
        $latest = $this->latest($period);

        return $latest && $latest->isLocked() ? $latest : null;
    }

    public function isLocked(Period|int $period): bool
    {
        return $this->lockedVersion($period) !== null;
    }

    /** @throws EstadoResultadosLockedException */
    public function assertUnlocked(Period $period): void
    {
        if ($version = $this->lockedVersion($period)) {
            throw new EstadoResultadosLockedException($version, $period->label);
        }
    }

    /**
     * Para llamar DENTRO de la transacción de una escritura del ER: bloquea la fila del
     * periodo FOR UPDATE (el mismo bloqueo que toma la aprobación) y vuelve a revisar el
     * candado de versión. Cierra la carrera "revisé que estaba abierto → otro aprobó →
     * escribí sobre un periodo ya aprobado": o la escritura termina antes de que la
     * aprobación lea sus insumos, o espera y ve el periodo cerrado (HTTP 423).
     *
     * @throws EstadoResultadosLockedException
     */
    public function lockForWrite(Period $period): void
    {
        DB::table('periods')->where('id', $period->id)->lockForUpdate()->first();
        $this->assertUnlocked($period);
    }

    /** Sin la migración aplicada no puede existir ninguna versión aprobada. */
    private function available(): bool
    {
        if (app()->runningUnitTests()) {
            return Schema::hasTable('estado_resultados_versions');
        }

        return self::$tableExists ??= Schema::hasTable('estado_resultados_versions');
    }
}
