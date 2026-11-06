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
    /**
     * Sin caché por instancia (fase 2.4): el universo depende de la tabla periods
     * (semanas base) y un servicio reutilizado —worker de cola, procesador— podía
     * seguir usando un universo viejo. Es una sola consulta; quien necesite reutilizar
     * el resultado en una operación lo hace a través de RadiographyBaseProvider::session().
     */
    public function for(Period $period): array
    {
        return app(RadiographySnapshotBuilder::class)->resolveDataIdsPublic($period);
    }
}
