<?php

namespace App\Services\EstadoResultados\Import;

use App\Models\EstadoResultados\EstadoResultadosImportAttempt;
use App\Models\EstadoResultados\EstadoResultadosImportError;
use App\Models\EstadoResultados\EstadoResultadosMovement;
use App\Models\EstadoResultados\EstadoResultadosUpload;
use App\Models\Period;
use App\Services\EstadoResultados\Reporting\EstadoResultadosLockedException;
use App\Services\EstadoResultados\Reporting\PeriodLockGuard;
use App\Services\EstadoResultados\Support\Money;
use App\Services\EstadoResultados\Support\SucursalNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
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

    public const EXTENSIONS = ['xlsx', 'xlsm', 'xls'];

    public function __construct(
        private readonly BankStatementParser $parser,
        private readonly SucursalNormalizer $sucursales,
    ) {}

    public function upload(Period $period, UploadedFile $file, string $slot, ?int $userId): EstadoResultadosUpload
    {
        return $this->importPath($period, (string) $file->getRealPath(), $file->getClientOriginalName(), $slot, $userId, $file);
    }

    /** Máximo permitido por archivo (config `estado_resultados.import.max_mb`, 10 MB por defecto). */
    public static function maxBytes(): int
    {
        return (int) round(self::maxMb() * 1024 * 1024);
    }

    public static function maxMb(): float
    {
        return max(0.001, (float) config('estado_resultados.import.max_mb', 10));
    }

    /**
     * Punto de entrada usado también por pruebas/comandos con una ruta local.
     *
     * Endurecido (06-nov-2026): bloqueo por versión aprobada, validación de
     * extensión / contenido / tamaño, candado por periodo+cuenta (cargas
     * concurrentes), intento registrado con sus errores por fila y copia del
     * original (también si se rechaza). Una carga sólo queda publicada si TODO
     * el archivo es válido y la transacción termina.
     */
    public function importPath(Period $period, string $absolutePath, string $originalName, string $slot, ?int $userId, ?UploadedFile $file = null): EstadoResultadosUpload
    {
        $attempt = EstadoResultadosImportAttempt::query()->create([
            'period_id' => $period->id,
            'account_slot' => mb_substr($slot, 0, 30),
            'original_name' => mb_substr($originalName, 0, 255),
            'extension' => mb_substr(mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION)), 0, 10) ?: null,
            'mime' => is_file($absolutePath) ? (mb_substr((string) (@mime_content_type($absolutePath) ?: ''), 0, 120) ?: null) : null,
            'file_size' => $file?->getSize() ?: (is_file($absolutePath) ? (int) @filesize($absolutePath) : 0),
            'status' => EstadoResultadosImportAttempt::EN_PROCESO,
            'user_id' => $userId,
            'started_at' => now(),
        ]);

        try {
            app(PeriodLockGuard::class)->assertUnlocked($period);
            if (! isset(self::SLOTS[$slot])) {
                throw new EstadoResultadosImportException('Cuenta no válida. Elige "Cuenta administrativa" o "Cuenta de excedentes".');
            }
            if ($period->type !== 'monthly') {
                throw new EstadoResultadosImportException('El Estado de Resultados se carga sobre periodos mensuales. Selecciona un periodo mensual.');
            }
            $this->validateFile($absolutePath, $originalName, $file?->getSize() ?: null);
            $attempt->update(['file_hash' => hash_file('sha256', $absolutePath) ?: null]);

            $lock = Cache::lock('estado_resultados:carga:'.$period->id.':'.$slot, 300);
            if (! $lock->get()) {
                throw new EstadoResultadosImportException(
                    'Ya hay una carga en curso para "'.self::SLOTS[$slot].'" de este periodo. Espera a que termine y vuelve a intentarlo.',
                    [], [], 409,
                );
            }
            try {
                $upload = $this->doImport($period, $absolutePath, $originalName, $slot, $userId, $file);
            } finally {
                $lock->release();
            }
        } catch (EstadoResultadosDuplicateUploadException $e) {
            $this->finishAttempt($attempt, EstadoResultadosImportAttempt::DUPLICADO, $e->getMessage(), [], $e->existing->id);

            throw $e->withAttempt($attempt->id);
        } catch (EstadoResultadosImportException $e) {
            $this->finishAttempt($attempt, EstadoResultadosImportAttempt::RECHAZADO, $e->getMessage(), $e->errors());
            $this->keepRejectedOriginal($attempt, $absolutePath);

            throw $e->withAttempt($attempt->id);
        } catch (EstadoResultadosLockedException $e) {
            $this->finishAttempt($attempt, EstadoResultadosImportAttempt::RECHAZADO, $e->getMessage(), []);

            throw $e;
        } catch (\Throwable $e) {
            $this->finishAttempt($attempt, EstadoResultadosImportAttempt::RECHAZADO, 'Error inesperado al cargar: no se publicó ningún movimiento.', [['row' => null, 'column' => null, 'message' => mb_substr($e->getMessage(), 0, 1000)]]);
            $this->keepRejectedOriginal($attempt, $absolutePath);

            throw $e;
        }

        $this->finishAttempt($attempt, EstadoResultadosImportAttempt::COMPLETADO, 'Archivo cargado: '.$upload->movements_count.' movimientos.', [], $upload->id, $upload->stored_path);

        return $upload;
    }

    /** Extensión permitida, tamaño máximo y contenido real (firma binaria) coherente con la extensión. */
    public function validateFile(string $absolutePath, string $originalName, ?int $reportedSize = null): void
    {
        $ext = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($ext, self::EXTENSIONS, true)) {
            throw new EstadoResultadosImportException(
                'Formato no permitido'.($ext !== '' ? ' (.'.$ext.')' : '').'. Sube el estado de cuenta en Excel: .xlsx, .xlsm o .xls.'
            );
        }
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new EstadoResultadosImportException('No se recibió el archivo completo. Vuelve a intentarlo.');
        }
        $size = max((int) $reportedSize, (int) filesize($absolutePath));
        if ($size === 0) {
            throw new EstadoResultadosImportException('El archivo está vacío.');
        }
        if ($size > self::maxBytes()) {
            throw new EstadoResultadosImportException(
                'El archivo pesa '.number_format($size / 1048576, 1).' MB y excede el máximo permitido de '.rtrim(rtrim(number_format(self::maxMb(), 1), '0'), '.').' MB.',
                ['Un estado de cuenta mensual pesa normalmente menos de 2 MB. Verifica que sea el archivo correcto.'],
            );
        }
        $head = (string) file_get_contents($absolutePath, false, null, 0, 8);
        $isZip = str_starts_with($head, "PK\x03\x04");
        $isOle = $head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
        if (($ext === 'xls' && ! $isOle && ! $isZip) || ($ext !== 'xls' && ! $isZip)) {
            throw new EstadoResultadosImportException(
                'El contenido del archivo no corresponde a un Excel .'.$ext.' (archivo renombrado o dañado).',
                ['Ábrelo en Excel y guárdalo de nuevo como "Libro de Excel (.xlsx)".'],
            );
        }
    }

    /** @return list<array<string,mixed>> intentos recientes con sus errores por fila. */
    public function attempts(Period $period, int $limit = 30): array
    {
        return EstadoResultadosImportAttempt::query()
            ->with(['user:id,name', 'errors' => fn ($q) => $q->orderBy('id')])
            ->where('period_id', $period->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (EstadoResultadosImportAttempt $a) => self::attemptPayload($a))
            ->all();
    }

    public static function attemptPayload(EstadoResultadosImportAttempt $a, int $maxErrors = 200): array
    {
        return [
            'id' => $a->id,
            'slot' => $a->account_slot,
            'slot_label' => self::SLOTS[$a->account_slot] ?? $a->account_slot,
            'file' => $a->original_name,
            'file_hash' => $a->file_hash,
            'file_size' => (int) $a->file_size,
            'status' => $a->status,
            'status_label' => EstadoResultadosImportAttempt::LABELS[$a->status] ?? $a->status,
            'message' => $a->message,
            'errors_count' => (int) $a->errors_count,
            'errors' => $a->errors->take($maxErrors)->map(fn ($e) => ['row' => $e->row_number, 'column' => $e->column_name, 'message' => $e->message])->values()->all(),
            'upload_id' => $a->upload_id,
            'original_kept' => $a->stored_path !== null,
            'user' => $a->user?->name,
            'at' => $a->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
        ];
    }

    /** @param list<array{row:?int, column:?string, message:string}> $errors */
    private function finishAttempt(EstadoResultadosImportAttempt $attempt, string $status, string $message, array $errors, ?int $uploadId = null, ?string $storedPath = null): void
    {
        $now = now();
        $rows = [];
        foreach (array_slice($errors, 0, 1000) as $e) {
            $rows[] = [
                'attempt_id' => $attempt->id,
                'row_number' => $e['row'] ?? null,
                'column_name' => isset($e['column']) ? mb_substr((string) $e['column'], 0, 60) : null,
                'message' => mb_substr((string) $e['message'], 0, 2000),
                'created_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            EstadoResultadosImportError::query()->insert($chunk);
        }
        $attempt->update(array_filter([
            'status' => $status,
            'message' => mb_substr($message, 0, 2000),
            'errors_count' => count($errors),
            'upload_id' => $uploadId,
            'stored_path' => $storedPath,
            'finished_at' => $now,
        ], fn ($v) => $v !== null));
    }

    /** Conserva el original de un intento rechazado (auditoría), si no excede el máximo permitido. */
    private function keepRejectedOriginal(EstadoResultadosImportAttempt $attempt, string $absolutePath): void
    {
        try {
            if (! is_file($absolutePath) || (int) filesize($absolutePath) > self::maxBytes()) {
                return;
            }
            $ext = preg_replace('/[^a-z0-9]/', '', (string) $attempt->extension) ?: 'bin';
            $path = 'estado-resultados/'.$attempt->period_id.'/rechazados/'.$attempt->id.'_'.substr((string) ($attempt->file_hash ?: hash_file('sha256', $absolutePath)), 0, 16).'.'.$ext;
            Storage::disk(self::DISK)->put($path, (string) file_get_contents($absolutePath));
            $attempt->update(['stored_path' => $path]);
        } catch (\Throwable) {
            // Conservar el original es auditoría: nunca debe ocultar el error real de la carga.
        }
    }

    private function doImport(Period $period, string $absolutePath, string $originalName, string $slot, ?int $userId, ?UploadedFile $file): EstadoResultadosUpload
    {
        $hash = hash_file('sha256', $absolutePath) ?: '';
        $duplicate = EstadoResultadosUpload::query()
            ->active()
            ->where('period_id', $period->id)
            ->where('file_hash', $hash)
            ->first();
        if ($duplicate) {
            throw new EstadoResultadosDuplicateUploadException(
                'Este mismo archivo ya está cargado en el periodo (cargado el '.$duplicate->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i').'). No se duplicó ningún movimiento.',
                $duplicate,
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
