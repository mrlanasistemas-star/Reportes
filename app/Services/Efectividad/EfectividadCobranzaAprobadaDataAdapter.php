<?php

namespace App\Services\Efectividad;

use App\Models\Period;
use App\Models\Portfolio;
use App\Models\Recovery;
use DateTimeImmutable;
use Illuminate\Support\LazyCollection;

/**
 * Fase 7 — Adaptador real: conecta `fact_portfolios` (CARTERA/Saldos por Cliente)
 * y `fact_recoveries` (INGRESOS/Ingresos Cobranza) con el
 * {@see EfectividadCobranzaAprobadaCalculator} ya aprobado, sin tocarlo.
 *
 * Reglas de esta capa (NO del motor, que ya está verificado contra el VBA):
 *  - CARTERA es un SNAPSHOT: solo las filas de fact_portfolios cuyo period_id
 *    es el periodo evaluado (igual que el VBA lee la hoja CARTERA tal como
 *    está en el Excel vigente, nunca histórico acumulado).
 *  - INGRESOS es HISTÓRICO COMPLETO: el calculador espera "_HIST_INGRESOS"
 *    completo (ver docstring de EfectividadCobranzaAprobadaCalculator::calculate),
 *    porque internamente filtra por fRecon dentro de [fechaIni,fechaFin]. Por
 *    eso aquí NUNCA se restringe fact_recoveries a un period_id: se manda todo
 *    el histórico ya importado en Reportería (coherente con la fuente real).
 *  - fechaIni/fechaFin se toman EXACTAMENTE de Period::start_date/end_date — la
 *    periodificación real de Reportería, nunca una conversión a mes calendario.
 *  - Nunca se inventa una columna que el Excel no trajo: si falta, el campo
 *    correspondiente llega como null al calculador (que ya sabe tratar null
 *    como "sin dato", igual que el VBA original).
 */
class EfectividadCobranzaAprobadaDataAdapter
{
    /**
     * @return array{cartera: array<int,array<string,mixed>>, ingresos: array<int,array<string,mixed>>, fecha_ini: DateTimeImmutable, fecha_fin: DateTimeImmutable, coverage: array<string,mixed>}
     */
    public function build(Period $period, ?int $branchId = null): array
    {
        if (!$period->start_date || !$period->end_date) {
            throw new \RuntimeException("El periodo #{$period->id} no tiene start_date/end_date definidos.");
        }

        $fechaIni = new DateTimeImmutable($period->start_date->format('Y-m-d'));
        $fechaFin = new DateTimeImmutable($period->end_date->format('Y-m-d'));

        $carteraQuery = Portfolio::query()->where('period_id', $period->id);
        if ($branchId) {
            $carteraQuery->where('branch_id', $branchId);
        }

        $ingresosQuery = Recovery::query();
        if ($branchId) {
            $ingresosQuery->where('branch_id', $branchId);
        }

        $cartera  = [];
        $coverage = $this->emptyCoverage();

        foreach ($this->chunkCursor($carteraQuery) as $row) {
            $coverage['cartera_rows_total']++;

            if ($row->contract === null || trim((string) $row->contract) === '') {
                $coverage['cartera_rows_sin_contrato']++;
                continue;
            }

            $estadoNormalizado = mb_strtoupper(trim((string) $row->status));
            $subestadoNormalizado = mb_strtoupper(trim((string) $row->substatus));
            if (
                str_contains($estadoNormalizado, 'CERRADO')
                || str_contains($estadoNormalizado, 'INACTIVO')
                || str_contains($subestadoNormalizado, 'INACTIVO')
            ) {
                $coverage['cartera_rows_excluidas_cerrado_inactivo']++;
            }

            if ($row->status === null && $row->substatus === null) {
                $coverage['cartera_rows_sin_estatus']++;
            }

            $cartera[] = $this->carteraRow($row);
        }

        $ingresos = [];
        foreach ($this->chunkCursor($ingresosQuery) as $row) {
            $coverage['ingresos_rows_total']++;

            $operacion = mb_strtoupper(trim((string) $row->operation));
            $transaccion = mb_strtoupper(trim((string) $row->transaction));
            $pasaWhitelist = ($operacion === 'PAGO A CONTRATO' && $transaccion === 'PAGO')
                || ($operacion === 'REFINANCIAMIENTO' && $transaccion === 'DESCUENTO');

            if ($pasaWhitelist) {
                $coverage['ingresos_rows_whitelist_pass']++;
            }

            if ($row->payment_date === null) {
                $coverage['ingresos_rows_sin_fecha_transaccion']++;
            }
            if ($row->installment_due_date === null) {
                $coverage['ingresos_rows_sin_fecha_cuota']++;
            }

            if ($pasaWhitelist && $row->payment_date !== null && $row->installment_due_date !== null) {
                $coverage['ingresos_rows_usables']++;
            }

            $ingresos[] = $this->ingresoRow($row);
        }

        $coverage['status']  = $this->resolveCoverageStatus($coverage);
        $coverage['message'] = $this->resolveCoverageMessage($coverage);

        return [
            'cartera'   => $cartera,
            'ingresos'  => $ingresos,
            'fecha_ini' => $fechaIni,
            'fecha_fin' => $fechaFin,
            'coverage'  => $coverage,
        ];
    }

    /** @return LazyCollection<int,mixed> */
    private function chunkCursor($query): LazyCollection
    {
        return $query->cursor();
    }

    private function carteraRow(Portfolio $row): array
    {
        return [
            'contrato'                        => $row->contract,
            'oficina'                          => $row->route_name,
            'promotor'                         => $row->promoter_name ?? $row->promoter_code,
            'estatus'                          => $row->status,
            'substatus'                        => $row->substatus,
            'fecha_vencimiento'                => $row->contract_due_date,
            'total_atrasado'                   => $row->total_overdue_raw,
            'pagos_atrasados'                  => $row->overdue_installments,
            'total_pendiente'                  => $row->total_pending_raw,
            'saldo_capital'                    => $row->saldo_capital_vencido,
            'saldo_interes'                    => $row->saldo_interes_vencido,
            'saldo_impuesto'                   => $row->saldo_impuesto_vencido,
            'saldo_cargo_calendario'           => $row->saldo_cargo_calendario_vencido,
            'saldo_impuesto_cargo_calendario'  => $row->saldo_impuesto_cargo_calendario_vencido,
            'cuota'                            => $row->installment_amount,
            'periodicidad'                     => $row->periodicity,
            'pagos'                            => $row->num_payments,
            'fecha_primer_pago'                => $row->first_payment_date,
            'fecha_siguiente_pago'             => $row->next_payment_date,
        ];
    }

    private function ingresoRow(Recovery $row): array
    {
        return [
            'contrato'           => $row->contract,
            'operacion'          => $row->operation,
            'transaccion'        => $row->transaction,
            'fecha_transaccion'  => $row->payment_date,
            'fecha_cuota'        => $row->installment_due_date,
            'capital'            => $row->capital,
            'interes'            => $row->interest,
            'impuesto'           => $row->tax,
            'cargos_calendario'  => $row->charges,
            'oficina'            => $row->office_name_raw,
            'promotor'           => $row->promoter_name,
            'acreditado'         => $row->acreditado,
            'numero_cuota'       => $row->installment_number,
            'fecha_vencimiento'  => $row->contract_due_date,
        ];
    }

    private function emptyCoverage(): array
    {
        return [
            'cartera_rows_total'                     => 0,
            'cartera_rows_sin_contrato'               => 0,
            'cartera_rows_sin_estatus'                => 0,
            'cartera_rows_excluidas_cerrado_inactivo' => 0,
            'ingresos_rows_total'                     => 0,
            'ingresos_rows_whitelist_pass'             => 0,
            'ingresos_rows_sin_fecha_transaccion'      => 0,
            'ingresos_rows_sin_fecha_cuota'            => 0,
            'ingresos_rows_usables'                    => 0,
            'status'                                   => 'sin_datos',
            'message'                                  => '',
        ];
    }

    private function resolveCoverageStatus(array $c): string
    {
        if ($c['cartera_rows_total'] === 0 && $c['ingresos_rows_total'] === 0) {
            return 'sin_datos';
        }

        if ($c['ingresos_rows_whitelist_pass'] > 0 && $c['ingresos_rows_usables'] === 0) {
            return 'bloqueado_sin_fechas';
        }

        if ($c['cartera_rows_total'] === 0) {
            return 'sin_cartera';
        }

        if ($c['ingresos_rows_usables'] === 0) {
            return 'sin_ingresos_usables';
        }

        return 'ok';
    }

    private function resolveCoverageMessage(array $c): string
    {
        return match ($c['status']) {
            'sin_datos' => 'No hay filas de Saldos por Cliente ni de Ingresos Cobranza importadas para este periodo/sucursal.',
            'sin_cartera' => 'No hay filas de Saldos por Cliente (CARTERA) importadas para este periodo/sucursal. El por-cobrar (Sano/Atraso/Vencido) no puede calcularse sin CARTERA.',
            'bloqueado_sin_fechas' => sprintf(
                'Se encontraron %d filas de Ingresos Cobranza que pasan la whitelist (PAGO A CONTRATO/PAGO o REFINANCIAMIENTO/DESCUENTO), pero NINGUNA trae a la vez Fecha transacción y Fecha cuota (%d sin fecha transacción, %d sin fecha cuota). La macro aprobada requiere ambas fechas para clasificar Sano/Atraso/Vencido y NUNCA se reconstruyen a partir de otra columna (p.ej. Días vencidos pago). El Excel de Ingresos Cobranza importado para este periodo no trae esas columnas.',
                $c['ingresos_rows_whitelist_pass'],
                $c['ingresos_rows_sin_fecha_transaccion'],
                $c['ingresos_rows_sin_fecha_cuota'],
            ),
            'sin_ingresos_usables' => 'Hay CARTERA importada, pero ninguna fila de Ingresos Cobranza es utilizable (whitelist + ambas fechas) para este periodo/sucursal. La cobranza calculada será 0 en todos los grupos.',
            default => sprintf(
                'Cobertura real: %d filas de CARTERA, %d filas de Ingresos (%d pasan whitelist, %d utilizables con ambas fechas).',
                $c['cartera_rows_total'],
                $c['ingresos_rows_total'],
                $c['ingresos_rows_whitelist_pass'],
                $c['ingresos_rows_usables'],
            ),
        };
    }
}
