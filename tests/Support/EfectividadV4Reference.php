<?php

namespace Tests\Support;

use App\Enums\ReportUploadStatus;
use App\Models\DataSource;
use App\Models\Period;
use App\Models\ReportUpload;
use App\Services\Efectividad\V4\EfectividadV4ReferenceWorkbook;
use DateTimeImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Apoyo para las pruebas con el libro de referencia MR_LANA_EFECTIVIDAD.xlsm.
 *
 * El libro contiene datos personales y NO se versiona: estas pruebas se omiten
 * (skip) si el archivo no está en `docs/efectividad/`. La lectura del libro se
 * guarda en caché (directorio temporal del sistema) por su sha1.
 */
final class EfectividadV4Reference
{
    public const PATH = 'docs/efectividad/MR_LANA_EFECTIVIDAD.xlsm';

    private static ?array $data = null;

    public static function available(): bool
    {
        return is_file(base_path(self::PATH));
    }

    public static function data(): array
    {
        return self::$data ??= (new EfectividadV4ReferenceWorkbook)->load(base_path(self::PATH), sys_get_temp_dir().'/reportes-efectividad-v4');
    }

    /** Escribe una hoja cruda como XLSX con `$titulos` filas de título previas (como el exportado de Lendus). */
    public static function writeXlsx(array $rows, string $path, int $titulos = 0): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        for ($i = 0; $i < $titulos; $i++) {
            $writer->addRow(Row::fromValues([$i === 0 ? 'MR LANA — Reporte exportado (prueba)' : '']));
        }
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues(array_map(
                fn ($v) => is_array($v) && isset($v['__d']) ? new DateTimeImmutable($v['__d']) : $v,
                $row,
            )));
        }
        $writer->close();
    }

    /** Semana operativa del ejemplo (05-11/10/2026) + semana previa + mes ancla. */
    public static function periods(): array
    {
        $prev = Period::query()->create(['name' => 'Semana 40', 'code' => 'W-2026-40', 'type' => 'weekly', 'year' => 2026, 'month' => 9, 'sequence' => 40, 'start_date' => '2026-09-28', 'end_date' => '2026-10-04']);
        $week = Period::query()->create(['name' => 'Semana 41', 'code' => 'W-2026-41', 'type' => 'weekly', 'year' => 2026, 'month' => 10, 'sequence' => 41, 'start_date' => '2026-10-05', 'end_date' => '2026-10-11']);
        $month = Period::query()->create(['name' => 'Octubre 2026', 'code' => 'M-2026-10', 'type' => 'monthly', 'year' => 2026, 'month' => 10, 'sequence' => 1, 'start_date' => '2026-09-28', 'end_date' => '2026-10-25', 'component_period_ids' => [$prev->id, $week->id]]);

        return compact('prev', 'week', 'month');
    }

    public static function source(string $code): DataSource
    {
        return DataSource::query()->firstOrCreate(['code' => $code], [
            'name' => $code, 'is_active' => true, 'is_required_for_bd' => false, 'is_required_for_report' => false,
        ]);
    }

    public static function upload(string $sourceCode, Period $period, string $storedPath, ?string $snapshotDate = null): ReportUpload
    {
        return ReportUpload::query()->create([
            'period_id' => $period->id,
            'covered_period_ids' => [$period->id],
            'data_source_id' => self::source($sourceCode)->id,
            'original_name' => basename($storedPath),
            'stored_path' => $storedPath,
            'file_size' => 1,
            'uploaded_at' => now(),
            'status' => ReportUploadStatus::Pending,
            'snapshot_date' => $snapshotDate,
        ]);
    }
}
