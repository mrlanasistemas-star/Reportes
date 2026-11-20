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
 *   3. Valida que la cuenta elegida coincida con la estructura. Las fechas NO se rechazan: cada
 *      movimiento se asigna al periodo mensual de su fecha (MovementPeriodAllocator, 17-nov-2026).
 *   4. En una sola transacción: reemplaza la carga anterior de la misma cuenta (sus movimientos se
 *      borran; las clasificaciones manuales viven aparte y se re-aplican por huella) e inserta los nuevos.
 *
 * Fase 2 (06-nov-2026): inspección de seguridad del contenedor (SpreadsheetSafetyInspector),
 * avisos con severidad (informativa | bloqueante: un control que no cuadra bloquea la
 * aprobación), ciclo de vida del archivo (recibido → validado → procesado → conciliado |
 * rechazado | duplicado | reemplazado) y re-chequeo de versión aprobada / duplicado DENTRO
 * de la transacción con la fila del periodo bloqueada.
 */
class EstadoResultadosUploadService
{
    public const SLOTS = [
        BankStatementParser::LAYOUT_ADMINISTRATIVA => 'Cuenta administrativa / corporativa',
        BankStatementParser::LAYOUT_EXCEDENTES => 'Cuenta de excedentes',
    ];

    /**
     * Archivos por cuenta (20-nov-2026): el 1 es obligatorio; el 2 es OPCIONAL (nunca se exige,
     * nunca aparece como faltante). Un movimiento idéntico en ambos se cuenta una sola vez.
     */
    public const PARTS = [
        1 => 'Archivo 1 (obligatorio)',
        2 => 'Archivo 2 (opcional)',
    ];

    public const OPTIONAL_PART = 2;

    public const DISK = 'local';

    public const EXTENSIONS = ['xlsx', 'xlsm', 'xls'];

    public function __construct(
        private readonly BankStatementParser $parser,
        private readonly SucursalNormalizer $sucursales,
        private readonly MovementPeriodAllocator $allocator,
    ) {}

    public function upload(Period $period, UploadedFile $file, string $slot, ?int $userId, int $part = 1): EstadoResultadosUpload
    {
        return $this->importPath($period, (string) $file->getRealPath(), $file->getClientOriginalName(), $slot, $userId, $file, $part);
    }

    /** «Cuenta administrativa / corporativa · Archivo 2 (opcional)». */
    public static function slotPartLabel(string $slot, int $part): string
    {
        return (self::SLOTS[$slot] ?? $slot).' · '.(self::PARTS[$part] ?? 'Archivo '.$part);
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
    public function importPath(Period $period, string $absolutePath, string $originalName, string $slot, ?int $userId, ?UploadedFile $file = null, int $part = 1): EstadoResultadosUpload
    {
        $attempt = EstadoResultadosImportAttempt::query()->create([
            'period_id' => $period->id,
            'account_slot' => mb_substr($slot, 0, 30),
            'file_part' => isset(self::PARTS[$part]) ? $part : 1,
            'original_name' => mb_substr($originalName, 0, 255),
            'extension' => mb_substr(mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION)), 0, 10) ?: null,
            'mime' => is_file($absolutePath) ? (mb_substr((string) (@mime_content_type($absolutePath) ?: ''), 0, 120) ?: null) : null,
            'file_size' => $file?->getSize() ?: (is_file($absolutePath) ? (int) @filesize($absolutePath) : 0),
            'status' => EstadoResultadosImportAttempt::EN_PROCESO,
            'lifecycle' => EstadoResultadosUpload::LIFECYCLE_RECIBIDO,
            'user_id' => $userId,
            'started_at' => now(),
        ]);

        try {
            app(PeriodLockGuard::class)->assertUnlocked($period);
            if (! isset(self::SLOTS[$slot])) {
                throw new EstadoResultadosImportException('Cuenta no válida. Elige "Cuenta administrativa" o "Cuenta de excedentes".');
            }
            if (! isset(self::PARTS[$part])) {
                throw new EstadoResultadosImportException('Archivo no válido. Elige «Archivo 1» o «Archivo 2» de la cuenta.');
            }
            if ($period->type !== 'monthly') {
                throw new EstadoResultadosImportException('El Estado de Resultados se carga sobre periodos mensuales. Selecciona un periodo mensual.');
            }
            $this->validateFile($absolutePath, $originalName, $file?->getSize() ?: null);
            $attempt->update(['file_hash' => hash_file('sha256', $absolutePath) ?: null]);

            // Candado por periodo+cuenta+archivo ANTES del chequeo de duplicado y del parseo. release() de
            // Laravel sólo libera si el dueño (token) coincide: un candado vencido y retomado por
            // otra carga no se suelta por error. La consistencia final la garantiza la transacción
            // (fila del periodo bloqueada + re-chequeo de versión aprobada y de duplicado).
            $lock = Cache::lock(self::lockKey($period, $slot, $part), 300);
            if (! $lock->get()) {
                throw new EstadoResultadosImportException(
                    'Ya hay una carga en curso para "'.self::slotPartLabel($slot, $part).'" de este periodo. Espera a que termine y vuelve a intentarlo.',
                    [], [], 409,
                );
            }
            try {
                $upload = $this->doImport($period, $absolutePath, $originalName, $slot, $userId, $file, $part);
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

        $this->finishAttempt($attempt, EstadoResultadosImportAttempt::COMPLETADO, 'Archivo cargado: '.$upload->movements_count.' movimientos.'.($upload->blocking_warnings > 0 ? ' Tiene '.$upload->blocking_warnings.' aviso(s) que bloquean la aprobación.' : ''), [], $upload->id, $upload->stored_path);

        return $upload;
    }

    private static function lockKey(Period $period, string $slot, int $part): string
    {
        // El archivo 1 conserva la llave de siempre; el 2 tiene la suya (candados independientes).
        return 'estado_resultados:carga:'.$period->id.':'.$slot.($part === 1 ? '' : ':'.$part);
    }

    /**
     * Quita el archivo 2 (opcional) de una cuenta. Igual que una sustitución: la carga queda
     * como reemplazada (sigue en el historial) y se borran sus movimientos que no estén en un
     * periodo aprobado. Después se re-sincroniza el reparto por fecha: si el archivo 1 trae
     * los mismos movimientos (antes «duplicados»), vuelven a contar UNA vez.
     */
    public function removePart(Period $period, string $slot, int $part, ?int $userId): EstadoResultadosUpload
    {
        app(PeriodLockGuard::class)->assertUnlocked($period);
        if (! isset(self::SLOTS[$slot])) {
            throw new EstadoResultadosImportException('Cuenta no válida. Elige "Cuenta administrativa" o "Cuenta de excedentes".');
        }
        if ($part !== self::OPTIONAL_PART) {
            throw new EstadoResultadosImportException('Sólo se puede quitar el «Archivo 2 (opcional)». El archivo 1 es obligatorio: si está mal, sustitúyelo por el correcto.');
        }

        $lock = Cache::lock(self::lockKey($period, $slot, $part), 300);
        if (! $lock->get()) {
            throw new EstadoResultadosImportException(
                'Ya hay una carga en curso para "'.self::slotPartLabel($slot, $part).'" de este periodo. Espera a que termine y vuelve a intentarlo.',
                [], [], 409,
            );
        }
        try {
            return $this->allocator->exclusive(fn () => DB::transaction(function () use ($period, $slot, $part) {
                DB::table('periods')->where('id', $period->id)->lockForUpdate()->first();
                app(PeriodLockGuard::class)->assertUnlocked($period);
                $active = EstadoResultadosUpload::query()->active()
                    ->where('period_id', $period->id)->where('account_slot', $slot)->where('file_part', $part)
                    ->lockForUpdate()->get();
                if ($active->isEmpty()) {
                    throw new EstadoResultadosImportException('La cuenta «'.self::SLOTS[$slot].'» no tiene «Archivo 2» cargado en este periodo: no hay nada que quitar.', [], [], 404);
                }
                $wasProcessed = $active->contains(fn ($u) => $u->status === EstadoResultadosUpload::STATUS_PROCESSED);
                foreach ($active as $old) {
                    $this->retire($old, null);
                }
                // Lo que estaba conciliado junto con el archivo quitado debe volver a cruzarse:
                // la cuenta queda «cargada, falta procesar».
                if ($wasProcessed) {
                    EstadoResultadosUpload::query()->active()->where('period_id', $period->id)->where('account_slot', $slot)
                        ->where('status', EstadoResultadosUpload::STATUS_PROCESSED)
                        ->update(['status' => EstadoResultadosUpload::STATUS_LOADED, 'lifecycle' => EstadoResultadosUpload::LIFECYCLE_VALIDADO]);
                }
                if (MovementPeriodAllocator::available()) {
                    $this->allocator->syncInTransaction();
                }

                return $active->first()->fresh();
            }));
        } finally {
            $lock->release();
        }
    }

    /** Retira una carga (sustituida o quitada): borra sus movimientos no congelados y la marca reemplazada. */
    private function retire(EstadoResultadosUpload $old, ?int $supersededBy): void
    {
        $guard = app(PeriodLockGuard::class);
        // Los movimientos que ya viven en un periodo APROBADO se conservan (el periodo aprobado
        // no cambia); el resto se borra.
        $lockedIds = EstadoResultadosMovement::query()->where('upload_id', $old->id)->distinct()->pluck('period_id')
            ->filter(fn ($pid) => $guard->isLocked((int) $pid))->values()->all();
        EstadoResultadosMovement::query()->where('upload_id', $old->id)
            ->when($lockedIds !== [], fn ($q) => $q->whereNotIn('period_id', $lockedIds))->delete();
        $old->update([
            'status' => EstadoResultadosUpload::STATUS_REPLACED,
            'lifecycle' => EstadoResultadosUpload::LIFECYCLE_REEMPLAZADO,
            'superseded_at' => now(),
            'superseded_by_upload_id' => $supersededBy,
        ]);
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
            ->with(['user:id,name', 'upload', 'errors' => fn ($q) => $q->orderBy('id')])
            ->where('period_id', $period->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (EstadoResultadosImportAttempt $a) => self::attemptPayload($a))
            ->all();
    }

    public static function attemptPayload(EstadoResultadosImportAttempt $a, int $maxErrors = 200): array
    {
        $upload = $a->upload_id ? $a->upload : null;
        $detail = $upload?->warningsDetailed() ?? [];
        $blocking = $upload ? $upload->blockingWarningsList() : [];
        $lifecycle = $a->lifecycle ?? match ($a->status) {
            EstadoResultadosImportAttempt::COMPLETADO => EstadoResultadosUpload::LIFECYCLE_VALIDADO,
            EstadoResultadosImportAttempt::RECHAZADO => EstadoResultadosUpload::LIFECYCLE_RECHAZADO,
            EstadoResultadosImportAttempt::DUPLICADO => EstadoResultadosUpload::LIFECYCLE_DUPLICADO,
            default => EstadoResultadosUpload::LIFECYCLE_RECIBIDO,
        };
        // Un intento validado sigue el ciclo de su carga (procesado / conciliado / reemplazado).
        if ($upload && $a->status === EstadoResultadosImportAttempt::COMPLETADO && $upload->lifecycle) {
            $lifecycle = $upload->lifecycle;
        }

        return [
            'lifecycle' => $lifecycle,
            'lifecycle_label' => EstadoResultadosUpload::lifecycleLabel($lifecycle),
            'warnings' => array_column($detail, 'message'),
            'warnings_detail' => $detail,
            'blocking_warnings' => $blocking,
            'has_macros' => (bool) $upload?->has_macros,
            'id' => $a->id,
            'slot' => $a->account_slot,
            'part' => $part = (int) ($a->file_part ?? 1),
            'slot_label' => isset(self::SLOTS[$a->account_slot])
                ? ($part === 1 ? self::SLOTS[$a->account_slot] : self::slotPartLabel($a->account_slot, $part))
                : ($a->account_slot === CorporatePayrollUploadService::SLOT ? CorporatePayrollUploadService::LABEL : $a->account_slot),
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
            'lifecycle' => match ($status) {
                EstadoResultadosImportAttempt::COMPLETADO => EstadoResultadosUpload::LIFECYCLE_VALIDADO,
                EstadoResultadosImportAttempt::DUPLICADO => EstadoResultadosUpload::LIFECYCLE_DUPLICADO,
                default => EstadoResultadosUpload::LIFECYCLE_RECHAZADO,
            },
            'validated_at' => $status === EstadoResultadosImportAttempt::COMPLETADO ? $now : null,
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

    private function doImport(Period $period, string $absolutePath, string $originalName, string $slot, ?int $userId, ?UploadedFile $file, int $part = 1): EstadoResultadosUpload
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

        // La extensión sale del nombre original (el temporal de PHP no la tiene).
        $parsed = $this->parser->parse($absolutePath, mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) ?: null);

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
        // 17-nov-2026: ya NO se rechaza un archivo cuyas fechas no caen en el periodo elegido.
        // Los estados de cuenta van por mes calendario y los periodos son de 4 semanas: cada
        // movimiento se asigna al periodo de SU fecha (MovementPeriodAllocator) y el aviso de
        // la carga dice cuántos entran en cada periodo.

        $totalCargos = Money::sum(array_column($parsed['movements'], 'cargo'));
        $totalAbonos = Money::sum(array_column($parsed['movements'], 'abono'));
        $warnings = $parsed['warnings_detail'];
        $controlOk = null;
        if ($parsed['control_cargos'] !== null || $parsed['control_abonos'] !== null) {
            $okC = $parsed['control_cargos'] === null || Money::equalsCents($parsed['control_cargos'], $totalCargos);
            $okA = $parsed['control_abonos'] === null || Money::equalsCents($parsed['control_abonos'], $totalAbonos);
            $controlOk = $okC && $okA;
            if (! $controlOk) {
                // BLOQUEANTE: lo leído no es lo que el banco reporta ⇒ no se puede aprobar.
                $warnings[] = ['code' => 'control_no_cuadra', 'severity' => EstadoResultadosUpload::SEVERITY_BLOCKING, 'message' => 'Los totales leídos no coinciden con los totales de control del archivo: cargos '
                    .Money::str2($totalCargos).' vs '.($parsed['control_cargos'] ?? '—').', abonos '
                    .Money::str2($totalAbonos).' vs '.($parsed['control_abonos'] ?? '—').'.'];
            }
        } else {
            // INFORMATIVA: sin renglón de control no hay contra qué comparar; queda visible.
            $warnings[] = ['code' => 'sin_totales_control', 'severity' => EstadoResultadosUpload::SEVERITY_INFO, 'message' => 'El archivo no trae renglón de totales de control ("TOTAL EXCEL"); no se pudo validar contra el banco.'];
        }
        $blocking = count(array_filter($warnings, fn ($w) => $w['severity'] === EstadoResultadosUpload::SEVERITY_BLOCKING));

        $storedPath = null;
        $directory = 'estado-resultados/'.$period->id;
        if ($file) {
            $storedPath = $file->storeAs($directory, $hash.'.'.($file->getClientOriginalExtension() ?: 'xlsx'), self::DISK) ?: null;
        } elseif (is_file($absolutePath)) {
            $storedPath = $directory.'/'.$hash.'.'.(pathinfo($originalName, PATHINFO_EXTENSION) ?: 'xlsx');
            Storage::disk(self::DISK)->put($storedPath, (string) file_get_contents($absolutePath));
        }

        return $this->allocator->exclusive(fn () => DB::transaction(function () use ($period, $slot, $part, $parsed, $hash, $originalName, $absolutePath, $userId, $storedPath, $totalCargos, $totalAbonos, $controlOk, $warnings, $blocking, $inPeriod, $start, $end, $dates) {
            // Serializa contra aprobación/procesamiento: fila del periodo bloqueada y RE-CHEQUEO
            // dentro de la transacción (cierra la carrera «se aprobó mientras se leía el archivo»).
            DB::table('periods')->where('id', $period->id)->lockForUpdate()->first();
            app(PeriodLockGuard::class)->assertUnlocked($period);
            $dup = EstadoResultadosUpload::query()->active()->where('period_id', $period->id)->where('file_hash', $hash)->lockForUpdate()->first();
            if ($dup) {
                throw new EstadoResultadosDuplicateUploadException('Este mismo archivo ya está cargado en el periodo. No se duplicó ningún movimiento.', $dup);
            }

            $previous = EstadoResultadosUpload::query()
                ->active()
                ->where('period_id', $period->id)
                ->where('account_slot', $slot)
                // Sustituir sólo reemplaza el MISMO archivo (1 o 2) de la cuenta.
                ->where('file_part', $part)
                ->lockForUpdate()
                ->get();

            $upload = EstadoResultadosUpload::query()->create([
                'period_id' => $period->id,
                'account_slot' => $slot,
                'file_part' => $part,
                'account_label' => $this->detectAccountLabel($parsed['movements'], $originalName),
                'layout' => $parsed['layout'],
                'original_name' => mb_substr($originalName, 0, 255),
                'file_hash' => $hash,
                'file_size' => (int) @filesize($absolutePath),
                'stored_path' => $storedPath,
                'status' => EstadoResultadosUpload::STATUS_LOADED,
                'lifecycle' => EstadoResultadosUpload::LIFECYCLE_VALIDADO,
                'validated_at' => now(),
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
                'warnings' => array_values(array_column($warnings, 'message')),
                'warnings_detail' => array_values($warnings),
                'blocking_warnings' => $blocking,
                'has_macros' => (bool) ($parsed['has_macros'] ?? false),
                'uploaded_by' => $userId,
            ]);

            foreach ($previous as $old) {
                $this->retire($old, $upload->id);
            }

            $now = now();
            $rows = [];
            $placement = MovementPeriodAllocator::available();
            // Llave temporal única: el reparto por fecha (abajo) la deja en 0 para el vigente.
            $tempKey = (1 << 40) + ($upload->id << 20);
            foreach ($parsed['movements'] as $i => $m) {
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
                ] + ($placement ? ['placement' => MovementPeriodAllocator::SIN_PERIODO, 'dedup_key' => $tempKey + $i] : []);
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                EstadoResultadosMovement::query()->insert($chunk);
            }

            if ($placement) {
                // Cada movimiento va al periodo de SU fecha (sin duplicar lo que ya trae otro archivo).
                $this->allocator->syncInTransaction();
                $this->describePlacement($upload, $period);
            }

            return $upload->fresh();
        }));
    }

    /** Agrega a la carga el aviso de en qué periodo quedó cada movimiento (por su fecha). */
    private function describePlacement(EstadoResultadosUpload $upload, Period $period): void
    {
        $messages = [];
        $outside = 0;
        foreach ($this->allocator->breakdownForUpload($upload->id) as $b) {
            $n = $b['count'];
            if ($b['placement'] === MovementPeriodAllocator::PERIODO && $b['period_id'] === $period->id) {
                $messages[] = "{$n} movimiento(s) entran en {$period->label} por su fecha.";

                continue;
            }
            $outside += $n;
            $messages[] = match ($b['placement']) {
                MovementPeriodAllocator::PERIODO => "{$n} movimiento(s) tienen fecha de {$b['label']}: se suman en ese periodo, no en {$period->label}.",
                MovementPeriodAllocator::DUPLICADO => "{$n} movimiento(s) ya vienen en otro archivo cargado: se cuentan una sola vez (no se duplican).",
                MovementPeriodAllocator::PERIODO_CERRADO => "{$n} movimiento(s) caen en un periodo ya aprobado: no se suman.",
                MovementPeriodAllocator::PERIODO_EXISTENTE => "{$n} movimiento(s) caen en un periodo que ya estaba cargado antes: ese periodo no se modifica y no se suman.",
                default => "{$n} movimiento(s) tienen fecha sin periodo mensual creado: se sumarán solos cuando exista ese periodo.",
            };
        }
        $extra = array_map(fn ($m) => ['code' => 'asignacion_por_fecha', 'severity' => EstadoResultadosUpload::SEVERITY_INFO, 'message' => $m], $messages);
        $detail = array_values(array_merge((array) $upload->warnings_detail, $extra));
        $upload->update([
            'warnings' => array_values(array_column($detail, 'message')),
            'warnings_detail' => $detail,
            'out_of_period_count' => $outside,
        ]);
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
