<?php

namespace App\Services\EstadoResultados\Import;

use App\Models\EstadoResultados\EstadoResultadosImportAttempt;
use App\Models\EstadoResultados\EstadoResultadosImportError;
use App\Models\EstadoResultados\EstadoResultadosPayrollLine;
use App\Models\EstadoResultados\EstadoResultadosPayrollUpload;
use App\Models\Period;
use App\Services\EstadoResultados\Reporting\EstadoResultadosLockedException;
use App\Services\EstadoResultados\Reporting\PeriodLockGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Carga del NOI de CORPORATIVO (fuente propia del Estado de Resultados).
 *
 * Incremental: sólo inserta los renglones de ESTE archivo en estado_resultados_payroll_lines.
 * No toca estados de cuenta, ERP, NOI de sucursales ni Radiografía, y no recalcula nada más:
 * el Estado de Resultados lo incorpora la próxima vez que se arma el dataset.
 *
 * Idempotencia: el mismo archivo (sha256) ya vigente en el periodo no se vuelve a cargar.
 * Un archivo distinto sustituye al anterior del periodo (queda en el historial como
 * «reemplazado»). Se valida TODO el archivo antes de escribir, en una sola transacción.
 */
class CorporatePayrollUploadService
{
    public const SLOT = EstadoResultadosPayrollUpload::KIND_NOI_CORPORATIVO;

    public const LABEL = 'NOI de corporativo';

    public function __construct(
        private readonly NoiPayrollParser $parser,
        private readonly EstadoResultadosUploadService $files,
    ) {}

    public function upload(Period $period, UploadedFile $file, ?int $userId): array
    {
        return $this->importPath($period, (string) $file->getRealPath(), $file->getClientOriginalName(), $userId, $file);
    }

    /**
     * @return array{upload:EstadoResultadosPayrollUpload, idempotent:bool}
     *
     * @throws EstadoResultadosImportException|EstadoResultadosLockedException
     */
    public function importPath(Period $period, string $absolutePath, string $originalName, ?int $userId, ?UploadedFile $file = null): array
    {
        $attempt = EstadoResultadosImportAttempt::query()->create([
            'period_id' => $period->id,
            'account_slot' => self::SLOT,
            'original_name' => mb_substr($originalName, 0, 255),
            'extension' => mb_substr(mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION)), 0, 10) ?: null,
            'file_size' => $file?->getSize() ?: (is_file($absolutePath) ? (int) @filesize($absolutePath) : 0),
            'status' => EstadoResultadosImportAttempt::EN_PROCESO,
            'user_id' => $userId,
            'started_at' => now(),
        ]);

        try {
            app(PeriodLockGuard::class)->assertUnlocked($period);
            if ($period->type !== 'monthly') {
                throw new EstadoResultadosImportException('El Estado de Resultados se carga sobre periodos mensuales. Selecciona un periodo mensual.');
            }
            $this->files->validateFile($absolutePath, $originalName, $file?->getSize() ?: null);
            $hash = hash_file('sha256', $absolutePath) ?: '';
            $attempt->update(['file_hash' => $hash ?: null]);

            $existing = EstadoResultadosPayrollUpload::query()->active()
                ->where('period_id', $period->id)->where('kind', self::SLOT)->where('file_hash', $hash)->first();
            if ($existing) {
                $this->finish($attempt, EstadoResultadosImportAttempt::DUPLICADO, 'Este mismo NOI de corporativo ya está cargado en el periodo (cargado el '
                    .$existing->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i').'). No se duplicó ningún renglón.');

                return ['upload' => $existing, 'idempotent' => true];
            }

            $lock = Cache::lock('estado_resultados:carga:'.$period->id.':'.self::SLOT, 300);
            if (! $lock->get()) {
                throw new EstadoResultadosImportException('Ya hay una carga en curso del NOI de corporativo de este periodo. Espera a que termine y vuelve a intentarlo.', [], [], 409);
            }
            try {
                $parsed = $this->parser->parse($absolutePath, mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) ?: null);
                $upload = $this->store($period, $parsed, $hash, $absolutePath, $originalName, $userId, $file);
            } finally {
                $lock->release();
            }
        } catch (EstadoResultadosImportException $e) {
            $this->finish($attempt, EstadoResultadosImportAttempt::RECHAZADO, $e->getMessage(), $e->errors());

            throw $e->withAttempt($attempt->id);
        } catch (EstadoResultadosLockedException $e) {
            $this->finish($attempt, EstadoResultadosImportAttempt::RECHAZADO, $e->getMessage());

            throw $e;
        } catch (\Throwable $e) {
            $this->finish($attempt, EstadoResultadosImportAttempt::RECHAZADO, 'Error inesperado al cargar: no se guardó ningún renglón.',
                [['row' => null, 'column' => null, 'message' => mb_substr($e->getMessage(), 0, 1000)]]);

            throw $e;
        }

        $this->finish($attempt, EstadoResultadosImportAttempt::COMPLETADO, 'NOI de corporativo cargado: '.$upload->employees_count.' colaborador(es), percepciones $'
            .number_format((float) $upload->total_percepciones, 2).'.', [], $upload->stored_path);

        return ['upload' => $upload, 'idempotent' => false];
    }

    private function store(Period $period, array $parsed, string $hash, string $absolutePath, string $originalName, ?int $userId, ?UploadedFile $file): EstadoResultadosPayrollUpload
    {
        $ext = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'xlsx';
        $storedPath = 'estado-resultados/'.$period->id.'/noi-corporativo/'.$hash.'.'.$ext;
        if ($file) {
            $storedPath = $file->storeAs('estado-resultados/'.$period->id.'/noi-corporativo', $hash.'.'.($file->getClientOriginalExtension() ?: 'xlsx'), EstadoResultadosUploadService::DISK) ?: null;
        } elseif (is_file($absolutePath)) {
            Storage::disk(EstadoResultadosUploadService::DISK)->put($storedPath, (string) file_get_contents($absolutePath));
        }

        return DB::transaction(function () use ($period, $parsed, $hash, $absolutePath, $originalName, $userId, $storedPath) {
            DB::table('periods')->where('id', $period->id)->lockForUpdate()->first();
            app(PeriodLockGuard::class)->assertUnlocked($period);

            $previous = EstadoResultadosPayrollUpload::query()->active()
                ->where('period_id', $period->id)->where('kind', self::SLOT)->lockForUpdate()->get();

            $upload = EstadoResultadosPayrollUpload::query()->create([
                'period_id' => $period->id,
                'kind' => self::SLOT,
                'original_name' => mb_substr($originalName, 0, 255),
                'file_hash' => $hash,
                'file_size' => (int) @filesize($absolutePath),
                'stored_path' => $storedPath,
                'status' => EstadoResultadosPayrollUpload::STATUS_LOADED,
                'rows_read' => $parsed['rows_read'],
                'lines_count' => count($parsed['lines']),
                'employees_count' => $parsed['employees'],
                'total_percepciones' => $parsed['total_percepciones'],
                'total_deducciones' => $parsed['total_deducciones'],
                'warnings' => $parsed['warnings'],
                'uploaded_by' => $userId,
            ]);

            foreach ($previous as $old) {
                // Sólo los renglones de la carga sustituida: nada más del periodo se borra ni recalcula.
                EstadoResultadosPayrollLine::query()->where('upload_id', $old->id)->delete();
                $old->update(['status' => EstadoResultadosPayrollUpload::STATUS_REPLACED, 'superseded_at' => now(), 'superseded_by_upload_id' => $upload->id]);
            }

            $now = now();
            $rows = array_map(fn ($l) => $l + ['upload_id' => $upload->id, 'period_id' => $period->id, 'created_at' => $now, 'updated_at' => $now], $parsed['lines']);
            foreach (array_chunk($rows, 200) as $chunk) {
                EstadoResultadosPayrollLine::query()->insert($chunk);
            }

            return $upload->fresh();
        });
    }

    private function finish(EstadoResultadosImportAttempt $attempt, string $status, string $message, array $errors = [], ?string $storedPath = null): void
    {
        $now = now();
        foreach (array_chunk(array_slice($errors, 0, 1000), 200) as $chunk) {
            EstadoResultadosImportError::query()->insert(array_map(fn ($e) => [
                'attempt_id' => $attempt->id,
                'row_number' => $e['row'] ?? null,
                'column_name' => isset($e['column']) ? mb_substr((string) $e['column'], 0, 60) : null,
                'message' => mb_substr((string) $e['message'], 0, 2000),
                'created_at' => $now,
            ], $chunk));
        }
        $attempt->update(array_filter([
            'status' => $status,
            'lifecycle' => match ($status) {
                EstadoResultadosImportAttempt::COMPLETADO => 'validado',
                EstadoResultadosImportAttempt::DUPLICADO => 'duplicado',
                default => 'rechazado',
            },
            'validated_at' => $status === EstadoResultadosImportAttempt::COMPLETADO ? $now : null,
            'message' => mb_substr($message, 0, 2000),
            'errors_count' => count($errors),
            'stored_path' => $storedPath,
            'finished_at' => $now,
        ], fn ($v) => $v !== null));
    }

    /** Resumen público de una carga (para la tarjeta de Fuentes). */
    public static function payload(?EstadoResultadosPayrollUpload $u): ?array
    {
        if (! $u) {
            return null;
        }

        return [
            'id' => $u->id, 'name' => $u->original_name, 'hash' => $u->file_hash,
            'employees' => (int) $u->employees_count, 'lines' => (int) $u->lines_count,
            'total_percepciones' => number_format((float) $u->total_percepciones, 2, '.', ''),
            'total_deducciones' => number_format((float) $u->total_deducciones, 2, '.', ''),
            'warnings' => array_values(array_map(fn ($w) => is_array($w) ? (string) ($w['message'] ?? '') : (string) $w, (array) ($u->warnings ?? []))),
            'uploaded_by' => $u->uploader?->name,
            'uploaded_at' => $u->created_at?->timezone('America/Mexico_City')->format('d/m/Y H:i'),
        ];
    }
}
