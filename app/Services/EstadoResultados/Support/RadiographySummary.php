<?php

namespace App\Services\EstadoResultados\Support;

use Illuminate\Support\Facades\DB;

/**
 * Selección ÚNICA y determinista del consolidado de Radiografía (period_summaries)
 * vigente de un periodo, para el Estado de Resultados (sólo lectura).
 *
 * Criterio = el MISMO que usa Radiografía para servir/exportar su consolidado
 * (MonthlyReportController::exportRadiography):
 *     status = 'generated' AND invalidated_at IS NULL ORDER BY id DESC LIMIT 1
 *
 * Hoy la tabla tiene UNIQUE(period_id) (migración 2026_04_28_100000), así que no
 * puede haber dos filas del mismo periodo; aun así el ORDER BY id DESC deja la
 * selección determinista si algún día se versionan los consolidados (gana el más
 * reciente vigente), en lugar del orden físico que devolvía `->value('id')` sin ORDER BY.
 */
final class RadiographySummary
{
    public static function current(int $periodId): ?object
    {
        return DB::table('period_summaries')
            ->where('period_id', $periodId)
            ->where('status', 'generated')
            ->whereNull('invalidated_at')
            ->orderByDesc('id')
            ->first();
    }

    public static function currentId(int $periodId): ?int
    {
        $id = self::current($periodId)?->id;

        return $id !== null ? (int) $id : null;
    }
}
