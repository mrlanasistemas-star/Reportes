<?php

namespace App\Services\EstadoResultados\Import;

use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Models\Period;
use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\SucursalNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Carga de los estados de cuenta complementarios del Estado de Resultados.
 *
 * Flujo propio (no reutiliza ReportUpload/DataSource de Radiografía):
 *   1. sha256 del archivo → si el MISMO archivo ya está activo en el periodo, se rechaza (idempotencia).
 *   2. BankStatementParser valida TODO el archivo antes de escribir.
 *   3. Valida que la cuenta elegida coincida con la estructura y que haya movimientos dentro del periodo.
 *   4. En una sola transacción: reemplaza la carga anterior de la misma cuenta (sus movimientos se
 *      borran; las clasificaciones manuales viven aparte y se re-aplican por huella) e inserta los nuevos.
 */
class EstadoResultadosUploadService
{
    public const SLOTS = [
        BankStatementParser::LAYOUT_ADMINISTRATIVA => 'Cuenta administrativa / corporativa',
        BankStatementParser::LAYOUT_EXCEDENTES => 'Cuenta de excedentes',
    ];

    public const DISK = 'local';

    public function __construct(
        private readonly BankStatementParser $parser,
        private readonly SucursalNormalizer $sucursales,
    ) {}

    public function upload(Period $period, UploadedFile $file, string $slot, ?int $userId): EstadoResultadosUpload
    {
        return $this->importPath($period, $file->getRealPath(), $file->getClientOriginalName(), $slot, $userId, $file);
    }

    /** Punto de entrada usado también por pruebas/comandos con una ruta local. */
    public function importPath(Period $period, string $absolutePath, string $originalName, string $slot, ?int $userId, ?UploadedFile $file = null): EstadoResultadosUpload
    {
        if (! isset(self::SLOTS[$slot])) {
            throw new EstadoResultadosImportException('Cuenta no válida. Elige "Cuenta administrativa" o "Cuenta de excedentes".');
        }
        if ($period->type !== 'monthly') {
            throw new EstadoResultadosImportException('El Estado de Resultados se carga sobre periodos mensuales. Selecciona un periodo mensual.');
        }

        $hash = hash_file('sha256', $absolutePath) ?: '';
        $duplicate = EstadoResultadosUpload::query()
            ->active()
            ->where('period_id', $period->id)
            ->where('file_hash', $hash)
            ->first();
        if ($duplicate) {
            throw new EstadoResultadosImportException(
                'Este mismo archivo ya está cargado en el periodo (cargado el '.$duplicate->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i').'). No se duplicó ningún movimiento.'
            );
        }

        $parsed = $this->parser->parse($absolutePath);

        if ($parsed['layout'] !== $slot) {
            throw new EstadoResultadosImportException(
                'El archivo tiene la estructura de "'.self::SLOTS[$parsed['layout']].'", pero se eligió "'.self::SLOTS[$slot].'".',
                ['Verifica que subiste el archivo en la tarjeta correcta.']
            );
        }

        $start = $period->start_date->format('Y-m-d');
        $end = $period->end_date->format('Y-m-d');
        $inPeriod = 0;
        $dates = [];
        foreach ($parsed['movements'] as $m) {
            $dates[] = $m['movement_date'];
            if ($m['movement_date'] >= $start && $m['movement_date'] <= $end) {
                $inPeriod++;
            }
        }
        if ($inPeriod === 0) {
            throw new EstadoResultadosImportException(
                'Ninguna fecha del archivo cae dentro del periodo '.$period->label.' ('.$period->start_date->format('d/m/Y').' – '.$period->end_date->format('d/m/Y').').',
                ['Fechas del archivo: '.min($dates).' a '.max($dates).'. Selecciona el periodo correcto.']
            );
        }

        $totalCargos = Money::sum(array_column($parsed['movements'], 'cargo'));
        $totalAbonos = Money::sum(array_column($parsed['movements'], 'abono'));
        $warnings = $parsed['warnings'];
        $controlOk = null;
        if ($parsed['control_cargos'] !== null || $parsed['control_abonos'] !== null) {
            $okC = $parsed['control_cargos'] === null || Money::equalsCents($parsed['control_cargos'], $totalCargos);
            $okA = $parsed['control_abonos'] === null || Money::equalsCents($parsed['control_abonos'], $totalAbonos);
            $controlOk = $okC && $okA;
            if (! $controlOk) {
                $warnings[] = 'Los totales leídos no coinciden con los totales de control del archivo: cargos '
                    .Money::str2($totalCargos).' vs '.($parsed['control_cargos'] ?? '—').', abonos '
                    .Money::str2($totalAbonos).' vs '.($parsed['control_abonos'] ?? '—').'.';
            }
        } else {
            $warnings[] = 'El archivo no trae renglón de totales de control ("TOTAL EXCEL"); no se pudo validar contra el banco.';
        }
        $outOfPeriod = count($parsed['movements']) - $inPeriod;
        if ($outOfPeriod > 0) {
            $warnings[] = "{$outOfPeriod} movimiento(s) tienen fecha fuera del periodo ({$start} a {$end}): se conservan para auditoría pero no se suman en este periodo.";
        }

        $storedPath = null;
        $directory = 'estado-resultados/'.$period->id;
        if ($file) {
            $storedPath = $file->storeAs($directory, $hash.'.'.($file->getClientOriginalExtension() ?: 'xlsx'), self::DISK) ?: null;
        } elseif (is_file($absolutePath)) {
            $storedPath = $directory.'/'.$hash.'.'.(pathinfo($originalName, PATHINFO_EXTENSION) ?: 'xlsx');
            Storage::disk(self::DISK)->put($storedPath, (string) file_get_contents($absolutePath));
        }

        return DB::transaction(function () use ($period, $slot, $parsed, $hash, $originalName, $absolutePath, $userId, $storedPath, $totalCargos, $totalAbonos, $controlOk, $warnings, $inPeriod, $start, $end, $dates) {
            $previous = EstadoResultadosUpload::query()
                ->active()
                ->where('period_id', $period->id)
                ->where('account_slot', $slot)
                ->lockForUpdate()
                ->get();

            $upload = EstadoResultadosUpload::query()->create([
                'period_id' => $period->id,
                'account_slot' => $slot,
                'account_label' => $this->detectAccountLabel($parsed['movements'], $originalName),
                'layout' => $parsed['layout'],
                'original_name' => mb_substr($originalName, 0, 255),
                'file_hash' => $hash,
                'file_size' => (int) @filesize($absolutePath),
                'stored_path' => $storedPath,
                'status' => EstadoResultadosUpload::STATUS_LOADED,
                'rows_read' => $parsed['rows_read'],
                'movements_count' => count($parsed['movements']),
                'detail_lines_count' => $parsed['detail_lines'],
                'out_of_period_count' => count($parsed['movements']) - $inPeriod,
                'total_cargos' => Money::str2($totalCargos),
                'total_abonos' => Money::str2($totalAbonos),
                'control_total_cargos' => $parsed['control_cargos'],
                'control_total_abonos' => $parsed['control_abonos'],
                'control_ok' => $controlOk,
                'first_movement_date' => min($dates),
                'last_movement_date' => max($dates),
                'warnings' => array_values($warnings),
                'uploaded_by' => $userId,
            ]);

            foreach ($previous as $old) {
                EstadoResultadosMovement::query()->where('upload_id', $old->id)->delete();
                $old->update([
                    'status' => EstadoResultadosUpload::STATUS_REPLACED,
                    'superseded_at' => now(),
                    'superseded_by_upload_id' => $upload->id,
                ]);
            }

            $now = now();
            $rows = [];
            foreach ($parsed['movements'] as $m) {
                $branch = $this->sucursales->normalize($m['sucursal_raw']);
                $rows[] = [
                    'upload_id' => $upload->id,
                    'period_id' => $period->id,
                    'account_slot' => $slot,
                    'source_row' => $m['source_row'],
                    'fingerprint' => sha1($slot.'|'.$m['fingerprint_base']),
                    'movement_date' => $m['movement_date'],
                    'bank_reference' => $m['bank_reference'],
                    'cargo' => $m['cargo'],
                    'abono' => $m['abono'],
                    'saldo' => $m['saldo'],
                    'folio_raw' => $m['folio_raw'],
                    'folio_normalized' => $m['folio_normalized'],
                    'concept' => $m['concept'],
                    'sucursal_raw' => $m['sucursal_raw'],
                    'branch_name' => $branch['name'],
                    'branch_kind' => $branch['kind'],
                    'beneficiary' => $m['beneficiary'],
                    'observations' => $m['observations'],
                    'status_raw' => $m['status_raw'],
                    'total_raw' => $m['total_raw'],
                    'concept_gasto' => $m['concept_gasto'],
                    'uuid' => $m['uuid'],
                    'complemento_pago' => $m['complemento_pago'],
                    'detail_lines' => json_encode($m['detail_lines'], JSON_UNESCAPED_UNICODE),
                    'raw_payload' => json_encode($m['raw_payload'], JSON_UNESCAPED_UNICODE),
                    'in_period' => $m['movement_date'] >= $start && $m['movement_date'] <= $end,
                    'needs_review' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                EstadoResultadosMovement::query()->insert($chunk);
            }

            return $upload->fresh();
        });
    }

    private function detectAccountLabel(array $movements, string $originalName): ?string
    {
        foreach ($movements as $m) {
            if (preg_match('/\*{3,}(\d{4})/', (string) $m['bank_reference'], $mm)) {
                return 'Cuenta ****'.$mm[1];
            }
        }
        if (preg_match('/(\d{4})(?=\.\w+$)/', $originalName, $mm)) {
            return 'Cuenta ****'.$mm[1];
        }

        return null;
    }
}
