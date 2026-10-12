<?php

namespace Tests\Support;

use App\Enums\ReportUploadStatus;
use App\Models\Branch;
use App\Models\DataSource;
use App\Models\Period;
use App\Models\PortfolioSnapshot;
use App\Models\PortfolioSnapshotRow;
use App\Models\Recovery;
use App\Models\ReportUpload;

/**
 * Datos mínimos y deterministas del motor V4 (BD aislada de pruebas):
 * semana operativa 05-11/10/2026, domingo base 04/10, 8 fotografías.
 *
 * Contratos A-D (CORDOBA / LUIS GOMEZ), cuota semanal de 100 con primer pago el
 * lunes 05/10. Ingresos: A paga el lunes a tiempo (Sano 100); B, C y D pagan la
 * cuota del lunes en martes/miércoles/jueves (Vigente con atraso 100 + 50 + 100).
 * Resultado esperado: Sano por cobrar 400, Sano cobrado 100, Atraso cobrado 250,
 * cobranza total 350, efectividad 87.5%, mora 50.
 */
final class EfectividadV4Fixtures
{
    public const CONTRATOS = ['25COR0000001', '25COR0000002', '25COR0000003', '25COR0000004'];

    public static function week(): Period
    {
        return Period::query()->create([
            'name' => 'Semana 41 2026', 'code' => 'W-V4-2026-41', 'type' => 'weekly',
            'year' => 2026, 'month' => 10, 'sequence' => 41,
            'start_date' => '2026-10-05', 'end_date' => '2026-10-11', 'is_closed' => false,
        ]);
    }

    public static function branch(): Branch
    {
        return Branch::query()->firstOrCreate(['code' => 'COR'], ['name' => 'CORDOBA', 'normalized_name' => 'cordoba', 'is_active' => true]);
    }

    /** @param  list<string>|null  $fechas  fechas con fotografía (por defecto las 8 de la semana) */
    public static function snapshots(Period $week, ?Branch $branch = null, ?array $fechas = null, ?callable $fila = null): void
    {
        $branch ??= self::branch();
        $source = DataSource::query()->firstOrCreate(['code' => 'lendus_saldos_cliente_foto'], ['name' => 'Fotografía', 'is_active' => true]);
        $fechas ??= array_map(fn ($i) => date('Y-m-d', strtotime("2026-10-04 +{$i} days")), range(0, 7));

        foreach ($fechas as $fecha) {
            $upload = ReportUpload::query()->create([
                'period_id' => $week->id, 'covered_period_ids' => [$week->id], 'data_source_id' => $source->id,
                'original_name' => "saldos_{$fecha}.xlsx", 'stored_path' => "reports/saldos_{$fecha}.xlsx", 'file_size' => 1,
                'uploaded_at' => now(), 'status' => ReportUploadStatus::Processed, 'snapshot_date' => $fecha,
            ]);
            $snap = PortfolioSnapshot::query()->create([
                'report_upload_id' => $upload->id, 'period_id' => $week->id, 'snapshot_date' => $fecha, 'status' => 'active',
                'rows_count' => count(self::CONTRATOS), 'branch_ids' => [$branch->id], 'offices' => ['CORDOBA'],
                'headers' => array_keys(\App\Services\Efectividad\V4\EfectividadV4ColumnMap::FOTO), 'file_sha1' => sha1($fecha),
                'importer_version' => 'test', 'imported_at' => now(),
            ]);
            foreach (self::CONTRATOS as $i => $contrato) {
                $row = [
                    'portfolio_snapshot_id' => $snap->id, 'report_upload_id' => $upload->id, 'snapshot_date' => $fecha,
                    'branch_id' => $branch->id, 'source_row' => $i + 2, 'contract' => $contrato, 'acreditado' => ' ',
                    'office_name_raw' => 'CORDOBA', 'promoter_name' => 'LUIS GOMEZ', 'status' => 'ACTIVO', 'substatus' => 'VIGENTE',
                    'product_name_raw' => 'S12', 'periodicity' => 'SEMANAL', 'first_payment_date' => '2026-10-05',
                    'contract_due_date' => '2026-12-21', 'installment_total' => 100, 'num_payments' => 12,
                    'total_overdue' => 0, 'overdue_installments' => 0, 'total_pending' => 1100, 'saldo_capital' => 900,
                ];
                PortfolioSnapshotRow::query()->create($fila ? $fila($row, $fecha, $i) : $row);
            }
        }
    }

    /** Ingresos de la semana (4 días distintos → no requiere confirmación de semana parcial). */
    public static function ingresos(Period $anchor, ?Branch $branch = null, array $override = []): void
    {
        $branch ??= self::branch();
        $pagos = [
            [self::CONTRATOS[0], '2026-10-05', 100],
            [self::CONTRATOS[1], '2026-10-06', 100],
            [self::CONTRATOS[2], '2026-10-07', 50],
            [self::CONTRATOS[3], '2026-10-08', 100],
        ];
        foreach ($pagos as [$contrato, $fecha, $monto]) {
            Recovery::query()->create(array_merge([
                'period_id' => $anchor->id, 'branch_id' => $branch->id, 'contract' => $contrato,
                'promoter_name' => 'LUIS GOMEZ', 'office_name_raw' => 'CORDOBA', 'acreditado' => 'COR1',
                'operation' => 'PAGO A CONTRATO', 'transaction' => 'PAGO', 'product_name' => 'S12', 'product_name_raw' => 'S12',
                'capital' => $monto, 'interest' => 0, 'tax' => 0, 'charges' => 0, 'total_amount' => $monto,
                'payment_date' => $fecha, 'installment_due_date' => '2026-10-05', 'installment_number' => '1',
                'contract_due_date' => '2026-12-21',
            ], $override));
        }
    }

    public static function seedValid(Period $week, ?Branch $branch = null): void
    {
        self::snapshots($week, $branch);
        self::ingresos($week, $branch);
    }
}
