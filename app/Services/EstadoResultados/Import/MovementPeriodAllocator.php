<?php

namespace App\Services\EstadoResultados\Import;

use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Models\Period;
use App\Services\EstadoResultados\Reporting\PeriodLockGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Asigna CADA movimiento bancario al periodo mensual operativo que contiene su FECHA,
 * sin importar en qué periodo se subió el archivo (17-nov-2026).
 *
 * Motivo: los estados de cuenta se arman a mano por mes calendario (1 al 30/31), pero
 * los periodos operativos son de 4 semanas (p. ej. Abril = 30-mar al 26-abr). Así, el
 * periodo Abril toma el 30 y 31 de marzo del archivo de Marzo y del 1 al 26 de abril del
 * archivo de Abril; del 27 al 30 de abril se van a Mayo.
 *
 * Reglas:
 *  - Sólo participan movimientos de cargas vigentes (no reemplazadas).
 *  - Un periodo con versión APROBADA no recibe ni pierde movimientos (queda congelado).
 *  - Mismo movimiento en dos archivos (misma cuenta, fecha, referencia, importes, saldo)
 *    ⇒ se cuenta UNA vez; la copia queda como "duplicado" (no suma).
 *  - Sin periodo mensual que cubra la fecha ⇒ "sin_periodo": se conserva en el periodo
 *    donde se subió como fuera de periodo y entra sola cuando se cree ese periodo.
 *  - Re-ejecutable: sólo escribe lo que cambia; lo que cambia de periodo queda sin
 *    procesar (processed_at = null) para que el periodo afectado pida reprocesar.
 */
class MovementPeriodAllocator
{
    public const PERIODO = 'periodo';

    public const SIN_PERIODO = 'sin_periodo';

    public const DUPLICADO = 'duplicado';

    public const PERIODO_CERRADO = 'periodo_cerrado';

    public static function available(): bool
    {
        return Schema::hasColumn('estado_resultados_movements', 'placement');
    }

    /**
     * Reasigna todos los movimientos. Devuelve los periodos que cambiaron.
     *
     * @return array{changed:int, periods:list<int>}
     */
    public function sync(): array
    {
        if (! self::available()) {
            return ['changed' => 0, 'periods' => []];
        }

        return $this->exclusive(fn () => DB::transaction(fn () => $this->doSync()));
    }

    /**
     * Serializa todo lo que reacomoda movimientos entre periodos (cargas y procesamientos
     * de periodos distintos pueden tocar los mismos movimientos).
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function exclusive(callable $fn): mixed
    {
        $lock = Cache::lock('estado_resultados:asignacion_periodos', 300);
        $lock->block(120);
        try {
            return $fn();
        } finally {
            $lock->release();
        }
    }

    /** Para llamar DENTRO de una transacción que ya escribió movimientos (carga). */
    public function syncInTransaction(): array
    {
        return self::available() ? $this->doSync() : ['changed' => 0, 'periods' => []];
    }

    private function doSync(): array
    {
        $periods = Period::query()->where('type', 'monthly')->orderBy('id')
            ->get(['id', 'start_date', 'end_date'])
            ->map(fn (Period $p) => ['id' => (int) $p->id, 'start' => $p->start_date->format('Y-m-d'), 'end' => $p->end_date->format('Y-m-d')])
            ->all();
        $guard = app(PeriodLockGuard::class);
        $locked = [];
        foreach ($periods as $p) {
            if ($guard->isLocked($p['id'])) {
                $locked[$p['id']] = true;
            }
        }

        $rows = DB::table('estado_resultados_movements as m')
            ->join('estado_resultados_uploads as u', 'u.id', '=', 'm.upload_id')
            ->select(['m.id', 'm.upload_id', 'm.period_id', 'm.fingerprint', 'm.movement_date', 'm.in_period', 'm.placement', 'm.dedup_key',
                'u.period_id as upload_period_id', 'u.status as upload_status'])
            ->orderBy('m.id')
            ->get();

        $periodFor = function (string $date) use ($periods): ?int {
            foreach ($periods as $p) {
                if ($date >= $p['start'] && $date <= $p['end']) {
                    return $p['id'];
                }
            }

            return null;
        };

        $desired = [];   // id => [period_id, in_period, placement, owner]
        $candidates = []; // "periodo|fp" => list<row>
        foreach ($rows as $r) {
            $current = (int) $r->period_id;
            // Congelados: periodo aprobado o carga ya reemplazada (sólo sobrevive en periodos aprobados).
            if (isset($locked[$current]) || $r->upload_status === EstadoResultadosUpload::STATUS_REPLACED) {
                continue;
            }
            $date = substr((string) $r->movement_date, 0, 10);
            $target = $periodFor($date);
            $home = isset($locked[(int) $r->upload_period_id]) ? $current : (int) $r->upload_period_id;

            if ($target === null) {
                $desired[$r->id] = [$home, false, self::SIN_PERIODO, false];
            } elseif (isset($locked[$target])) {
                $desired[$r->id] = [$home, false, self::PERIODO_CERRADO, false];
            } else {
                $candidates[$target.'|'.$r->fingerprint][] = $r;
                $desired[$r->id] = [$home, false, self::DUPLICADO, false]; // se corrige abajo para el ganador
            }
        }

        foreach ($candidates as $key => $group) {
            $target = (int) strstr($key, '|', true);
            usort($group, function ($a, $b) use ($target) {
                // 1) el archivo subido en ese mismo periodo, 2) el que ya era vigente, 3) la carga más reciente.
                $score = fn ($r) => [(int) $r->upload_period_id === $target ? 0 : 1,
                    ((int) $r->period_id === $target && (bool) $r->in_period && (int) $r->dedup_key === 0) ? 0 : 1,
                    -(int) $r->upload_id, (int) $r->id];

                return $score($a) <=> $score($b);
            });
            $desired[$group[0]->id] = [$target, true, self::PERIODO, true];
        }

        $byId = $rows->keyBy('id');
        $changes = [];
        $touched = [];
        foreach ($desired as $id => [$pid, $in, $placement, $owner]) {
            $r = $byId[$id];
            $dedup = $owner ? 0 : (int) $id;
            if ((int) $r->period_id === $pid && (bool) $r->in_period === $in && (string) $r->placement === $placement && (int) $r->dedup_key === $dedup) {
                continue;
            }
            $changes[] = ['id' => (int) $id, 'period_id' => $pid, 'in_period' => $in, 'placement' => $placement, 'owner' => $owner,
                'moved' => (int) $r->period_id !== $pid || (bool) $r->in_period !== $in];
            $touched[(int) $r->period_id] = true;
            $touched[$pid] = true;
        }
        if ($changes === []) {
            return ['changed' => 0, 'periods' => []];
        }

        $table = DB::table('estado_resultados_movements');
        // Fase 1: estacionar todo lo que cambia (dedup_key = id es siempre único) para
        // que ningún movimiento choque en la llave única mientras se reacomoda.
        foreach (array_chunk(array_column($changes, 'id'), 500) as $ids) {
            (clone $table)->whereIn('id', $ids)->update(['dedup_key' => DB::raw('id')]);
        }
        // Fase 2: destino final, agrupado para pocas sentencias.
        $groups = [];
        foreach ($changes as $c) {
            $groups[implode('|', [$c['period_id'], (int) $c['in_period'], $c['placement'], (int) $c['owner'], (int) $c['moved']])][] = $c['id'];
        }
        $now = now();
        foreach ($groups as $g => $ids) {
            [$pid, $in, $placement, $owner, $moved] = explode('|', $g);
            $values = ['period_id' => (int) $pid, 'in_period' => (bool) $in, 'placement' => $placement, 'updated_at' => $now];
            if ($owner === '1') {
                $values['dedup_key'] = 0;
            }
            if ($moved === '1') {
                $values['processed_at'] = null;
            }
            foreach (array_chunk($ids, 500) as $chunk) {
                (clone $table)->whereIn('id', $chunk)->update($values);
            }
        }

        $periodIds = array_map('intval', array_keys($touched));
        sort($periodIds);

        return ['changed' => count($changes), 'periods' => $periodIds];
    }

    /**
     * Dónde quedó cada movimiento de una carga (para avisar al usuario).
     *
     * @return list<array{period_id:?int, label:string, placement:string, count:int}>
     */
    public function breakdownForUpload(int $uploadId): array
    {
        if (! self::available()) {
            return [];
        }
        $rows = DB::table('estado_resultados_movements')
            ->where('upload_id', $uploadId)
            ->groupBy('period_id', 'placement', 'in_period')
            ->get(['period_id', 'placement', 'in_period', DB::raw('COUNT(*) as n'), DB::raw('MIN(movement_date) as first_date')]);
        $periods = Period::query()->whereIn('id', $rows->pluck('period_id')->unique()->all() ?: [0])->get()->keyBy('id');

        return $rows->sortBy('first_date')->map(fn ($r) => [
            'period_id' => (int) $r->period_id,
            'label' => (string) ($periods[$r->period_id]?->label ?? ''),
            'placement' => (bool) $r->in_period ? self::PERIODO : (string) $r->placement,
            'count' => (int) $r->n,
        ])->values()->all();
    }
}
