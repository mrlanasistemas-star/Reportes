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
    /** Estados de cobertura con los que el resultado se considera válido para mostrarse. */
    private const AVAILABLE_STATUSES = ['ok', 'sin_ingresos_usables'];

    /**
     * @param  EfectividadCobranzaAprobadaDataAdapter  $adapter  lee CARTERA e INGRESOS de la BD
     * @param  EfectividadCobranzaAprobadaCalculator  $calculator  motor aprobado (paridad con el VBA)
     */
    public function __construct(
        private readonly EfectividadCobranzaAprobadaDataAdapter $adapter,
        private readonly EfectividadCobranzaAprobadaCalculator $calculator,
    ) {}

    /**
     * Genera el reporte de efectividad aprobada del periodo (y sucursal opcional). Si la
     * cobertura no es suficiente, devuelve is_available = false y filas vacías (sin inventar).
     *
     * `$onProgress` se pasa tal cual al adaptador (conteos de cartera/ingresos).
     * `$onStage` recibe `'calculate'` justo antes de invocar al calculador aprobado
     * — solo informa el cambio de etapa; no altera entradas ni resultados.
     *
     * @param  Period  $period  periodo evaluado
     * @param  int|null  $branchId  sucursal (null = todas)
     * @param  callable|null  $onProgress  callback de progreso de lectura
     * @param  callable|null  $onStage  callback de cambio de etapa
     * @return array{period_id:int, branch_id:int|null, fecha_ini:string, fecha_fin:string, is_available:bool, coverage:array<string,mixed>, rows:array<int,array<string,mixed>>, generated_at:string}
     */
    public function generate(Period $period, ?int $branchId = null, ?callable $onProgress = null, ?callable $onStage = null): array
    {
        $built = $this->adapter->build($period, $branchId, $onProgress);

        $onStage && $onStage('calculate', $built['coverage']);

        $rows = $this->calculator->calculate(
            $built['cartera'],
            $built['ingresos'],
            $built['fecha_ini'],
            $built['fecha_fin'],
        );

        $isAvailable = in_array($built['coverage']['status'], self::AVAILABLE_STATUSES, true);

        return [
            'period_id' => $period->id,
            'branch_id' => $branchId,
            'fecha_ini' => $built['fecha_ini']->format('Y-m-d'),
            'fecha_fin' => $built['fecha_fin']->format('Y-m-d'),
            'is_available' => $isAvailable,
            'coverage' => $built['coverage'],
            'rows' => $isAvailable ? $rows : [],
            'generated_at' => now()->toISOString(),
        ];
    }
}
