<?php

namespace App\Services\Roster;

use App\Models\Period;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reúne, SOLO CON LECTURAS, la evidencia que necesita PeriodEmployeeRosterService
 * para clasificar altas/bajas sin inventar nada (18-oct-2026):
 *
 *   - Directorio laboral (lendus_employee_directory): estatus ACTIVO / INACTIVO
 *     de la carga cuyo periodo CUBRE el periodo evaluado (fin ≥ inicio del
 *     periodo). Un directorio más viejo no prueba nada sobre una ausencia
 *     posterior → sin carga válida = vigencia desconocida.
 *   - Finiquito/indemnización en NOI (si existe el concepto).
 *   - Colocación Lendus del periodo (actividad comercial, nunca vigencia).
 *   - Rosters de periodos POSTERIORES (si reaparece, la ausencia no fue baja).
 *   - Historial anterior (para distinguir reingreso de regreso tras ausencia).
 *   - Conciliación autorizada (period_employee_movement_reviews).
 */
class RosterMovementEvidenceCollector
{
    private const FINIQUITO_PATTERNS = ['FINIQUITO', 'INDEMNIZ', 'LIQUIDACION', 'LIQUIDACIÓN', 'PRIMA DE ANTIG', 'PRIMA ANTIG'];

    private const COMMERCIAL_POSITIONS = ['GESTOR', 'PROMOTOR', 'ASESOR', 'EJECUTIVO'];

    /**
     * Carga del directorio laboral válida para el periodo, o null.
     *
     * @return array{upload_id:int, label:string, end_date:?string}|null
     */
    public function directorySnapshotFor(Period $period): ?array
    {
        if (! Schema::hasTable('lendus_employee_directory')) {
            return null;
        }

        $row = DB::table('lendus_employee_directory as d')
            ->join('report_uploads as u', 'u.id', '=', 'd.report_upload_id')
            ->leftJoin('periods as p', 'p.id', '=', 'u.period_id')
            ->whereNotNull('p.end_date')
            ->whereDate('p.end_date', '>=', $period->start_date)
            ->orderBy('p.end_date')
            ->orderByDesc('d.report_upload_id')
            ->select('d.report_upload_id', 'p.name as period_label', 'p.end_date', 'u.original_name')
            ->first();

        if (! $row) {
            return null;
        }

        return [
            'upload_id' => (int) $row->report_upload_id,
            'label' => trim(($row->original_name ?? 'Directorio laboral').' · '.($row->period_label ?? '')),
            'end_date' => $row->end_date ? substr((string) $row->end_date, 0, 10) : null,
        ];
    }

    /**
     * Estatus por nombre normalizado en la carga del directorio: ACTIVO si hay
     * alguna fila ACTIVO, si no INACTIVO; además puesto y códigos.
     *
     * @param  string[]  $names
     * @return array<string, array{status:string, puesto:?string, codes:string[]}>
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
                $out[$key] ??= ['status' => null, 'puesto' => null, 'codes' => []];
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

        return array_filter($out, fn ($v) => $v['status'] !== null);
    }

    /**
     * Nombres con concepto de finiquito/indemnización en NOI en los periodos dados.
     *
     * @param  int[]  $dataIds
     * @return array<string, string> nombre_normalizado => concepto
     */
    public function finiquitoNames(array $dataIds): array
    {
        if ($dataIds === []) {
            return [];
        }

        $rows = DB::table('fact_noi_movements as f')
            ->join('employees as e', 'e.id', '=', 'f.employee_id')
            ->whereIn('f.period_id', $dataIds)
            ->where(function ($q) {
                foreach (self::FINIQUITO_PATTERNS as $p) {
                    $q->orWhereRaw('UPPER(f.concept) LIKE ?', ['%'.$p.'%']);
                }
            })
            ->get(['e.normalized_name', 'f.concept']);

        $out = [];
        foreach ($rows as $r) {
            if ($r->normalized_name) {
                $out[(string) $r->normalized_name] = (string) $r->concept;
            }
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
     * @param  string[]  $names
     * @return array<string, string> nombre_normalizado => etiqueta del periodo
     */
    public function reappearsLater(Period $period, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk(array_values(array_unique($names)), 500) as $chunk) {
            DB::table('period_employee_rosters as r')
                ->join('periods as p', 'p.id', '=', 'r.period_id')
                ->where('r.is_active_for_period', true)
                ->where('r.period_id', '!=', $period->id)
                ->whereDate('p.start_date', '>', $period->end_date)
                ->whereIn('r.nombre_normalizado', $chunk)
                ->orderBy('p.start_date')
                ->get(['r.nombre_normalizado', 'p.name'])
                ->each(function ($r) use (&$out) {
                    $out[(string) $r->nombre_normalizado] ??= (string) $r->name;
                });
        }

        return $out;
    }

    /**
     * Último registro ANTERIOR (por fecha, sin contar el periodo evaluado) de
     * cada persona: sirve para distinguir reingreso (última fila = baja
     * confirmada) de regreso tras ausencia (última fila = activo/ausencia).
     *
     * @param  string[]  $names
     * @return array<string, array{status:string, period:string}>
     */
    public function lastPriorRecord(Period $period, array $names): array
    {
        if ($names === []) {
            return [];
        }

        $out = [];
        foreach (array_chunk(array_values(array_unique($names)), 500) as $chunk) {
            DB::table('period_employee_rosters as r')
                ->join('periods as p', 'p.id', '=', 'r.period_id')
                ->where('r.period_id', '!=', $period->id)
                ->whereDate('p.end_date', '<', $period->start_date)
                ->whereIn('r.nombre_normalizado', $chunk)
                ->orderByDesc('p.end_date')
                ->get(['r.nombre_normalizado', 'r.is_active_for_period', 'r.movement_type', 'r.reconciliation_status', 'p.name'])
                ->each(function ($r) use (&$out) {
                    $key = (string) $r->nombre_normalizado;
                    if (isset($out[$key])) {
                        return;
                    }
                    $out[$key] = [
                        'status' => RosterMovementStatus::resolve($r->reconciliation_status, $r->movement_type, (bool) $r->is_active_for_period),
                        'confirmed_baja' => RosterMovementStatus::isConfirmedBaja(
                            RosterMovementStatus::resolve($r->reconciliation_status, $r->movement_type, (bool) $r->is_active_for_period),
                            $r->movement_type,
                        ),
                        'period' => (string) $r->name,
                    ];
                });
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
     * Última decisión autorizada por persona para el periodo.
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
            ->get(['v.nombre_normalizado', 'v.decision', 'v.note', 'v.created_at', 'u.name as reviewer'])
            ->each(function ($r) use (&$out) {
                $out[(string) $r->nombre_normalizado] ??= $r;
            });

        return $out;
    }
}
