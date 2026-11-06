<?php

namespace App\Services\EstadoResultados\Comparatives;

use App\Models\Period;
use App\Services\EstadoResultados\PeriodDataIds;
use App\Services\EstadoResultados\Support\Dimensions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Firma DETERMINISTA de todos los insumos de los que depende el dataset en vivo de un
 * periodo. Si cualquiera cambia, la firma cambia y el resumen en caché deja de usarse
 * (nunca se sirve un dato viejo). Sólo hace consultas agregadas baratas (índices por period_id).
 *
 * Insumos firmados:
 *  - periodo (fechas, updated_at) y universo de period_id de Radiografía (PeriodDataIds);
 *  - report_uploads de ese universo (id, estado, updated_at) — Radiografía, ERP, NOI, IMSS…;
 *  - conteo / id máximo / updated_at máximo de fact_expenses (= firma ERP), fact_noi_movements,
 *    fact_imss, fact_rotacion, period_summaries; catálogo de sucursales;
 *  - ER: cargas (id, estado, sha256), movimientos (conteo, max updated_at/processed_at), vínculos de
 *    conciliación, clasificaciones (id máximo), ajustes y su bitácora, corridas, versiones;
 *  - versión de reglas, versión de dimensiones, versión del extractor y huella del CÓDIGO del módulo
 *    (mtime de app/Services/EstadoResultados/** y Radiografía) — un cambio de reglas invalida la caché;
 *  - fecha del día (Radiografía tiene métricas relativas a la fecha; la caché nunca cruza días).
 */
class InputSignature
{
    private const COMPARATIVES_VERSION = 'cmp-1';

    /** Formato de la base de Radiografía en caché (subir si cambia su estructura). */
    public const BASE_VERSION = 'base-1';

    private static ?string $codeFingerprint = null;

    public function __construct(private readonly PeriodDataIds $dataIds) {}

    public function for(Period $period, string $scope): string
    {
        $ids = $this->dataIds->for($period);
        $agg = fn (string $table, array $ids, string $col = 'period_id') => $this->aggregate($table, $col, $ids);

        $parts = [
            'v' => self::COMPARATIVES_VERSION.'/'.PeriodSummaryExtractor::VERSION,
            'rules' => (string) config('estado_resultados.rules_version'),
            'scope' => $scope,
            'radiography' => $this->radiographyParts($period, $ids),
            'er_uploads' => DB::table('estado_resultados_uploads')->where('period_id', $period->id)->orderBy('id')
                ->get(['id', 'account_slot', 'status', 'file_hash', 'updated_at'])->map(fn ($r) => implode('|', (array) $r))->all(),
            'er_movements' => [$agg('estado_resultados_movements', [$period->id]),
                DB::table('estado_resultados_movements')->where('period_id', $period->id)->max('processed_at')],
            'er_links' => $this->aggregate('estado_resultados_reconciliation_links', null, []),
            'er_classifications' => $agg('estado_resultados_classifications', [$period->id]),
            'er_adjustments' => $agg('estado_resultados_adjustments', [$period->id]),
            'er_adjustment_logs' => $this->aggregate('estado_resultados_adjustment_logs', null, []),
            'er_runs' => $agg('estado_resultados_runs', [$period->id]),
            'er_versions' => $agg('estado_resultados_versions', [$period->id]),
        ];

        return hash('sha256', json_encode($parts, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Firma SÓLO de los insumos de la base de Radiografía (RadiographyBaseProvider). No cambia al
     * clasificar, ajustar o procesar estados de cuenta: esas acciones reutilizan la base ya
     * calculada y sólo recalculan la parte propia del ER, que nunca se guarda en caché.
     */
    public function radiography(Period $period): string
    {
        return hash('sha256', json_encode(
            ['v' => self::BASE_VERSION] + $this->radiographyParts($period, $this->dataIds->for($period)),
            JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * Insumos de la base de Radiografía: universo de period_id, cargas de report_uploads (toda
     * importación o reproceso de recuperaciones, colocación, cartera, ERP, NOI, IMSS… cambia su
     * id/estado/updated_at), agregados baratos de hechos, catálogos, huella del código y fecha
     * del día (Radiografía tiene métricas relativas a la fecha: la caché nunca cruza días).
     *
     * @param  list<int>  $ids
     */
    private function radiographyParts(Period $period, array $ids): array
    {
        $agg = fn (string $table, array $ids, string $col = 'period_id') => $this->aggregate($table, $col, $ids);

        return [
            'dims' => Dimensions::VERSION,
            'code' => self::codeFingerprint(),
            'day' => now('America/Mexico_City')->format('Y-m-d'),
            'period' => [$period->id, (string) $period->start_date, (string) $period->end_date, (string) $period->updated_at],
            'data_ids' => $ids,
            'report_uploads' => $ids === [] ? [] : DB::table('report_uploads')->whereIn('period_id', $ids)->orderBy('id')
                ->get(['id', 'data_source_id', 'status', 'updated_at'])->map(fn ($r) => implode('|', (array) $r))->all(),
            'fact_expenses' => $agg('fact_expenses', $ids),
            'fact_noi' => $agg('fact_noi_movements', $ids),
            'fact_imss' => $agg('fact_imss', $ids),
            'fact_rotacion' => $agg('fact_rotacion', [$period->id]),
            'summaries' => $agg('period_summaries', [$period->id]),
            'branches' => $this->aggregate('branches', null, []),
            'employee_branch_assignments' => $this->aggregate('employee_branch_assignments', null, []),
            'data_sources' => $this->aggregate('data_sources', null, []),
        ];
    }

    /** [conteo, id máximo, updated_at máximo] — tolera tablas/columnas ausentes. */
    private function aggregate(string $table, ?string $col, array $ids): array
    {
        static $columns = [];
        if (! isset($columns[$table])) {
            $columns[$table] = Schema::hasTable($table) ? Schema::getColumnListing($table) : false;
        }
        $cols = $columns[$table];
        if ($cols === false) {
            return ['sin_tabla'];
        }
        $q = DB::table($table);
        if ($col !== null) {
            if (! in_array($col, $cols, true)) {
                return ['sin_columna'];
            }
            if ($ids === []) {
                return [0];
            }
            $q->whereIn($col, $ids);
        }
        $select = ['COUNT(*) as c'];
        if (in_array('id', $cols, true)) {
            $select[] = 'MAX(id) as m';
        }
        if (in_array('updated_at', $cols, true)) {
            $select[] = 'MAX(updated_at) as u';
        } elseif (in_array('created_at', $cols, true)) {
            $select[] = 'MAX(created_at) as u';
        }
        $row = (array) $q->selectRaw(implode(', ', $select))->first();

        return array_values(array_map(fn ($v) => $v === null ? null : (string) $v, $row));
    }

    private static function codeFingerprint(): string
    {
        if (self::$codeFingerprint !== null) {
            return self::$codeFingerprint;
        }
        $marks = [];
        foreach ([app_path('Services/EstadoResultados'), app_path('Services/Radiography')] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $marks[] = $f->getPathname().':'.$f->getMTime().':'.$f->getSize();
                }
            }
        }
        $marks[] = (string) @filemtime(config_path('estado_resultados.php'));
        sort($marks);

        return self::$codeFingerprint = sha1(implode("\n", $marks));
    }
}
