<?php

namespace App\Services\EstadoResultados\Classification;

use App\Models\EstadoResultados\EstadoResultadosClassification;
use App\Models\Period;
use App\Services\EstadoResultados\Reporting\PeriodLockGuard;
use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\Treatment;
use Illuminate\Support\Facades\DB;

/**
 * Clasificación MANUAL de movimientos. Nunca modifica el dato original
 * (estado_resultados_movements / fact_expenses): escribe una fila nueva en la
 * bitácora append-only; la clasificación vigente es la más reciente por huella.
 */
class ClassificationService
{
    /**
     * Clasificación manual VIGENTE por huella del periodo (la más reciente gana) con el número
     * de cambios registrados para esa huella.
     *
     * @param  int  $periodId  periodo
     * @return array<string, array{treatment:string, previous:?string, notes:string, user:?string, at:?string, history_count:int}>
     */
    public function effectiveOverrides(int $periodId): array
    {
        $rows = EstadoResultadosClassification::query()
            ->with('user:id,name')
            ->where('period_id', $periodId)
            ->orderBy('id')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $prev = $out[$r->item_fingerprint]['history_count'] ?? 0;
            $out[$r->item_fingerprint] = [
                'treatment' => $r->treatment,
                'previous' => $r->previous_treatment,
                'notes' => $r->notes,
                'user' => $r->user?->name,
                'at' => $r->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
                'history_count' => $prev + 1,
                // «Ya considerado» parcial: parte del importe fuera del ER (el resto va a 	reatment).
                'considered_amount' => $r->considered_amount !== null ? Money::str2($r->considered_amount) : null,
                'considered_reason' => $r->considered_reason,
            ];
        }

        return $out;
    }

    /**
     * Historial de clasificaciones manuales de un movimiento (más reciente primero).
     *
     * @param  int  $periodId  periodo
     * @param  string  $fingerprint  huella del movimiento
     * @return list<array<string,mixed>>
     */
    public function history(int $periodId, string $fingerprint): array
    {
        return EstadoResultadosClassification::query()
            ->with('user:id,name')
            ->where('period_id', $periodId)
            ->where('item_fingerprint', $fingerprint)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($r) => [
                'treatment' => $r->treatment,
                'treatment_label' => Treatment::label($r->treatment),
                'previous_treatment' => $r->previous_treatment,
                'previous_label' => $r->previous_treatment ? Treatment::label($r->previous_treatment) : null,
                'notes' => $r->notes,
                'user' => $r->user?->name,
                'at' => $r->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
                'considered_amount' => $r->considered_amount !== null ? Money::str2($r->considered_amount) : null,
                'considered_reason' => $r->considered_reason,
                'considered_reason_label' => $r->considered_reason ? (Treatment::CONSIDERED_REASONS[$r->considered_reason] ?? $r->considered_reason) : null,
            ])->all();
    }

    /**
     * Clasifica manualmente uno o varios movimientos: agrega una fila a la bitácora por cada
     * item cuyo tratamiento actual sea distinto (los que no cambian se omiten). Transacción con
     * la fila del periodo bloqueada.
     *
     * @param  Period  $period  periodo
     *                          «Ya considerado» (25-nov-2026): $considered = ['reason' => radiografia|otro_periodo|otra_fuente,
     *                          'amount' => null|'123.45']. Sin mount sólo se registra el motivo (todo el importe); con
     *                          mount (un solo movimiento, validado al centavo por el llamador contra su importe
     *                          clasificable) esa parte queda fuera del ER y el RESTO toma $treatment.
     * @param  list<array{fingerprint:string, source:string, current:string, considered?:?string}>  $items  movimientos con su tratamiento actual
     * @param  string  $treatment  tratamiento nuevo (debe ser asignable)
     * @param  string  $notes  motivo (mínimo 3 caracteres; se guarda hasta 2000)
     * @param  int|null  $userId  usuario
     * @param  array{reason:string, amount:?string}|null  $considered  reparto «Ya considerado»
     * @return int número de clasificaciones registradas
     *
     * @throws \InvalidArgumentException tratamiento no permitido o motivo corto
     *                                   (también EstadoResultadosLockedException, HTTP 423, si el periodo está aprobado)
     */
    public function classify(Period $period, array $items, string $treatment, string $notes, ?int $userId, ?array $considered = null): int
    {
        app(PeriodLockGuard::class)->assertUnlocked($period); // versión aprobada ⇒ HTTP 423
        if (! Treatment::isAssignable($treatment)) {
            throw new \InvalidArgumentException('Tratamiento no permitido: '.$treatment);
        }
        $notes = trim($notes);
        if (mb_strlen($notes) < 3) {
            throw new \InvalidArgumentException('Escribe el motivo de la clasificación (mínimo 3 caracteres).');
        }

        $reason = $considered['reason'] ?? null;
        $amount = isset($considered['amount']) && $considered['amount'] !== null ? Money::str2($considered['amount']) : null;
        if ($reason !== null && ! isset(Treatment::CONSIDERED_REASONS[$reason])) {
            throw new \InvalidArgumentException('Motivo de «Ya considerado» no válido.');
        }
        if ($amount !== null && (count($items) !== 1 || ! Money::of($amount)->isPositive())) {
            throw new \InvalidArgumentException('El importe ya considerado se captura para un solo movimiento y debe ser mayor a cero.');
        }

        return DB::transaction(function () use ($period, $items, $treatment, $notes, $userId, $reason, $amount) {
            app(PeriodLockGuard::class)->lockForWrite($period); // re-verificación con la fila del periodo bloqueada
            $count = 0;
            foreach ($items as $item) {
                $sameSplit = ($item['considered'] ?? null) === $amount && ($reason === null || ($item['considered_reason'] ?? null) === $reason);
                if ($item['current'] === $treatment && $sameSplit) {
                    continue; // sin cambio real — no ensucia la bitácora
                }
                EstadoResultadosClassification::query()->create([
                    'period_id' => $period->id,
                    'item_fingerprint' => $item['fingerprint'],
                    'item_source' => $item['source'],
                    'treatment' => $treatment,
                    'previous_treatment' => $item['current'],
                    'considered_amount' => $amount,
                    'considered_reason' => $reason,
                    'notes' => mb_substr($notes, 0, 2000),
                    'user_id' => $userId,
                ]);
                $count++;
            }

            return $count;
        });
    }
}
