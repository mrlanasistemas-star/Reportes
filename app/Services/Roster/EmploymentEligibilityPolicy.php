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
 *
 * La usan los resolvedores de personal de OKR (elegibles para metas) y la API
 * de colaboradores. Solo lectura.
 */
class EmploymentEligibilityPolicy
{
    /** Patrones (LIKE, sin distinguir mayúsculas) de registros que no son una persona real. */
    public const PLACEHOLDER_PATTERNS = [
        '%VACANTE%', 'PLAZA %', '%POR CONTRATAR%', '%SIN ASIGNAR%',
        // Registros de prueba explícitos (nunca un apellido real).
        'EMPLEADO DE PRUEBA%', 'EMPLEADO PRUEBA%', 'USUARIO PRUEBA%', 'PRUEBA %', 'TEST %', 'DEMO %',
    ];

    /**
     * @param  RosterMovementEvidenceCollector  $evidence  Línea de tiempo de directorios laborales cargados.
     */
    public function __construct(private readonly RosterMovementEvidenceCollector $evidence) {}

    /**
     * ¿El nombre corresponde a un registro que no es persona (vacante, plaza, prueba)?
     *
     * Equivalente en PHP de excludePlaceholders(): convierte cada patrón LIKE de
     * PLACEHOLDER_PATTERNS a regex (sin distinguir mayúsculas, espacios colapsados).
     *
     * @param  string|null  $name  Nombre completo.
     */
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

    /**
     * Excluye vacantes/plazas/registros de prueba (columna de nombre completo).
     *
     * Modifica la consulta recibida agregando un NOT LIKE por patrón.
     *
     * @param  QueryBuilder  $query  Consulta a filtrar (se modifica en sitio).
     * @param  string  $column  Columna con el nombre completo (se interpola en SQL; debe venir del código, nunca del usuario).
     */
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
     *
     * Compara el corte efectivo del directorio (directoryTimeline()) contra la
     * fecha fin del periodo de la nómina (comparación de cadenas Y-m-d).
     *
     * @param  int|null  $directoryUploadId  Carga del directorio vigente.
     * @param  int|null  $rosterPeriodId  Periodo de la nómina vigente.
     * @return bool false si falta alguno de los datos.
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

    /**
     * Colaboradores que cobran en la nómina del periodo (is_active_for_period = 1).
     *
     * @param  int  $rosterPeriodId  Periodo de la nómina vigente.
     * @return int[] employee_id activos en la nómina vigente.
     */
    public function paidInRoster(int $rosterPeriodId): array
    {
        return DB::table('period_employee_rosters')
            ->where('period_id', $rosterPeriodId)
            ->where('is_active_for_period', true)
            ->pluck('employee_id')->map(fn ($v) => (int) $v)->all();
    }
}
