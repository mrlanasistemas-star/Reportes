<?php

namespace App\Services\Efectividad;

use App\Models\Period;

/**
 * Fase 7/11 — Orquesta Adaptador → Calculador aprobado y decide, con evidencia
 * real (nunca una bandera global), si el resultado de este periodo/sucursal es
 * válido para mostrarse/exportarse/exponerse por API.
 */
class EfectividadCobranzaAprobadaReportService
{
    private const AVAILABLE_STATUSES = ['ok', 'sin_ingresos_usables'];

    public function __construct(
        private readonly EfectividadCobranzaAprobadaDataAdapter $adapter,
        private readonly EfectividadCobranzaAprobadaCalculator $calculator,
    ) {}

    /**
     * @return array{period_id:int, branch_id:int|null, is_available:bool, coverage:array<string,mixed>, rows:array<int,array<string,mixed>>, generated_at:string}
     */
    public function generate(Period $period, ?int $branchId = null): array
    {
        $built = $this->adapter->build($period, $branchId);

        $rows = $this->calculator->calculate(
            $built['cartera'],
            $built['ingresos'],
            $built['fecha_ini'],
            $built['fecha_fin'],
        );

        $isAvailable = in_array($built['coverage']['status'], self::AVAILABLE_STATUSES, true);

        return [
            'period_id'    => $period->id,
            'branch_id'    => $branchId,
            'fecha_ini'    => $built['fecha_ini']->format('Y-m-d'),
            'fecha_fin'    => $built['fecha_fin']->format('Y-m-d'),
            'is_available' => $isAvailable,
            'coverage'     => $built['coverage'],
            'rows'         => $isAvailable ? $rows : [],
            'generated_at' => now()->toISOString(),
        ];
    }
}
