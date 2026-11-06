<?php

namespace App\Services\EstadoResultados;

use App\Models\EstadoResultados\EstadoResultadosAdjustment;
use App\Models\EstadoResultados\EstadoResultadosAdjustmentLog;
use App\Models\Period;
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
 */
class AdjustmentService
{
    public const SIN_INFORMACION = 'sin_informacion';

    public const CONFIRMADO_CERO = 'confirmado_cero';

    public const CAPTURADO = 'capturado';

    public const STATES = [self::SIN_INFORMACION, self::CONFIRMADO_CERO, self::CAPTURADO];

    /** concepto => [label, sección, efecto, ¿afecta el resultado?] */
    public const CONCEPTS = [
        'depreciacion' => ['label' => 'Depreciación de activos', 'section' => 'da', 'effect' => 'Disminuye EBIT', 'affects' => true],
        'amortizacion' => ['label' => 'Amortización (intangibles / sistemas propios)', 'section' => 'da', 'effect' => 'Disminuye EBIT', 'affects' => true],
        'intereses_bbva' => ['label' => 'Intereses bancarios BBVA', 'section' => 'financiero', 'effect' => 'Disminuye EBT', 'affects' => true],
        'intereses_lerom' => ['label' => 'Intereses LEROM', 'section' => 'financiero', 'effect' => 'Disminuye EBT', 'affects' => true],
        'otros_intereses' => ['label' => 'Otros intereses', 'section' => 'financiero', 'effect' => 'Disminuye EBT', 'affects' => true],
        'otros_gastos_financieros' => ['label' => 'Otros gastos financieros / no operativos', 'section' => 'financiero', 'effect' => 'Disminuye EBT', 'affects' => true],
        'ingresos_financieros' => ['label' => 'Ingresos financieros / no operativos', 'section' => 'financiero', 'effect' => 'Aumenta EBT', 'affects' => true],
        'isr' => ['label' => 'ISR causado del periodo', 'section' => 'isr', 'effect' => 'Disminuye utilidad neta', 'affects' => true],
        'capex' => ['label' => 'CAPEX pagado', 'section' => 'flujo', 'effect' => 'Flujo de inversión (no afecta resultado)', 'affects' => false],
        'capital_deuda' => ['label' => 'Capital de deuda pagado', 'section' => 'flujo', 'effect' => 'Flujo de financiamiento (no afecta resultado)', 'affects' => false],
    ];

    /** @return array<string, array<string,mixed>> */
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
            ];
        }

        return $out;
    }

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

    /** @return list<array<string,mixed>> */
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
