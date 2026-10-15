<?php

namespace App\Services\Okr;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Escritura ATÓMICA del PDF de un documento OKR (4.9, 19-oct-2026).
 *
 * Reglas que garantiza:
 *   - El motor (Browsershot) escribe en un archivo temporal junto al destino;
 *     solo un PDF real y no vacío (cabecera %PDF) se mueve al nombre final.
 *     Un fallo del motor, un archivo vacío/corrupto o un fallo al escribir
 *     NUNCA dejan un "emitido" sin PDF: se lanza la excepción, la transacción
 *     se revierte y el temporal se borra.
 *   - Si la transacción falla DESPUÉS de mover el archivo (p. ej. el índice
 *     único detectó que otro administrador ya emitió), discard() borra el PDF
 *     recién escrito — sin archivos huérfanos.
 */
class OkrDocumentIssuer
{
    /**
     * @param  callable(string $absoluteTmpPath): void  $render
     */
    public function writePdf(string $disk, string $relativePath, callable $render): void
    {
        $storage = Storage::disk($disk);
        $final = $storage->path($relativePath);
        File::ensureDirectoryExists(dirname($final));
        $tmp = dirname($final) . DIRECTORY_SEPARATOR . '.emitiendo-' . bin2hex(random_bytes(4)) . '-' . basename($final);

        try {
            $render($tmp);

            if (!is_file($tmp) || filesize($tmp) < 5 || file_get_contents($tmp, false, null, 0, 4) !== '%PDF') {
                throw new OkrDocumentStorageException('El motor de PDF terminó sin generar un documento válido.');
            }
            if (is_file($final)) {
                // Un PDF previo con el mismo folio solo puede venir de una emisión
                // interrumpida que no llegó a confirmarse — se reemplaza.
                @unlink($final);
            }
            if (!@rename($tmp, $final)) {
                throw new OkrDocumentStorageException('No se pudo guardar el PDF en el almacenamiento de documentos.');
            }
        } catch (Throwable $e) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            throw $e;
        }
    }

    /**
     * Ejecuta $callback con un candado EXCLUSIVO entre procesos (Cache::lock,
     * almacén atómico). Necesario porque en el servidor las tablas OKR pueden
     * ser MyISAM: ahí `lockForUpdate()` y las transacciones NO protegen nada.
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public function exclusively(string $key, callable $callback, int $waitSeconds = 45): mixed
    {
        try {
            return Cache::lock($key, 180)->block($waitSeconds, $callback);
        } catch (LockTimeoutException) {
            throw new \RuntimeException('Otra emisión de un documento de este OKR sigue en curso. Espera unos segundos y vuelve a intentarlo; no se emitió ningún folio nuevo.');
        }
    }

    /**
     * Compensación explícita: borra la fila provisional ("PENDIENTE-…", sin PDF)
     * de una emisión que falló. Con InnoDB la transacción ya la revirtió (no-op);
     * con MyISAM es lo ÚNICO que evita un documento "emitido" sin PDF que además
     * bloquearía (índice de vigencia) cualquier emisión posterior.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     */
    public function forgetUnconfirmed(string $modelClass, ?int $id): void
    {
        if ($id === null) {
            return;
        }

        try {
            $modelClass::query()->whereKey($id)->where('folio', 'like', 'PENDIENTE-%')->delete();
        } catch (Throwable $e) {
            Log::error('OKR: no se pudo borrar la fila provisional de una emisión fallida', ['model' => $modelClass, 'id' => $id, 'error' => $e->getMessage()]);
        }
    }

    /** Borra un PDF que se escribió pero cuya emisión NO se confirmó en BD. */
    public function discard(string $disk, ?string $relativePath): void
    {
        if ($relativePath === null || $relativePath === '') {
            return;
        }

        try {
            Storage::disk($disk)->delete($relativePath);
        } catch (Throwable $e) {
            Log::warning('OKR: no se pudo borrar un PDF de una emisión no confirmada', ['path' => $relativePath, 'error' => $e->getMessage()]);
        }
    }

    /** ¿La excepción es la violación del índice "un solo documento vigente"? */
    public static function isCurrentGuardViolation(Throwable $e): bool
    {
        if (!$e instanceof QueryException) {
            return false;
        }
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $message = $e->getMessage();

        return $sqlState === '23000' || $sqlState === '23505'
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry');
    }
}

