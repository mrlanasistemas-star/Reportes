<?php

namespace App\Services\EstadoResultados\Reporting;

/**
 * Estados del Estado de Resultados de un periodo.
 *
 * Los tres primeros se DERIVAN del dataset en vivo (ReportStatusEvaluator); los
 * dos últimos sólo existen en una versión aprobada (snapshot inmutable).
 */
final class ReportStatus
{
    public const BORRADOR = 'borrador';

    public const PENDIENTE_CONCILIACION = 'pendiente_conciliacion';

    public const LISTO_REVISION = 'listo_revision';

    public const APROBADO = 'aprobado';

    public const SUSTITUIDO = 'sustituido';

    public const LABELS = [
        self::BORRADOR => 'Borrador',
        self::PENDIENTE_CONCILIACION => 'Pendiente de conciliación',
        self::LISTO_REVISION => 'Listo para revisión',
        self::APROBADO => 'Aprobado',
        self::SUSTITUIDO => 'Sustituido por nueva versión',
    ];

    public const SOURCE_LIVE = 'en_vivo';

    public const SOURCE_SNAPSHOT = 'snapshot';

    /**
     * Criterio gerencial de reconocimiento (se imprime en web/Excel/PDF). Si el
     * dataset trae su propio `meta.criterion` se usa ése.
     */
    public const DEFAULT_CRITERION = 'Criterio gerencial: ingresos reconocidos por lo cobrado en el periodo según Radiografía '
        .'(intereses, IVA cobrado, moratorios, cargos y comisiones; sin capital recuperado ni colocación). '
        .'Gastos por fecha de pago (ERP de gastos y estados de cuenta conciliados); CAPEX, capital de deuda, '
        .'traspasos y movimientos personales quedan fuera del resultado.';

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    /** @return list<array{key:string,label:string}> */
    public static function catalog(): array
    {
        return array_map(fn ($k, $l) => ['key' => $k, 'label' => $l], array_keys(self::LABELS), self::LABELS);
    }
}
