<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Schema;

/**
 * Las fotografías diarias de cartera (Efectividad V4) se guardan como
 * `report_uploads` con `snapshot_date` para reutilizar almacenamiento,
 * importador y bitácora de procesamiento. El flujo mensual de la radiografía
 * (Histórico General, "Cargar registros", reemplazo por periodo+fuente,
 * reprocesos, cierre de periodo) asume UN archivo vigente por fuente y periodo:
 * si viera varias fotografías marcaría todas menos una como reemplazadas. Por
 * eso este scope las oculta a TODA consulta existente; solo el código de
 * Efectividad V4 las consulta, explícitamente con `ReportUpload::snapshots()`.
 */
class ExcludePortfolioSnapshotUploadsScope implements Scope
{
    public const NAME = 'exclude_portfolio_snapshot_uploads';

    /** Solo se memoriza el resultado positivo: antes de migrar, el scope no hace nada. */
    private static bool $columnaExiste = false;

    public function apply(Builder $builder, Model $model): void
    {
        if (! self::$columnaExiste) {
            self::$columnaExiste = Schema::connection($model->getConnectionName())->hasColumn($model->getTable(), 'snapshot_date');
            if (! self::$columnaExiste) {
                return;
            }
        }
        $builder->whereNull($model->qualifyColumn('snapshot_date'));
    }
}
