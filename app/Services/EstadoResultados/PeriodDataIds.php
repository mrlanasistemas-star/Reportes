<?php

namespace App\Services\EstadoResultados;

use App\Models\Period;
use App\Services\Radiography\RadiographySnapshotBuilder;

/**
 * Universo de period_id (semanas base + el propio periodo) EXACTAMENTE igual al
 * que usa Radiografía — delega en RadiographySnapshotBuilder::resolveDataIdsPublic()
 * (método público, consumido sin cambiar su semántica). Mismo universo ⇒ el
 * EBITDA de sucursales del ER es conciliable a $0.00 contra Radiografía.
 */
class PeriodDataIds
{
    private array $cache = [];

    public function for(Period $period): array
    {
        return $this->cache[$period->id] ??= app(RadiographySnapshotBuilder::class)->resolveDataIdsPublic($period);
    }
}
