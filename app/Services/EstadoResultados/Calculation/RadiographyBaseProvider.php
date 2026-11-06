<?php

namespace App\Services\EstadoResultados\Calculation;

use App\Models\Period;
use App\Services\EstadoResultados\PeriodDataIds;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Support\Facades\DB;

/**
 * Base de Radiografía para el nivel 1 (EBITDA sucursales) del Estado de Resultados.
 *
 * Llama EXACTAMENTE al mismo camino que RadiographySnapshotBuilder::build():
 *   BranchRadiographyCalculator::buildBranches($period, $dataIds, [], $closingDataIds)
 *   → sumGlobal($branches, $unassigned)
 * y a los métodos estáticos canónicos (ingresoEbitdaBaseFor/nominaTotalFor/ebitdaFinalFor).
 * No replica ni altera ninguna regla: si Radiografía cambia, el ER la sigue.
 */
class RadiographyBaseProvider
{
    private array $cache = [];

    public function __construct(
        private readonly BranchRadiographyCalculator $calculator,
        private readonly PeriodDataIds $dataIds,
    ) {}

    /**
     * @return array{global: array, branches: array<string,array>, unassigned: array, cartera: array, rotacion: array, data_ids: list<int>}
     */
    public function for(Period $period): array
    {
        return $this->cache[$period->id] ??= $this->compute($period);
    }

    private function compute(Period $period): array
    {
        $dataIds = $this->dataIds->for($period);
        // Periodos mensuales: el cierre (cartera/mora) usa los mismos dataIds (ver
        // RadiographySnapshotBuilder::resolveClosingDataIds()).
        $result = $this->calculator->buildBranches($period, $dataIds, [], $dataIds);
        $global = $this->calculator->sumGlobal($result['branches'], $result['unassigned']);

        $branches = [];
        foreach ($result['branches'] as $b) {
            $branches[(string) $b['sucursal']] = $b;
        }

        return [
            'global' => $global,
            'branches' => $branches,
            'unassigned' => $result['unassigned'],
            'cartera' => $this->calculator->computeCarteraGlobalSinFiltro($dataIds),
            'rotacion' => $this->rotacion($period),
            'data_ids' => $dataIds,
        ];
    }

    /**
     * Plantilla/rotación (informativo) — misma fuente única que Radiografía
     * (RadiographySnapshotBuilder::buildRotationData): fact_rotacion con source='derived_noi'.
     */
    private function rotacion(Period $period): array
    {
        $rows = DB::table('fact_rotacion')->where('period_id', $period->id)->where('source', 'derived_noi')->get(['sucursal_nombre', 'altas', 'bajas', 'promedio_personal']);
        $plantilla = (float) $rows->sum('promedio_personal');
        $bajas = (int) $rows->sum('bajas');

        return [
            'disponible' => $rows->isNotEmpty(),
            'altas' => (int) $rows->sum('altas'),
            'bajas' => $bajas,
            'plantilla' => $plantilla,
            'indice' => $plantilla > 0 ? round($bajas / $plantilla * 100, 2) : null,
            'por_sucursal' => $rows->mapWithKeys(fn ($r) => [mb_strtoupper((string) $r->sucursal_nombre) => [
                'altas' => (int) $r->altas, 'bajas' => (int) $r->bajas, 'plantilla' => (float) $r->promedio_personal,
            ]])->all(),
        ];
    }
}
