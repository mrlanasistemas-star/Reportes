<?php

namespace App\Services\Efectividad;

/**
 * Incidencia estructurada de una ejecución de Efectividad de Cobranza.
 *
 * Todas las incidencias tienen la MISMA forma (se guarda como JSON en
 * `efectividad_cobranza_runs.issues` y se muestra tal cual en la UI y en el
 * correo de bloqueo). Los campos que no aplican quedan en null — nunca se
 * inventa un número de fila, una hoja o un encabezado que no se observó.
 *
 * Severidad:
 *  - critical: impidió generar un resultado confiable (bloqueo o error).
 *  - warning:  el reporte se generó, pero el usuario debe revisarlo.
 *  - info:     contexto útil (p.ej. archivo físico ya no disponible).
 *
 * Categoría (diferencia bloqueos de errores técnicos):
 *  technical | missing_data | missing_columns | partial_coverage |
 *  incompatible | stale | file_missing | worker_stalled
 */
final class EfectividadCobranzaIssue
{
    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_INFO = 'info';

    public const CATEGORY_TECHNICAL = 'technical';

    public const CATEGORY_MISSING_DATA = 'missing_data';

    public const CATEGORY_MISSING_COLUMNS = 'missing_columns';

    public const CATEGORY_PARTIAL_COVERAGE = 'partial_coverage';

    public const CATEGORY_INCOMPATIBLE = 'incompatible';

    public const CATEGORY_STALE = 'stale';

    public const CATEGORY_FILE_MISSING = 'file_missing';

    public const CATEGORY_WORKER_STALLED = 'worker_stalled';

    /**
     * @param  array<string,mixed>  $context  file, upload_id, period, sheet, original_header,
     *                                        expected_column, affected_rows, record_count, source
     * @return array<string,mixed>
     */
    public static function make(string $code, string $severity, string $category, string $message, string $action, array $context = []): array
    {
        return [
            'code' => $code,
            'severity' => $severity,
            'category' => $category,
            'message' => $message,
            'source' => $context['source'] ?? null, // ingresos | saldos | null
            'file' => $context['file'] ?? null,
            'upload_id' => $context['upload_id'] ?? null,
            'period' => $context['period'] ?? null,
            'sheet' => $context['sheet'] ?? null,
            'original_header' => $context['original_header'] ?? null,
            'expected_column' => $context['expected_column'] ?? null,
            'field' => $context['field'] ?? null, // clave interna del campo (ColumnMapService)
            'affected_rows' => $context['affected_rows'] ?? null,
            'record_count' => $context['record_count'] ?? null,
            'action' => $action,
        ];
    }

    /**
     * Ordena críticas → advertencias → informativas y elimina duplicados
     * exactos (mismo código + archivo + columna).
     *
     * @param  array<int,array<string,mixed>>  $issues
     * @return array<int,array<string,mixed>>
     */
    public static function normalize(array $issues): array
    {
        $rank = [self::SEVERITY_CRITICAL => 0, self::SEVERITY_WARNING => 1, self::SEVERITY_INFO => 2];

        return collect($issues)
            ->unique(fn (array $i) => ($i['code'] ?? '').'|'.($i['upload_id'] ?? '').'|'.($i['expected_column'] ?? ''))
            ->sortBy(fn (array $i) => $rank[$i['severity'] ?? self::SEVERITY_INFO] ?? 3)
            ->values()
            ->all();
    }
}
