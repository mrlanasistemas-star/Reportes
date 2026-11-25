<?php

namespace App\Services\EstadoResultados\Export;

use App\Models\Period;
use App\Services\EstadoResultados\FinancialExpenses\FinancialExpenseService;
use App\Services\EstadoResultados\Reporting\ReportStatus;
use Illuminate\Support\Facades\Log;

/**
 * Sección «Financiamientos / Gastos financieros» de las exportaciones (PDF y Excel) del Estado
 * de Resultados. FUENTE ÚNICA: FinancialExpenseService::panel() (que ya incluye allocation());
 * aquí NO se calcula ninguna cifra: sólo se ordena el payload y se agregan rótulos.
 *
 * Sólo aplica al alcance CONSOLIDADO (los créditos son de la empresa). Si el reporte se exporta
 * desde un snapshot aprobado, el snapshot no guarda estos datos: la sección se marca como
 * «dato vigente, informativo» y nunca altera las cifras aprobadas del ER.
 */
final class ErFinancingSection
{
    /** Rótulos del estado derivado de cada crédito. */
    public const CREDIT_STATUS = [
        'vigente' => 'Vigente',
        'por_iniciar' => 'Aún no inicia',
        'pago_no_localizado' => 'Pago no localizado',
        'pago_incompleto' => 'Faltan pagos por cubrir',
        'sin_pagos_programados' => 'Sin pagos programados',
        'posible_liquidacion' => 'Posible liquidación',
    ];

    /** Rótulos del estado de cada pago previsto contra el banco. */
    public const PAYMENT_STATUS = [
        'conciliado' => 'Conciliado',
        'parcial' => 'Parcial',
        'atrasado' => 'Atrasado',
        'adelantado' => 'Adelantado',
        'no_localizado' => 'No localizado',
        'no_previsto' => 'No previsto',
    ];

    /** Rótulos de la cobertura de cada pago con lo aplicado (FinancialExpenseService::applyFunds). */
    public const COVERAGE_STATUS = [
        'cubierto' => 'Cubierto con aportaciones',
        'parcial' => 'Parcial',
        'sin_cubrir' => 'Sin cubrir',
    ];

    /** Texto corto de la regla del pago aplicado (mismo en web, PDF y Excel). */
    public const APPLIED_NOTE = 'Aplicado = lo mayor entre lo aportado por las sucursales y lo pagado al acreedor (es el mismo dinero: no se suma dos veces); cubre los pagos en orden.';

    /** Rótulos de la cobertura de la tabla sobre el periodo. */
    public const COVERAGE = [
        FinancialExpenseService::COBERTURA_COMPLETA => 'Completa',
        FinancialExpenseService::COBERTURA_PARCIAL => 'Parcial',
        FinancialExpenseService::SIN_TABLA => 'Sin tabla',
        FinancialExpenseService::FUERA_CALENDARIO => 'Fuera de calendario',
    ];

    public const NOTE = 'Capital fuera del ER; distribución por sucursal es gerencial y no duplica el gasto consolidado.';

    /** Regla del reparto por sucursal (mismo texto en web, PDF, Excel y comando). */
    public const BRANCH_NOTE = 'A cada sucursal le toca lo que se debía pagar en el periodo × su participación en las aportaciones (al centavo). Diferencia = aportó − le toca: negativo, debe; positivo, a favor.';

    /** Rótulo de la situación de una sucursal frente a lo que le toca. */
    public static function standingLabel(?string $standing): string
    {
        return match ($standing) {
            'debe' => 'Debe',
            'a_favor' => 'A favor',
            'cubierto' => 'Cubierto',
            default => '—',
        };
    }

    public function __construct(private readonly FinancialExpenseService $service) {}

    /**
     * Payload de la sección o null si no aplica (alcance de sucursal, periodo inexistente, sin
     * tablas de amortización o sin créditos). Nunca lanza: si el servicio falla devuelve
     * ['error' => …] y la exportación sigue sin la sección.
     *
     * @param  array  $dataset  dataset resuelto del ER (sólo se usan meta.scope, meta.period y meta.report)
     * @return array<string,mixed>|null
     */
    public function build(array $dataset): ?array
    {
        $meta = (array) ($dataset['meta'] ?? []);
        if (! (bool) ($meta['scope']['consolidated'] ?? true) || ! FinancialExpenseService::available()) {
            return null;
        }
        $period = isset($meta['period']['id']) ? Period::query()->find($meta['period']['id']) : null;
        if (! $period) {
            return null;
        }
        try {
            $panel = $this->service->panel($period);
        } catch (\Throwable $e) {
            Log::warning('estado_resultados.export_financing_failed', ['period_id' => $period->id, 'error' => $e->getMessage()]);

            return ['error' => 'La sección de financiamientos no se pudo generar en este momento; el Estado de Resultados sí está completo.'];
        }
        if ($panel['credits'] === []) {
            return null;
        }
        $snapshot = ($meta['report']['source'] ?? null) === ReportStatus::SOURCE_SNAPSHOT;

        $allocation = (array) $panel['allocation'];
        $byCredit = collect($allocation['credits'] ?? [])->keyBy('credit_key');
        $credits = [];
        foreach ($panel['credits'] as $c) {
            $key = $c['credit']['key'];
            $cp = $c['creditor_payments'];
            $credits[] = [
                'key' => $key,
                'name' => $c['credit']['name'],
                'creditor' => $c['credit']['creditor'],
                'periodicity' => $c['credit']['periodicity'],
                'coverage' => $c['coverage']['status'],
                'coverage_label' => $c['status']['status'] === 'por_iniciar' ? 'Aún no inicia' : (self::COVERAGE[$c['coverage']['status']] ?? $c['coverage']['status']),
                'coverage_reason' => $c['coverage']['reason'],
                'covered' => (bool) $c['covered'],
                'totals' => $c['totals'],
                'recognized' => $c['recognized'],
                'vat' => $c['covered'] ? self::sum2($c['totals']['interest_vat'], $c['totals']['commission_vat']) : '0.00',
                'payments' => $cp,
                'application' => $c['application'],
                'contributions' => $c['contributions'],
                'status' => $c['status']['status'],
                'status_label' => self::CREDIT_STATUS[$c['status']['status']] ?? $c['status']['status'],
                'status_reason' => $c['status']['reason'],
                'status_tone' => match ($c['status']['status']) {
                    'vigente' => 'ok',
                    'por_iniciar' => 'mute',
                    'pago_no_localizado', 'pago_incompleto', 'posible_liquidacion' => 'bad',
                    default => 'warn',
                },
                'next_payment' => $c['status']['next_payment'],
                'first_payment' => $c['status']['first_payment'] ?? null,
                'document_balance' => $c['status']['document_balance'],
                'adjustment' => $c['adjustment'],
                'commission_adjustment' => $c['commission_adjustment'],
                'allocation' => $byCredit[$key] ?? null,
            ];
        }

        // Barras por sucursal del SERVICIO DE DEUDA: le toca / aportó / diferencia (allocation.by_branch)
        // y «Sin asignar» (aportaciones sin sucursal, o todo si no hubo aportaciones). value = diferencia.
        $bars = array_map(fn ($b) => [
            'label' => $b['branch'], 'value' => $b['difference'], 'due' => $b['due'], 'contributed' => $b['contributed'],
            'interest' => $b['interest'], 'assigned' => $b['assigned'], 'standing' => $b['standing'], 'unassigned' => false,
        ], array_values($allocation['by_branch'] ?? []));
        usort($bars, fn ($a, $b) => [(float) $a['value'], $a['label']] <=> [(float) $b['value'], $b['label']]);
        $unDue = (string) ($allocation['unassigned_due'] ?? '0.00');
        $unContrib = (string) ($allocation['unassigned_contributed'] ?? '0.00');
        if ((float) $unDue !== 0.0 || (float) $unContrib !== 0.0 || (float) ($allocation['unassigned'] ?? 0) !== 0.0) {
            $bars[] = ['label' => 'Sin asignar', 'value' => bcsub($unContrib, $unDue, 2), 'due' => $unDue, 'contributed' => $unContrib,
                'interest' => null, 'assigned' => (string) ($allocation['unassigned'] ?? '0.00'), 'standing' => null, 'unassigned' => true];
        }
        $expected = (float) ($allocation['expected'] ?? 0);
        foreach ($bars as &$bar) {
            // Parte de lo que se debía pagar (presentación del mismo dato; no es otro cálculo de negocio).
            $bar['share'] = $expected > 0 ? round((float) $bar['due'] / $expected * 100, 2) : null;
        }
        unset($bar);

        return [
            'period' => $panel['period'],
            'summary' => $panel['summary'],
            'totals' => $panel['totals'],
            'credits' => $credits,
            'unassigned_contributions' => $panel['contributions_unassigned'],
            'allocation' => $allocation,
            'bars' => $bars,
            'allocation_ok' => collect($allocation['credits'] ?? [])->every(fn ($c) => (bool) ($c['check']['ok'] ?? false) && (bool) ($c['debt_service']['ok'] ?? true)),
            'commissions_in_result' => (bool) $panel['summary']['commissions_in_financial_result'],
            'locked' => $panel['locked'],
            'informative' => $snapshot,
            'notice' => $snapshot
                ? 'Dato vigente, informativo: la versión aprobada no guarda el detalle de financiamientos. Las cifras aprobadas del Estado de Resultados no cambian.'
                : null,
            'note' => self::NOTE,
        ];
    }

    /** Rótulo de la cobertura de un pago; «Cubierto con aportaciones» sólo si el aplicado salió de ellas. */
    public static function coverageLabel(?string $coverage, ?string $basis): string
    {
        if ($coverage === 'cubierto' && $basis !== 'aportaciones') {
            return 'Cubierto';
        }

        return self::COVERAGE_STATUS[$coverage] ?? '—';
    }

    /** Suma de dos importes en texto (centavos exactos) para el IVA separado de un crédito. */
    private static function sum2(?string $a, ?string $b): string
    {
        return bcadd((string) ($a ?? '0'), (string) ($b ?? '0'), 2);
    }
}
