<?php

namespace App\Services\Roster;

use App\Models\Period;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Política ÚNICA de vigencia laboral ACTUAL (cierre 2026-10) para decidir quién
 * puede recibir algo nuevo (p. ej. un OKR). La consulta histórica no cambia:
 * los rosters de periodos anteriores se leen tal como se calcularon.
 *
 *   - Baja confirmada en la nómina vigente → fuera (lo decide el roster).
 *   - Registros que no son personas (vacantes, plazas, pruebas) → fuera.
 *   - INACTIVO en el directorio vigente → fuera, SALVO que ese directorio sea
 *     ANTERIOR a la nómina vigente y la persona cobre en ella: eso es una
 *     contradicción de fuentes, se mantiene visible y conciliable (nunca se
 *     oculta en silencio).
 *   - Ausente de NOI sin baja confirmada → sigue elegible con advertencia.
 */
class EmploymentEligibilityPolicy
{
    /** Patrones (LIKE, sin distinguir mayúsculas) de registros que no son una persona real. */
    public const PLACEHOLDER_PATTERNS = [
        '%VACANTE%', 'PLAZA %', '%POR CONTRATAR%', '%SIN ASIGNAR%',
        // Registros de prueba explícitos (nunca un apellido real).
        'EMPLEADO DE PRUEBA%', 'EMPLEADO PRUEBA%', 'USUARIO PRUEBA%', 'PRUEBA %', 'TEST %', 'DEMO %',
    ];

    public function __construct(private readonly RosterMovementEvidenceCollector $evidence) {}

    public static function isPlaceholderName(?string $name): bool
    {
        $n = preg_replace('/\s+/u', ' ', mb_strtoupper(trim((string) $name)));
        foreach (self::PLACEHOLDER_PATTERNS as $p) {
            // Mismo significado que el LIKE de SQL: % = cualquier texto.
            $regex = '/^'.implode('.*', array_map(fn ($part) => preg_quote($part, '/'), explode('%', $p))).'$/u';
            if (preg_match($regex, $n) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Excluye vacantes/plazas/registros de prueba (columna de nombre completo). */
    public function excludePlaceholders(QueryBuilder $query, string $column = 'full_name'): void
    {
        $query->where(function ($q) use ($column) {
            foreach (self::PLACEHOLDER_PATTERNS as $p) {
                $q->whereRaw("UPPER(COALESCE({$column}, '')) NOT LIKE ?", [$p]);
            }
        });
    }

    /**
     * ¿El directorio vigente es ANTERIOR al cierre de la nómina vigente? Si lo
     * es, su INACTIVO no puede excluir a quien cobra en esa nómina.
     */
    public function directoryPredatesPayroll(?int $directoryUploadId, ?int $rosterPeriodId): bool
    {
        if ($directoryUploadId === null || $rosterPeriodId === null) {
            return false;
        }
        $end = Period::query()->whereKey($rosterPeriodId)->value('end_date');
        $cut = collect($this->evidence->directoryTimeline())->firstWhere('upload_id', $directoryUploadId)['effective_cut'] ?? null;

        return $end !== null && $cut !== null && $cut < substr((string) $end, 0, 10);
    }

    /** @return int[] employee_id activos en la nómina vigente. */
    public function paidInRoster(int $rosterPeriodId): array
    {
        return DB::table('period_employee_rosters')
            ->where('period_id', $rosterPeriodId)
            ->where('is_active_for_period', true)
            ->pluck('employee_id')->map(fn ($v) => (int) $v)->all();
    }
}
