<?php

namespace App\Services\Roster;

use App\Models\Period;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reúne, SOLO CON LECTURAS, la evidencia que necesita PeriodEmployeeRosterService
 * para clasificar altas/bajas sin inventar nada (18-oct-2026):
 *
 *   - Directorio laboral (lendus_employee_directory): estatus ACTIVO / INACTIVO,
 *     usado SOLO si su corte es válido para el periodo evaluado (ver
 *     directoryEvidenceFor()). Un directorio POSTERIOR nunca confirma una baja.
 *   - Finiquito/indemnización en NOI (si existe el concepto).
 *   - Colocación Lendus del periodo (actividad comercial, nunca vigencia).
 *   - Rosters de periodos POSTERIORES (si reaparece, la ausencia no fue baja).
 *   - Historial anterior (para distinguir reingreso de regreso tras ausencia).
 *   - Conciliación autorizada (period_employee_movement_reviews).
 *
 * Todas las búsquedas por persona usan la llave de identidad del roster
 * (employee_key) Y los employee_id del grupo — nunca solo el nombre: dos
 * homónimos con llaves distintas no comparten historial.
 */
class RosterMovementEvidenceCollector
{
    private const FINIQUITO_PATTERNS = ['FINIQUITO', 'INDEMNIZ', 'LIQUIDACION', 'LIQUIDACIÓN', 'PRIMA DE ANTIG', 'PRIMA ANTIG'];

    private const COMMERCIAL_POSITIONS = ['GESTOR', 'PROMOTOR', 'ASESOR', 'EJECUTIVO'];

    /**
     * Ventana de cierre: un directorio exportado hasta N días después del fin
     * del periodo todavía describe ese periodo (la carga de cierre se hace
     * normalmente dentro del mes siguiente). Más allá es evidencia POSTERIOR.
     */
    public const DIRECTORY_GRACE_DAYS = 31;

    public const DIR_PERIOD = 'periodo';

    public const DIR_PRIOR = 'anterior';

    public const DIR_LATER = 'posterior';

    private ?array $timeline = null;

    /**
     * Todas las cargas del directorio laboral con su corte efectivo.
     *
     * Corte efectivo = el mayor entre (a) el fin del periodo al que se cargó y
     * (b) la fecha de ingreso más reciente que contiene el archivo — un archivo
     * no puede ser anterior a la última alta que trae. Esto detecta un archivo
     * reciente cargado en un periodo viejo (caso real: el "directorio de Mayo"
     * trae ingresos al 30/06/2026; es una foto de finales de junio).
     *
     * @return array<int, array{upload_id:int, file:string, declared_period_id:?int, declared_period:?string, declared_start:?string, declared_end:?string, max_hire_date:?string, uploaded_at:?string, effective_cut:?string, content_after_declared:bool, rows:int}>
     */
    public function directoryTimeline(): array
    {
        if ($this->timeline !== null) {
            return $this->timeline;
        }
        if (! Schema::hasTable('lendus_employee_directory')) {
            return $this->timeline = [];
        }

        $rows = DB::table('lendus_employee_directory as d')
            ->join('report_uploads as u', 'u.id', '=', 'd.report_upload_id')
            ->leftJoin('periods as p', 'p.id', '=', 'u.period_id')
            ->groupBy('d.report_upload_id', 'u.original_name', 'u.period_id', 'p.name', 'p.start_date', 'p.end_date', 'u.uploaded_at', 'u.created_at')
            ->selectRaw('d.report_upload_id, u.original_name, u.period_id, p.name as period_name, p.start_date, p.end_date, u.uploaded_at, u.created_at, MAX(d.fecha_inicio) as max_hire, COUNT(*) as n')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $declaredEnd = $r->end_date ? substr((string) $r->end_date, 0, 10) : null;
            $maxHire = $r->max_hire ? substr((string) $r->max_hire, 0, 10) : null;
            $cut = max(array_filter([$declaredEnd, $maxHire])) ?: null;
            $out[] = [
                'upload_id' => (int) $r->report_upload_id,
                'file' => (string) ($r->original_name ?? 'Directorio laboral'),
                'declared_period_id' => $r->period_id ? (int) $r->period_id : null,
                'declared_period' => $r->period_name,
                'declared_start' => $r->start_date ? substr((string) $r->start_date, 0, 10) : null,
                'declared_end' => $declaredEnd,
                'max_hire_date' => $maxHire,
                'uploaded_at' => ($r->uploaded_at ?? $r->created_at) ? substr((string) ($r->uploaded_at ?? $r->created_at), 0, 10) : null,
                'effective_cut' => $cut,
                'content_after_declared' => $declaredEnd !== null && $maxHire !== null
                    && $maxHire > date('Y-m-d', strtotime($declaredEnd.' +'.self::DIRECTORY_GRACE_DAYS.' days')),
                'rows' => (int) $r->n,
            ];
        }
        usort($out, fn ($a, $b) => [$a['effective_cut'], $a['upload_id']] <=> [$b['effective_cut'], $b['upload_id']]);

        return $this->timeline = $out;
    }

    /**
     * Evidencia de directorio para el periodo, con su procedencia:
     *   - valid: corte dentro de [inicio, fin + ventana] → 'periodo' (prueba vigencia
     *     del periodo); si no hay, el más reciente con corte ANTES del inicio →
     *     'anterior' (su INACTIVO prueba una baja "a más tardar" en su corte; su
     *     ACTIVO es viejo y no contradice una ausencia posterior).
     *   - later: el primer directorio con corte POSTERIOR a la ventana — solo
     *     informativo: nunca confirma una baja ni crea contradicciones.
     *
     * @return array{valid: ?array, later: ?array, timeline: array}
     */
    public function directoryEvidenceFor(Period $period): array
    {
        $start = $period->start_date?->toDateString();
        $end = $period->end_date?->toDateString();
        $timeline = $this->directoryTimeline();
        if ($start === null || $end === null || $timeline === []) {
            return ['valid' => null, 'later' => null, 'timeline' => $timeline];
        }
        $limit = date('Y-m-d', strtotime($end.' +'.self::DIRECTORY_GRACE_DAYS.' days'));

        $inWindow = array_values(array_filter($timeline, fn ($d) => $d['effective_cut'] !== null && $d['effective_cut'] >= $start && $d['effective_cut'] <= $limit));
        // Preferir el que se cargó PARA este periodo; luego el de corte más cercano al cierre.
        usort($inWindow, fn ($a, $b) => [(int) ($b['declared_period_id'] === $period->id), $a['effective_cut']] <=> [(int) ($a['declared_period_id'] === $period->id), $b['effective_cut']]);
        $valid = $inWindow[0] ?? null;
        $kind = self::DIR_PERIOD;

        if ($valid === null) {
            $prior = array_values(array_filter($timeline, fn ($d) => $d['effective_cut'] !== null && $d['effective_cut'] < $start));
            $valid = $prior !== [] ? end($prior) : null;
            $kind = self::DIR_PRIOR;
        }

        $later = array_values(array_filter($timeline, fn ($d) => $d['effective_cut'] !== null && $d['effective_cut'] > $limit))[0] ?? null;

        return [
            'valid' => $valid ? $this->describe($valid, $kind) : null,
            'later' => $later ? $this->describe($later, self::DIR_LATER) : null,
            'timeline' => $timeline,
        ];
    }

    /**
     * Compatibilidad: carga del directorio VÁLIDA para el periodo, o null.
     * Nunca devuelve un directorio posterior.
     */
    public function directorySnapshotFor(Period $period): ?array
    {
        return $this->directoryEvidenceFor($period)['valid'];
    }

    private function describe(array $d, string $kind): array
    {
        $cut = $d['effective_cut'] ? date('d/m/Y', strtotime($d['effective_cut'])) : 's/f';
        $where = $d['declared_period'] ? "cargado en {$d['declared_period']}" : 'sin periodo';
        $kindLabel = match ($kind) {
            self::DIR_PERIOD => 'vigente para el periodo',
            self::DIR_PRIOR => 'anterior al periodo',
            default => 'posterior al periodo',
        };

        return [
            'upload_id' => $d['upload_id'],
            'kind' => $kind,
            'label' => "{$d['file']} · {$where} · corte estimado {$cut} ({$kindLabel})",
            'end_date' => $d['effective_cut'],
            'effective_cut' => $d['effective_cut'],
            'declared_period' => $d['declared_period'],
            'content_after_declared' => $d['content_after_declared'],
        ];
    }

    /**
     * Estatus por nombre normalizado en la carga del directorio: ACTIVO si hay
     * alguna fila ACTIVO, si no INACTIVO; además puesto, códigos y si el nombre
     * corresponde a más de un número de empleado (homónimo en el directorio).
     *
     * @param  string[]  $names
     * @return array<string, array{status:string, puesto:?string, codes:string[], ambiguous:bool}>
     */
    public function directoryStatusByName(?array $snapshot, array $names): array
    {
        if ($snapshot === null || $names === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk(array_values(array_unique($names)), 500) as $chunk) {
            $rows = DB::table('lendus_employee_directory')
                ->where('report_upload_id', $snapshot['upload_id'])
                ->whereIn('normalized_name', $chunk)
                ->get(['normalized_name', 'estatus', 'puesto', 'codigo']);

            foreach ($rows as $r) {
                $key = (string) $r->normalized_name;
                $status = mb_strtoupper(trim((string) $r->estatus));
                $out[$key] ??= ['status' => null, 'puesto' => null, 'codes' => [], 'ambiguous' => false];
                if ($status === 'ACTIVO') {
                    $out[$key]['status'] = 'ACTIVO';
                    $out[$key]['puesto'] = $r->puesto ?: $out[$key]['puesto'];
                } elseif ($status === 'INACTIVO' && $out[$key]['status'] !== 'ACTIVO') {
                    $out[$key]['status'] = 'INACTIVO';
                    $out[$key]['puesto'] ??= $r->puesto;
                }
                if ($r->codigo) {
                    $out[$key]['codes'][] = mb_strtoupper(trim((string) $r->codigo));
                }
            }
        }

        foreach ($out as $k => $v) {
            $out[$k]['codes'] = array_values(array_unique($v['codes']));
            $out[$k]['ambiguous'] = count($out[$k]['codes']) > 1;
        }

        return array_filter($out, fn ($v) => $v['status'] !== null);
    }

    /**
     * Empleados con concepto de finiquito/indemnización en NOI en los periodos dados.
     *
     * @param  int[]  $dataIds
     * @return array<int, string> employee_id => concepto
     */
    public function finiquitoEmployees(array $dataIds): array
    {
        if ($dataIds === []) {
            return [];
        }

        $rows = DB::table('fact_noi_movements as f')
            ->whereIn('f.period_id', $dataIds)
            ->whereNotNull('f.employee_id')
            ->where(function ($q) {
                foreach (self::FINIQUITO_PATTERNS as $p) {
                    $q->orWhereRaw('UPPER(f.concept) LIKE ?', ['%'.$p.'%']);
                }
            })
            ->get(['f.employee_id', 'f.concept']);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->employee_id] = (string) $r->concept;
        }

        return $out;
    }

    /**
     * Actividad comercial (colocación Lendus) en los periodos dados.
     *
     * @param  int[]  $dataIds
     * @return array{names: array<string,bool>, codes: array<string,bool>, loaded: bool}
     */
    public function placementActivity(array $dataIds): array
    {
        if ($dataIds === [] || ! Schema::hasTable('fact_placements')) {
            return ['names' => [], 'codes' => [], 'loaded' => false];
        }

        $rows = DB::table('fact_placements')
            ->whereIn('period_id', $dataIds)
            ->select('normalized_promoter_name', 'promoter_code')
            ->distinct()
            ->get();

        $names = [];
        $codes = [];
        foreach ($rows as $r) {
            if ($r->normalized_promoter_name) {
                $names[(string) $r->normalized_promoter_name] = true;
            }
            if ($r->promoter_code) {
                $codes[mb_strtoupper(trim((string) $r->promoter_code))] = true;
            }
        }

        return ['names' => $names, 'codes' => $codes, 'loaded' => $rows->isNotEmpty()];
    }

    public function hasCommercialActivity(array $activity, string $name, array $codes): bool
    {
        if (isset($activity['names'][$name])) {
            return true;
        }
        foreach ($codes as $c) {
            if (isset($activity['codes'][$c])) {
                return true;
            }
        }

        return false;
    }

    public function isCommercialPosition(?string $puesto): bool
    {
        $p = mb_strtoupper((string) $puesto);
        foreach (self::COMMERCIAL_POSITIONS as $needle) {
            if (str_contains($p, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Primer periodo POSTERIOR en el que la persona vuelve a estar en nómina.
     *
     * @param  array<string, int[]>  $identities  employee_key => employee_ids
     * @return array<string, string> employee_key => etiqueta del periodo
     */
    public function reappearsLater(Period $period, array $identities): array
    {
        if ($identities === []) {
            return [];
        }

        $rows = $this->rosterRowsFor($identities, fn ($q) => $q
            ->where('r.is_active_for_period', true)
            ->where('r.period_id', '!=', $period->id)
            ->whereDate('p.start_date', '>', $period->end_date)
            ->orderBy('p.start_date'), ['p.name']);

        $out = [];
        foreach ($rows as $key => $list) {
            $out[$key] = (string) $list[0]->name;
        }

        return $out;
    }

    /**
     * Último registro ANTERIOR (por fecha, sin contar el periodo evaluado) de
     * cada persona: sirve para distinguir reingreso (última fila = baja
     * confirmada) de regreso tras ausencia (última fila = activo/ausencia).
     *
     * @param  array<string, int[]>  $identities  employee_key => employee_ids
     * @return array<string, array{status:string, confirmed_baja:bool, period:string}>
     */
    public function lastPriorRecord(Period $period, array $identities): array
    {
        if ($identities === []) {
            return [];
        }

        $rows = $this->rosterRowsFor($identities, fn ($q) => $q
            ->where('r.period_id', '!=', $period->id)
            ->whereDate('p.end_date', '<', $period->start_date)
            ->orderByDesc('p.end_date'), ['r.is_active_for_period', 'r.movement_type', 'r.reconciliation_status', 'p.name']);

        $out = [];
        foreach ($rows as $key => $list) {
            $r = $list[0];
            $status = RosterMovementStatus::resolve($r->reconciliation_status, $r->movement_type, (bool) $r->is_active_for_period);
            $out[$key] = [
                'status' => $status,
                'confirmed_baja' => RosterMovementStatus::isConfirmedBaja($status, $r->movement_type),
                'period' => (string) $r->name,
            ];
        }

        return $out;
    }

    /**
     * Filas de roster de otros periodos que pertenecen a cada identidad, por
     * employee_id del grupo o por la misma llave (filas antiguas, cuya llave
     * era el nombre). Conserva el orden de la consulta.
     *
     * @param  array<string, int[]>  $identities
     * @return array<string, array<int, object>>
     */
    private function rosterRowsFor(array $identities, \Closure $scope, array $columns): array
    {
        $keyById = [];
        foreach ($identities as $key => $ids) {
            foreach ($ids as $id) {
                $keyById[(int) $id] = (string) $key;
            }
        }
        $keys = array_map('strval', array_keys($identities));

        $out = [];
        foreach (array_chunk($keys, 400) as $i => $chunk) {
            $ids = array_values(array_unique(array_merge(...array_map(fn ($k) => $identities[$k], $chunk))));
            $q = DB::table('period_employee_rosters as r')
                ->join('periods as p', 'p.id', '=', 'r.period_id')
                ->where(fn ($w) => $w->whereIn('r.employee_key', $chunk)->orWhereIn('r.employee_id', $ids));
            $scope($q);
            foreach ($q->get(array_merge(['r.employee_key', 'r.employee_id'], $columns)) as $r) {
                $key = $keyById[(int) $r->employee_id] ?? (in_array((string) $r->employee_key, $chunk, true) ? (string) $r->employee_key : null);
                if ($key !== null) {
                    $out[$key][] = $r;
                }
            }
        }

        return $out;
    }

    private ?bool $reviewsAvailable = null;

    /** La migración puede no haberse corrido aún (despliegue en curso): nunca romper la lectura. */
    public function reviewsAvailable(): bool
    {
        return $this->reviewsAvailable ??= Schema::hasTable('period_employee_movement_reviews');
    }

    /**
     * Última decisión autorizada por persona para el periodo. La columna
     * nombre_normalizado de la bitácora guarda la llave de identidad del
     * roster (igual al nombre salvo homónimos separados).
     *
     * @return array<string, object>
     */
    public function latestReviews(Period $period): array
    {
        if (! $this->reviewsAvailable()) {
            return [];
        }

        $out = [];
        DB::table('period_employee_movement_reviews as v')
            ->leftJoin('users as u', 'u.id', '=', 'v.reviewed_by')
            ->where('v.period_id', $period->id)
            ->orderByDesc('v.id')
            ->get(['v.nombre_normalizado', 'v.employee_id', 'v.decision', 'v.note', 'v.created_at', 'u.name as reviewer'])
            ->each(function ($r) use (&$out) {
                $out[(string) $r->nombre_normalizado] ??= $r;
            });

        return $out;
    }
}
