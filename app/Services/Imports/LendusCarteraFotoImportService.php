<?php

namespace App\Services\Imports;

use App\Models\Branch;
use App\Models\PortfolioSnapshot;
use App\Models\PortfolioSnapshotRow;
use App\Models\ReportUpload;
use App\Services\BranchResolverService;
use App\Services\Efectividad\V4\EfectividadV4Calculator;
use App\Services\Efectividad\V4\EfectividadV4ColumnMap;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;

/**
 * Importa una FOTOGRAFÍA DIARIA de cartera (mismo reporte "Saldos por Cliente"
 * de Lendus, identificado con `report_uploads.snapshot_date`) para la
 * Efectividad de Cobranza V4.
 *
 * Diferencias deliberadas con LendusSaldosClienteImportService (cartera
 * mensual de la radiografía, que NO se toca):
 *  - Se guardan TODAS las filas con contrato: la macro V4 también usa
 *    contratos cerrados (estado del día), productos excluidos (para excluir sus
 *    pagos) y saldos negativos (suma de componentes Vencido).
 *  - Montos sin redondear (DOUBLE) y texto original de cada columna.
 *  - Se registra qué encabezados traía el archivo: una columna ausente no
 *    equivale a una columna con cero (así razona la macro).
 *
 * La fecha de la fotografía la declara el usuario; se contrasta con
 * "Fecha última transacción" (en el libro de referencia su máximo coincide
 * exactamente con la fecha de cada foto): una transacción POSTERIOR a la fecha
 * declarada hace la fotografía incompatible y se rechaza.
 */
class LendusCarteraFotoImportService
{
    public const IMPORTER_VERSION = 'cartera_foto_v4_2026_10';

    /** Columnas adicionales (no las usa la macro) para validar la fecha declarada. */
    private const EXTRA = [
        'fecha_ultima_transaccion' => ['Fecha última transacción', 'Fecha ultima transaccion'],
    ];

    public function __construct(private readonly BranchResolverService $branchResolver) {}

    public function handle(ReportUpload $upload, ?callable $progress = null): array
    {
        if (! $upload->snapshot_date) {
            throw new RuntimeException('La fotografía de cartera no tiene fecha (snapshot_date).');
        }
        if (! $upload->stored_path || ! Storage::disk('public')->exists($upload->stored_path)) {
            throw new RuntimeException('El archivo físico de la fotografía de cartera no existe en el almacenamiento.');
        }
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        $path = Storage::disk('public')->path($upload->stored_path);
        $fecha = $upload->snapshot_date->format('Y-m-d');
        $calc = new EfectividadV4Calculator;

        $reader = new Reader(new Options(SHOULD_FORMAT_DATES: false));
        $reader->open($path);

        $map = null;
        $headerRow = null;
        $sourceColumns = null;
        $buffer = [];
        $stats = ['rows_read' => 0, 'rows_inserted' => 0, 'rows_skipped' => 0, 'rows_with_errors' => 0];
        $branches = [];
        $offices = [];
        $maxUltimaTransaccion = null;
        $snapshot = null;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $n = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $n++;
                    $values = array_map(fn ($c) => $c->getValue(), $row->cells);

                    if ($map === null) {
                        if ($n > 30) {
                            throw new RuntimeException('No se encontró la fila de encabezados de Saldos por Cliente (se esperaba "Contrato", "Oficina" y "Substatus" en las primeras 30 filas).');
                        }
                        $candidate = EfectividadV4ColumnMap::resolver($values, EfectividadV4ColumnMap::FOTO + self::EXTRA);
                        if (isset($candidate['contrato'], $candidate['oficina'], $candidate['substatus'])) {
                            $faltantes = array_diff(EfectividadV4Calculator::COLUMNAS_FOTO_OBLIGATORIAS, array_keys($candidate));
                            if ($faltantes) {
                                throw new RuntimeException('El archivo no contiene columnas obligatorias para la Efectividad V4: '.implode(', ', $faltantes).'.');
                            }
                            $map = $candidate;
                            $headerRow = $n;
                            $sourceColumns = count(array_filter($values, fn ($v) => trim((string) $v) !== ''));
                            $snapshot = $this->crearSnapshot($upload, $fecha, $map, $headerRow, $sourceColumns, $path);
                        }

                        continue;
                    }

                    $contrato = trim((string) $this->v($values, $map, 'contrato'), ' ');
                    if ($contrato === '') {
                        $stats['rows_skipped']++;

                        continue;
                    }
                    $stats['rows_read']++;

                    $ultima = $this->fecha($this->v($values, $map, 'fecha_ultima_transaccion'));
                    if ($ultima !== null && ($maxUltimaTransaccion === null || $ultima > $maxUltimaTransaccion)) {
                        $maxUltimaTransaccion = $ultima;
                    }

                    $oficina = $this->texto($this->v($values, $map, 'oficina'));
                    $promotorCodigo = $this->texto($this->v($values, $map, 'promotor_codigo'));
                    $branch = $this->resolverSucursal($contrato, $promotorCodigo, $oficina);
                    if ($branch) {
                        $branches[$branch->id] = true;
                    }
                    if ($oficina !== null) {
                        $offices[$calc->normalizar($oficina)] = $oficina;
                    }

                    $buffer[] = [
                        'portfolio_snapshot_id' => $snapshot->id,
                        'report_upload_id' => $upload->id,
                        'snapshot_date' => $fecha,
                        'branch_id' => $branch?->id,
                        'source_row' => $n,
                        'contract' => $contrato,
                        'acreditado' => $this->texto($this->v($values, $map, 'acreditado'), false),
                        'client_code' => $this->texto($this->v($values, $map, 'cliente')),
                        'office_name_raw' => $oficina,
                        'promoter_code' => $promotorCodigo,
                        'promoter_name' => $this->texto($this->v($values, $map, 'nombre_promotor')),
                        'status' => $this->texto($this->v($values, $map, 'estatus')),
                        'substatus' => $this->texto($this->v($values, $map, 'substatus')),
                        'product_name_raw' => $this->texto($this->v($values, $map, 'producto')),
                        'origin' => $this->texto($this->v($values, $map, 'origen')),
                        'periodicity' => $this->texto($this->v($values, $map, 'periodicidad'), false),
                        'first_payment_date' => $this->fecha($this->v($values, $map, 'fecha_primer_pago')),
                        'contract_due_date' => $this->fecha($this->v($values, $map, 'fecha_vencimiento')),
                        'next_payment_date' => $this->fecha($this->v($values, $map, 'fecha_siguiente_pago')),
                        'installment_total' => $this->numero($this->v($values, $map, 'cuota_total')),
                        'num_payments' => $this->numero($this->v($values, $map, 'pagos')),
                        'total_overdue' => $this->numero($this->v($values, $map, 'total_atrasado')),
                        'overdue_installments' => $this->numero($this->v($values, $map, 'pagos_atrasados')),
                        'total_pending' => $this->numero($this->v($values, $map, 'total_pendiente')),
                        'saldo_capital' => $this->numero($this->v($values, $map, 'saldo_capital')),
                        'saldo_interes' => $this->numero($this->v($values, $map, 'saldo_interes')),
                        'saldo_impuesto' => $this->numero($this->v($values, $map, 'saldo_impuesto')),
                        'saldo_cargos_calendario' => $this->numero($this->v($values, $map, 'saldo_cargos_calendario')),
                        'saldo_impuesto_cargos_calendario' => $this->numero($this->v($values, $map, 'saldo_impuesto_cargos_calendario')),
                        'current_balance' => $this->numero($this->v($values, $map, 'saldo_actual')),
                        'days_overdue' => $this->entero($this->v($values, $map, 'dias_atraso')),
                        'days_past_due' => $this->entero($this->v($values, $map, 'dias_vencido')),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    if (count($buffer) >= 500) {
                        PortfolioSnapshotRow::query()->insert($buffer);
                        $stats['rows_inserted'] += count($buffer);
                        $buffer = [];
                        $progress && $progress($stats + ['log' => "Fotografía {$fecha}: {$stats['rows_inserted']} contratos guardados."]);
                    }
                }
                break; // solo la primera hoja (formato del exportado de Lendus)
            }
        } catch (\Throwable $e) {
            if ($snapshot !== null) {
                $this->descartar($snapshot);
            }

            throw $e;
        } finally {
            $reader->close();
        }

        if ($map === null || $snapshot === null) {
            throw new RuntimeException('El archivo está vacío o no tiene el formato de Saldos por Cliente.');
        }
        if ($buffer) {
            PortfolioSnapshotRow::query()->insert($buffer);
            $stats['rows_inserted'] += count($buffer);
        }
        if ($stats['rows_inserted'] === 0) {
            $this->descartar($snapshot);
            throw new RuntimeException('Cartera sin datos: el archivo no contiene contratos.');
        }
        if ($maxUltimaTransaccion !== null && $maxUltimaTransaccion > $fecha) {
            $this->descartar($snapshot);
            throw new RuntimeException(sprintf(
                'Fecha incompatible: la fotografía se declaró del %s pero contiene transacciones del %s. Verifica la fecha del archivo descargado.',
                $this->dmy($fecha), $this->dmy($maxUltimaTransaccion),
            ));
        }

        $branchIds = array_map('intval', array_keys($branches));
        sort($branchIds);
        $snapshot->forceFill([
            'rows_count' => $stats['rows_inserted'],
            'branch_ids' => $branchIds,
            'offices' => array_values($offices),
            'status' => PortfolioSnapshot::STATUS_ACTIVE,
            'imported_at' => now(),
        ])->save();

        $reemplazadas = $this->reemplazarAnteriores($snapshot);

        $aviso = $maxUltimaTransaccion !== null && $maxUltimaTransaccion < date('Y-m-d', strtotime($fecha.' -2 days'))
            ? sprintf(' Aviso: la transacción más reciente del archivo es del %s; confirma que la fotografía corresponde al %s.', $this->dmy($maxUltimaTransaccion), $this->dmy($fecha))
            : '';

        return $stats + [
            'log' => sprintf(
                'Fotografía de cartera del %s importada: %d contratos, %d oficina(s), %d sucursal(es).%s%s',
                $this->dmy($fecha), $stats['rows_inserted'], count($offices), count($branchIds),
                $reemplazadas ? " Sustituye a {$reemplazadas} fotografía(s) anterior(es) de la misma fecha." : '',
                $aviso,
            ),
            'snapshot_id' => $snapshot->id,
            'max_ultima_transaccion' => $maxUltimaTransaccion,
        ];
    }

    /**
     * Bitácora apta para la UI: una excepción genérica del lector (OpenSpout) puede
     * traer la ruta física del archivo; nunca se muestra una ruta del servidor.
     */
    public static function mensajePublico(?string $log): ?string
    {
        if ($log === null) {
            return null;
        }
        foreach ([storage_path(), base_path()] as $ruta) {
            $log = str_replace([$ruta, str_replace('\\', '/', $ruta)], '', $log);
        }

        return preg_replace('#(?:[A-Za-z]:)?[\\\\/](?:[^\s\\\\/:"\']+[\\\\/])+([^\s\\\\/:"\']+\.(?:xlsx|xlsm|xls|csv))#u', '$1', $log);
    }

    private function crearSnapshot(ReportUpload $upload, string $fecha, array $map, int $headerRow, int $sourceColumns, string $path): PortfolioSnapshot
    {
        // Reproceso del mismo archivo: se reemplazan sus filas anteriores.
        $previas = PortfolioSnapshot::query()->where('report_upload_id', $upload->id)->pluck('id');
        if ($previas->isNotEmpty()) {
            PortfolioSnapshotRow::query()->whereIn('portfolio_snapshot_id', $previas)->delete();
            PortfolioSnapshot::query()->whereIn('id', $previas)->delete();
        }

        return PortfolioSnapshot::query()->create([
            'report_upload_id' => $upload->id,
            'period_id' => $upload->period_id,
            'snapshot_date' => $fecha,
            'status' => 'importing',
            'headers' => array_keys($map),
            'header_row' => $headerRow,
            'source_columns' => $sourceColumns,
            'file_sha1' => sha1_file($path) ?: null,
            'importer_version' => self::IMPORTER_VERSION,
        ]);
    }

    private function descartar(PortfolioSnapshot $snapshot): void
    {
        PortfolioSnapshotRow::query()->where('portfolio_snapshot_id', $snapshot->id)->delete();
        $snapshot->delete();
    }

    /** Una fotografía nueva de la misma fecha sustituye a las anteriores que cubren alguna de sus sucursales. */
    private function reemplazarAnteriores(PortfolioSnapshot $nueva): int
    {
        $propias = array_map('intval', $nueva->branch_ids ?? []);
        $n = 0;
        $otras = PortfolioSnapshot::query()->active()
            ->whereDate('snapshot_date', $nueva->snapshot_date->format('Y-m-d'))
            ->where('id', '!=', $nueva->id)
            ->get();
        foreach ($otras as $otra) {
            $suyas = array_map('intval', $otra->branch_ids ?? []);
            if (! $propias || ! $suyas || array_intersect($propias, $suyas)) {
                DB::table('portfolio_snapshots')->where('id', $otra->id)->update([
                    'status' => PortfolioSnapshot::STATUS_REPLACED,
                    'replaced_by_id' => $nueva->id,
                    'updated_at' => now(),
                ]);
                $n++;
            }
        }

        return $n;
    }

    private function resolverSucursal(string $contrato, ?string $promotorCodigo, ?string $oficina): ?Branch
    {
        if ($branch = $this->branchResolver->findOrCreateBranchByCode($contrato)) {
            return $branch;
        }
        if ($promotorCodigo && ($branch = $this->branchResolver->findOrCreateBranchByCode($promotorCodigo))) {
            return $branch;
        }
        if ($oficina) {
            $real = $this->branchResolver->resolveRealBranchFromRoute($oficina);

            return $real ? $this->branchResolver->findOrCreateBranchByName($real) : $this->branchResolver->resolveOfficialBranchByName($oficina);
        }

        return null;
    }

    private function v(array $values, array $map, string $campo): mixed
    {
        return isset($map[$campo]) ? ($values[$map[$campo]] ?? null) : null;
    }

    private function texto(mixed $v, bool $nullSiVacio = true): ?string
    {
        if ($v === null) {
            return null;
        }
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        $s = is_float($v) && floor($v) === $v ? (string) (int) $v : (string) $v;
        $s = mb_substr($s, 0, 160);

        return $nullSiVacio && trim($s) === '' ? null : $s;
    }

    private function numero(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }

        return (new EfectividadV4Calculator)->numero($v);
    }

    private function entero(mixed $v): ?int
    {
        $n = $this->numero($v);

        return $n === null ? null : (int) round($n);
    }

    private function fecha(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if ($v instanceof DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        $dia = (new EfectividadV4Calculator)->leerFecha($v);

        return $dia === null ? null : gmdate('Y-m-d', $dia * 86400);
    }

    private function dmy(string $ymd): string
    {
        return date('d/m/Y', strtotime($ymd));
    }
}
