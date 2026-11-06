<?php

namespace App\Services\EstadoResultados\Reporting;

use App\Models\EstadoResultados\EstadoResultadosVersion;
use App\Models\Period;
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

    /** Sin la migración aplicada no puede existir ninguna versión aprobada. */
    private function available(): bool
    {
        if (app()->runningUnitTests()) {
            return Schema::hasTable('estado_resultados_versions');
        }

        return self::$tableExists ??= Schema::hasTable('estado_resultados_versions');
    }
}
