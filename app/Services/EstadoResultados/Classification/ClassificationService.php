<?php

namespace App\Services\EstadoResultados\Classification;

use App\Models\EstadoResultados\EstadoResultadosClassification;
use App\Models\Period;
use App\Services\EstadoResultados\Reporting\PeriodLockGuard;
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
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
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
            ])->all();
    }

    /**
     * @param  list<array{fingerprint:string, source:string, current:string}>  $items
     */
    public function classify(Period $period, array $items, string $treatment, string $notes, ?int $userId): int
    {
        app(PeriodLockGuard::class)->assertUnlocked($period); // versión aprobada ⇒ HTTP 423
        if (! Treatment::isAssignable($treatment)) {
            throw new \InvalidArgumentException('Tratamiento no permitido: '.$treatment);
        }
        $notes = trim($notes);
        if (mb_strlen($notes) < 3) {
            throw new \InvalidArgumentException('Escribe el motivo de la clasificación (mínimo 3 caracteres).');
        }

        return DB::transaction(function () use ($period, $items, $treatment, $notes, $userId) {
            $count = 0;
            foreach ($items as $item) {
                if ($item['current'] === $treatment) {
                    continue; // sin cambio real — no ensucia la bitácora
                }
                EstadoResultadosClassification::query()->create([
                    'period_id' => $period->id,
                    'item_fingerprint' => $item['fingerprint'],
                    'item_source' => $item['source'],
                    'treatment' => $treatment,
                    'previous_treatment' => $item['current'],
                    'notes' => mb_substr($notes, 0, 2000),
                    'user_id' => $userId,
                ]);
                $count++;
            }

            return $count;
        });
    }
}
