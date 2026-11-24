<?php

namespace App\Services\EstadoResultados;

use App\Models\EstadoResultados\EstadoResultadosAdjustment;
use App\Models\EstadoResultados\EstadoResultadosAdjustmentLog;
use App\Models\Period;
use App\Services\EstadoResultados\FinancialExpenses\FinancialExpenseService;
use App\Services\EstadoResultados\Reporting\PeriodLockGuard;
use App\Services\EstadoResultados\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ajustes contables TIPIFICADOS por periodo y alcance (no existe un "ajuste" genérico).
 *
 * Tres estados distintos — un vacío NUNCA se asume $0:
 *   sin_informacion  → no hay dato; el subtotal afectado se marca "incompleto".
 *   confirmado_cero  → alguien confirmó que el concepto fue $0.00.
 *   capturado        → importe capturado (≥ 0; el signo lo da la sección).
 *
 * Origen (20-nov-2026): `origin` = manual | tabla_amortizacion. Los intereses de crédito
 * (intereses_lerom / intereses_bbva / otros_intereses) se alimentan en el consolidado desde
 * la tabla de amortización (FinancialExpenseService::overlay) con estado «capturado» y origen
 * «tabla_amortizacion»; `amortization` trae el detalle (pagos, capital excluido, diferencia).
 *
 * Lo usan EstadoResultadosDatasetBuilder (D&A, resultado financiero, ISR) y el controlador del
 * ER (pestaña «Ajustes»: captura y bitácora).
 */
class AdjustmentService
{
    /** Sin dato: el subtotal afectado queda incompleto (nunca se asume $0). */
    public const SIN_INFORMACION = 'sin_informacion';

    /** Confirmado explícitamente en $0.00. */
    public const CONFIRMADO_CERO = 'confirmado_cero';

    /** Importe capturado (positivo; la sección define el signo). */
    public const CAPTURADO = 'capturado';

    /** Estados válidos de un ajuste. */
    public const STATES = [self::SIN_INFORMACION, self::CONFIRMADO_CERO, self::CAPTURADO];

    /** concepto => [label, sección, efecto, ¿afecta el resultado?] */
    public const CONCEPTS = [
        'depreciacion' => ['label' => 'Depreciación de activos', 'section' => 'da', 'effect' => 'Disminuye EBIT', 'affects' => true],
        'amortizacion' => ['label' => 'Amortización (intangibles / sistemas propios)', 'section' => 'da', 'effect' => 'Disminuye EBIT', 'affects' => true],
        'intereses_bbva' => ['label' => 'Intereses bancarios BBVA', 'section' => 'financiero', 'effect' => 'Disminuye EBT', 'affects' => true],
        'intereses_lerom' => ['label' => 'Intereses LEROM', 'section' => 'financiero', 'effect' => 'Disminuye EBT', 'affects' => true],
        'otros_intereses' => ['label' => 'Otros intereses', 'section' => 'financiero', 'effect' => 'Disminuye EBT', 'affects' => true],
        // 24-nov-2026: comisión financiera de la tabla (LEROM). Criterio pendiente de confirmar por Finanzas.
        'comisiones_financieras' => ['label' => 'Comisiones financieras', 'section' => 'financiero', 'effect' => 'Disminuye EBT', 'affects' => true],
        'otros_gastos_financieros' => ['label' => 'Otros gastos financieros / no operativos', 'section' => 'financiero', 'effect' => 'Disminuye EBT', 'affects' => true],
        'ingresos_financieros' => ['label' => 'Ingresos financieros / no operativos', 'section' => 'financiero', 'effect' => 'Aumenta EBT', 'affects' => true],
        'isr' => ['label' => 'ISR causado del periodo', 'section' => 'isr', 'effect' => 'Disminuye utilidad neta', 'affects' => true],
        'capex' => ['label' => 'CAPEX pagado', 'section' => 'flujo', 'effect' => 'Flujo de inversión (no afecta resultado)', 'affects' => false],
        'capital_deuda' => ['label' => 'Capital de deuda pagado', 'section' => 'flujo', 'effect' => 'Flujo de financiamiento (no afecta resultado)', 'affects' => false],
    ];

    /**
     * Ajustes del periodo/alcance para TODOS los conceptos del catálogo (los no capturados salen
     * como sin_informacion con importe null) y, en el consolidado, los intereses alimentados por
     * la tabla de amortización cuando no hay captura manual (FinancialExpenseService::overlay).
     *
     * @param  int  $periodId  periodo
     * @param  string  $scopeKey  alcance (CONSOLIDADO o sucursal)
     * @return array<string, array<string,mixed>> concepto ⇒ meta + state, amount, notes, origin, amortization
     */
    public function forPeriod(int $periodId, string $scopeKey): array
    {
        $rows = EstadoResultadosAdjustment::query()
            ->with('editor:id,name')
            ->where('period_id', $periodId)
            ->where('scope_key', $scopeKey)
            ->get()
            ->keyBy('concept');

        $out = [];
        foreach (self::CONCEPTS as $key => $meta) {
            $row = $rows->get($key);
            $state = $row?->state ?? self::SIN_INFORMACION;
            $out[$key] = $meta + [
                'key' => $key,
                'state' => $state,
                'amount' => $state === self::CAPTURADO ? Money::str2($row->amount) : ($state === self::CONFIRMADO_CERO ? '0.00' : null),
                'notes' => $row?->notes,
                'updated_by' => $row?->editor?->name,
                'updated_at' => $row?->updated_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
                // manual = captura en Ajustes · tabla_amortizacion = interés de la tabla del crédito.
                'origin' => $row ? FinancialExpenseService::ORIGIN_MANUAL : null,
                'amortization' => null,
            ];
        }

        // Intereses LEROM/BBVA/otros: la tabla de amortización alimenta el concepto en el
        // CONSOLIDADO cuando no hay captura manual (la captura manual gana y se muestra la diferencia).
        return app(FinancialExpenseService::class)->overlay($periodId, $scopeKey, $out);
    }

    /**
     * Guarda (crea o actualiza) un ajuste y registra el cambio en la bitácora, dentro de una
     * transacción con la fila del periodo bloqueada (re-verifica que no esté aprobado).
     *
     * @param  Period  $period  periodo
     * @param  string  $scopeKey  alcance
     * @param  string  $concept  concepto de CONCEPTS
     * @param  string  $state  estado de STATES
     * @param  mixed  $amount  importe (sólo con «capturado»; debe ser ≥ 0)
     * @param  string|null  $notes  notas del cambio
     * @param  int|null  $userId  usuario que edita
     *
     * @throws \InvalidArgumentException concepto/estado no válido o importe vacío/negativo
     * @throws Reporting\EstadoResultadosLockedException si el periodo está aprobado (423)
     */
    public function save(Period $period, string $scopeKey, string $concept, string $state, mixed $amount, ?string $notes, ?int $userId): EstadoResultadosAdjustment
    {
        app(PeriodLockGuard::class)->assertUnlocked($period); // versión aprobada ⇒ HTTP 423
        if (! isset(self::CONCEPTS[$concept])) {
            throw new \InvalidArgumentException('Concepto de ajuste no válido.');
        }
        if (! in_array($state, self::STATES, true)) {
            throw new \InvalidArgumentException('Estado de ajuste no válido.');
        }
        $value = null;
        if ($state === self::CAPTURADO) {
            $parsed = Money::tryOf($amount);
            if ($parsed === null || $amount === null || $amount === '') {
                throw new \InvalidArgumentException('Captura un importe válido.');
            }
            if ($parsed->isNegative()) {
                throw new \InvalidArgumentException('Captura el importe en positivo; la sección define si suma o resta.');
            }
            $value = Money::str2($parsed);
        }

        return DB::transaction(function () use ($period, $scopeKey, $concept, $state, $value, $notes, $userId) {
            app(PeriodLockGuard::class)->lockForWrite($period); // re-verificación con la fila del periodo bloqueada
            $adj = EstadoResultadosAdjustment::query()->lockForUpdate()->firstOrNew([
                'period_id' => $period->id, 'scope_key' => $scopeKey, 'concept' => $concept,
            ]);
            $oldState = $adj->exists ? $adj->state : null;
            $oldAmount = $adj->exists ? $adj->amount : null;

            $adj->fill(['state' => $state, 'amount' => $value, 'notes' => $notes, 'updated_by' => $userId])->save();

            EstadoResultadosAdjustmentLog::query()->create([
                'adjustment_id' => $adj->id,
                'old_state' => $oldState,
                'old_amount' => $oldAmount,
                'new_state' => $state,
                'new_amount' => $value,
                'notes' => $notes,
                'user_id' => $userId,
            ]);

            return $adj;
        });
    }

    /**
     * Bitácora de cambios de ajustes del periodo/alcance (últimos 200, más reciente primero).
     *
     * @param  int  $periodId  periodo
     * @param  string  $scopeKey  alcance
     * @return list<array<string,mixed>>
     */
    public function logs(int $periodId, string $scopeKey): array
    {
        return EstadoResultadosAdjustmentLog::query()
            ->join('estado_resultados_adjustments as a', 'a.id', '=', 'estado_resultados_adjustment_logs.adjustment_id')
            ->leftJoin('users as u', 'u.id', '=', 'estado_resultados_adjustment_logs.user_id')
            ->where('a.period_id', $periodId)
            ->where('a.scope_key', $scopeKey)
            ->orderByDesc('estado_resultados_adjustment_logs.id')
            ->limit(200)
            ->get(['a.concept', 'estado_resultados_adjustment_logs.*', 'u.name as user_name'])
            ->map(fn ($l) => [
                'concept' => $l->concept,
                'label' => self::CONCEPTS[$l->concept]['label'] ?? $l->concept,
                'old_state' => $l->old_state,
                'old_amount' => $l->old_amount !== null ? Money::str2($l->old_amount) : null,
                'new_state' => $l->new_state,
                'new_amount' => $l->new_amount !== null ? Money::str2($l->new_amount) : null,
                'notes' => $l->notes,
                'user' => $l->user_name,
                'at' => Carbon::parse($l->created_at)->timezone('America/Mexico_City')->format('d/m/Y H:i'),
            ])->all();
    }
}
