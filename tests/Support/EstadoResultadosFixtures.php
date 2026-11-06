<?php

namespace Tests\Support;

use App\Enums\ReportUploadStatus;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Expense;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\EstadoResultados\Calculation\RadiographyBaseProvider;
use App\Services\EstadoResultados\PeriodDataIds;
use App\Services\Radiography\BranchRadiographyCalculator;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Fixtures sintéticas del Estado de Resultados (sin datos reales, sin seeders).
 *
 * En SQLite no puede ejecutarse BranchRadiographyCalculator::buildBranches()
 * (usa JSON_EXTRACT/REGEXP de MySQL), así que el nivel 1 se alimenta con una base
 * de Radiografía SINTÉTICA con la misma forma que sumGlobal(). La equivalencia
 * con la Radiografía REAL se prueba en tests/Integration (MySQL).
 */
final class EstadoResultadosFixtures
{
    private static int $seq = 0;

    public static function period(string $start = '2026-09-01', string $end = '2026-09-30'): Period
    {
        self::$seq++;

        return Period::query()->create([
            'name' => 'ER prueba '.self::$seq, 'code' => 'M-ER-'.self::$seq, 'type' => 'monthly',
            // sequence único: en MySQL (tablas no InnoDB) el periodo no se revierte y chocaría
            // con la clave única (type, year, month, sequence) de otras pruebas.
            'year' => 2026, 'month' => 9, 'sequence' => 900 + self::$seq,
            'start_date' => $start, 'end_date' => $end, 'is_closed' => false,
        ]);
    }

    public static function branch(string $name): Branch
    {
        return Branch::query()->firstOrCreate(
            ['normalized_name' => mb_strtolower($name)],
            ['code' => mb_substr(strtoupper($name), 0, 4), 'name' => strtoupper($name), 'is_active' => true],
        );
    }

    /** Fila ERP (fact_expenses, fuente gastos_erp) con la misma forma que GastosErpExcelImportService. */
    public static function erp(Period $period, string $branch, string $folio, string $amount, string $fechaPago, string $category = 'Gastos Operativos', string $concept = 'SERVICIOS', ?string $fechaCaptura = null): Expense
    {
        $source = DataSource::query()->firstOrCreate(['code' => 'gastos_erp'], ['name' => 'Gastos ERP', 'description' => 'ERP', 'is_active' => true]);
        $upload = ReportUpload::query()->firstOrCreate(
            ['period_id' => $period->id, 'data_source_id' => $source->id],
            ['original_name' => 'erp.xlsx', 'stored_path' => 'reports/erp.xlsx', 'mime_type' => 'application/vnd.ms-excel', 'file_size' => 10, 'uploaded_at' => now(), 'status' => ReportUploadStatus::Processed, 'notes' => null],
        );

        return Expense::query()->create([
            'period_id' => $period->id,
            'report_upload_id' => $upload->id,
            'branch_id' => self::branch($branch)->id,
            'category' => $category,
            'concept' => $concept,
            'amount' => $amount,
            'paid_amount' => $amount,
            'expense_date' => $fechaPago,
            'observations' => 'Folio: '.$folio,
            'raw_payload' => ['folio' => $folio, 'estatus' => 'PAGADA', 'sucursal_raw' => $branch, 'fecha_pago' => $fechaPago, 'fecha_captura' => $fechaCaptura ?? $fechaPago],
        ]);
    }

    /**
     * Fila ERP con campos adicionales (estatus, observaciones) — misma forma que erp().
     * (Agregado por el endurecimiento de conciliación; no cambia erp().)
     */
    public static function erpWith(Period $period, string $branch, string $folio, string $amount, string $fechaPago, array $extra = []): Expense
    {
        $e = self::erp($period, $branch, $folio, $amount, $fechaPago, $extra['category'] ?? 'Gastos Operativos', $extra['concept'] ?? 'SERVICIOS', $extra['fecha_captura'] ?? null);
        $payload = $e->raw_payload;
        if (isset($extra['estatus'])) {
            $payload['estatus'] = $extra['estatus'];
        }
        $e->forceFill(['raw_payload' => $payload, 'observations' => $extra['observations'] ?? $e->observations])->save();

        return $e;
    }

    /**
     * Colaborador NOI del periodo (employees + employee_branch_assignments + fact_noi_movements,
     * percepción P001 SUELDO) — misma forma que lee Radiografía. $branch null = sin asignación.
     */
    public static function noiEmployee(Period $period, string $fullName, ?string $branch, string $amount): int
    {
        $source = DataSource::query()->firstOrCreate(['code' => 'noi_nomina'], ['name' => 'NOI Nómina', 'description' => 'NOI', 'is_active' => true]);
        $upload = ReportUpload::query()->firstOrCreate(
            ['period_id' => $period->id, 'data_source_id' => $source->id],
            ['original_name' => 'noi.xlsx', 'stored_path' => 'reports/noi.xlsx', 'mime_type' => 'application/vnd.ms-excel', 'file_size' => 10, 'uploaded_at' => now(), 'status' => ReportUploadStatus::Processed, 'notes' => null],
        );
        self::$seq++;
        $employeeId = DB::table('employees')->insertGetId([
            'employee_code' => 'E-ER-'.self::$seq, 'full_name' => $fullName, 'normalized_name' => mb_strtolower($fullName),
            'is_active' => true, 'source_system' => 'noi', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($branch !== null) {
            DB::table('employee_branch_assignments')->insert([
                'period_id' => $period->id, 'employee_id' => $employeeId, 'branch_id' => self::branch($branch)->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('fact_noi_movements')->insert([
            'period_id' => $period->id, 'employee_id' => $employeeId, 'report_upload_id' => $upload->id,
            'concept' => 'P001 SUELDO', 'concept_type' => 'percepcion', 'amount' => $amount, 'quantity' => 1,
            'source_row_key' => 'er-'.self::$seq, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $employeeId;
    }

    /**
     * Sustituye la base de Radiografía por una sintética (sólo pruebas SQLite).
     * $overrides se aplica sobre el GLOBAL; por defecto ingresos 100,000 / OPEX 20,000 / nómina 30,000.
     */
    public static function fakeRadiography(array $overrides = []): array
    {
        $calc = app(BranchRadiographyCalculator::class);
        $branch = array_replace($calc->emptyBranchSummary('CORDOBA'), [
            'interes_recuperado' => 80000.00, 'impuesto_recuperado' => 12800.00, 'charges' => 4200.00,
            'cargos_adicionales' => 1000.00, 'comision_apertura' => 1500.00, 'excedente_recuperado' => 300.00,
            'seguro_crece_reconocido' => 200.00,
            'capital_recuperado' => 900000.00, 'recuperacion_total' => 1000000.00,
            'colocacion' => 750000.00, 'creditos_colocados' => 100, 'valor_cartera' => 3000000.00,
            'gastos_operativos' => 20000.00, 'gastos_detalle' => ['Gasolina' => 12000.00, 'Renta Oficina' => 8000.00],
            'nomina_total' => 25000.00, 'comisiones' => 3000.00, 'imss_patronal' => 2000.00,
        ], $overrides);
        $unassigned = $calc->emptyBranchSummary('SIN ASIGNAR');
        $global = $calc->sumGlobal([$branch], []);
        $base = [
            'global' => $global,
            'branches' => ['CORDOBA' => $branch],
            'unassigned' => $unassigned,
            'cartera' => ['valor_cartera' => 3000000.00, 'cartera_vencida' => 300000.00],
            'rotacion' => ['disponible' => false, 'altas' => 0, 'bajas' => 0, 'plantilla' => 0.0, 'indice' => null, 'por_sucursal' => []],
            'data_ids' => [],
        ];

        $fake = new class($calc, app(PeriodDataIds::class), $base) extends RadiographyBaseProvider
        {
            public function __construct(BranchRadiographyCalculator $c, PeriodDataIds $d, private array $fixed)
            {
                parent::__construct($c, $d);
            }

            public function for(Period $period): array
            {
                return ['data_ids' => app(PeriodDataIds::class)->for($period)] + $this->fixed;
            }
        };
        app()->instance(RadiographyBaseProvider::class, $fake);

        return $base;
    }

    /**
     * Genera un Excel de cuenta administrativa con la estructura real (encabezados
     * FECHA | REFRENCIA BANCA | CARGO | ABONO | SALDO | REQ | CONCEPTO | SUCURSAL | …).
     *
     * @param  list<array{0:string,1:?float,2:?float,3?:?string,4?:?string,5?:?string,6?:?string,7?:?string}>  $rows
     *                                                                                                                [fecha Y-m-d, cargo, abono, req, concepto, sucursal, beneficiario, uuid]
     */
    public static function adminXlsx(array $rows, bool $withControl = true): string
    {
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet()->setTitle('OK');
        $ws->fromArray(['no', 'FECHA', 'REFRENCIA BANCA', 'CARGO', 'ABONO', 'SALDO', 'REQ', 'CONCEPTO', 'SUCURSAL', 'BENEFICIARIO', 'OBSERVACIONES', 'ESTATUS', 'TOTAL', 'CONCEPTO GASTO', 'UUID', 'COMPLEMENTO PAGO'], null, 'A1');
        $ws->fromArray([null, null, 'SALDO INICIAL', null, null, 1000], null, 'A2');
        $r = 3;
        $saldo = 1000.0;
        $tc = 0.0;
        $ta = 0.0;
        foreach ($rows as $i => $row) {
            [$date, $cargo, $abono] = $row;
            $saldo += (float) $abono - (float) $cargo;
            $tc += (float) $cargo;
            $ta += (float) $abono;
            $serial = Date::PHPToExcel(new \DateTime($date));
            $ws->fromArray([null, $serial, 'SPEI ENVIADO REF '.($i + 1), $cargo, $abono, $saldo, $row[3] ?? null, $row[4] ?? null, $row[5] ?? null, $row[6] ?? null, null, null, null, null, $row[7] ?? null], null, 'A'.$r);
            $r++;
        }
        if ($withControl) {
            $ws->fromArray([null, null, 'TOTAL EXCEL:', round($tc, 2), round($ta, 2)], null, 'A'.($r + 1));
        }

        return self::save($book);
    }

    /** Excel de la cuenta de excedentes (FECHA | DESCRIPCION Y REFERENCIA | CARGO | ABONO | SALDO | DETALLE | SUCURSAL | OBSERVACIONES). */
    public static function excedentesXlsx(array $rows): string
    {
        $book = new Spreadsheet;
        $ws = $book->getActiveSheet()->setTitle('EDO CUENTA');
        $ws->fromArray([null, 'FECHA', 'DESCRIPCION Y REFERENCIA', 'CARGO', 'ABONO', 'SALDO', 'DETALLE', 'SUCURSAL', 'OBSERVACIONES'], null, 'A1');
        $r = 2;
        foreach ($rows as $i => $row) {
            $serial = Date::PHPToExcel(new \DateTime($row[0]));
            $ws->fromArray([null, $serial, $row[6] ?? 'DEPOSITO EN EFECTIVO '.($i + 1), $row[1], $row[2], 0, $row[3] ?? null, $row[4] ?? null, $row[5] ?? null], null, 'A'.$r);
            $r++;
        }

        return self::save($book);
    }

    public static function invalidXlsx(): string
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->fromArray([['NOMBRE', 'MONTO'], ['X', 10]], null, 'A1');

        return self::save($book);
    }

    /**
     * Periodo mensual con año/mes de reporte explícitos (comparativas: anterior, año anterior,
     * acumulado). sequence alto para no chocar con otros periodos de prueba.
     * (Agregado por las comparativas; no cambia period().)
     */
    public static function monthPeriod(int $year, int $month, string $start, string $end): Period
    {
        self::$seq++;

        return Period::query()->create([
            'name' => 'ER comparativa '.$year.'-'.$month.' #'.self::$seq, 'code' => 'M-ERC-'.self::$seq, 'type' => 'monthly',
            'year' => $year, 'month' => $month, 'sequence' => 800 + self::$seq,
            'start_date' => $start, 'end_date' => $end, 'is_closed' => false,
        ]);
    }

    /**
     * Base de Radiografía sintética POR PERIODO (sólo pruebas SQLite).
     * $map = [period_id => ['SUCURSAL' => overrides sobre emptyBranchSummary(), …]].
     * Un periodo que no está en el mapa no tiene sucursales ni cifras (= sin datos).
     * Se puede volver a llamar para cambiar cifras (p. ej. para probar snapshot vs en vivo).
     * (Agregado por las comparativas; no cambia fakeRadiography().)
     */
    public static function fakeRadiographyByPeriod(array $map): void
    {
        $calc = app(BranchRadiographyCalculator::class);
        $bases = [];
        foreach ($map as $periodId => $branches) {
            $rows = [];
            foreach ($branches as $name => $overrides) {
                $rows[$name] = array_replace($calc->emptyBranchSummary($name), $overrides);
            }
            $bases[$periodId] = [
                'global' => $calc->sumGlobal(array_values($rows), []),
                'branches' => $rows,
                'unassigned' => $calc->emptyBranchSummary('SIN ASIGNAR'),
                'cartera' => ['valor_cartera' => 0.0, 'cartera_vencida' => 0.0],
                'rotacion' => ['disponible' => false, 'altas' => 0, 'bajas' => 0, 'plantilla' => 0.0, 'indice' => null, 'por_sucursal' => []],
            ];
        }
        $empty = [
            'global' => $calc->sumGlobal([], []),
            'branches' => [],
            'unassigned' => $calc->emptyBranchSummary('SIN ASIGNAR'),
            'cartera' => ['valor_cartera' => 0.0, 'cartera_vencida' => 0.0],
            'rotacion' => ['disponible' => false, 'altas' => 0, 'bajas' => 0, 'plantilla' => 0.0, 'indice' => null, 'por_sucursal' => []],
        ];

        $fake = new class($calc, app(PeriodDataIds::class), $bases, $empty) extends RadiographyBaseProvider
        {
            public function __construct(BranchRadiographyCalculator $c, PeriodDataIds $d, private array $bases, private array $empty)
            {
                parent::__construct($c, $d);
            }

            public function for(Period $period): array
            {
                return ['data_ids' => app(PeriodDataIds::class)->for($period)] + ($this->bases[$period->id] ?? $this->empty);
            }
        };
        app()->instance(RadiographyBaseProvider::class, $fake);
    }

    private static function save(Spreadsheet $book): string
    {
        $dir = storage_path('framework/testing/er');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $path = $dir.'/'.uniqid('er_', true).'.xlsx';
        (new Xlsx($book))->save($path);

        return $path;
    }
}
