<?php

namespace App\Services\Okr;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Model;
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
     * Genera el PDF en un temporal junto al destino, valida que sea un PDF real
     * (cabecera %PDF, tamaño mínimo) y lo mueve al nombre final. Si ya existía
     * un archivo con ese nombre (emisión interrumpida) se reemplaza.
     *
     * Efecto: escribe en el disco de almacenamiento indicado; ante cualquier
     * fallo borra el temporal y relanza la excepción.
     *
     * @param  string  $disk  Disco de Storage donde se guardan los documentos.
     * @param  string  $relativePath  Ruta final relativa al disco.
     * @param  callable(string $absoluteTmpPath): void  $render  Función que escribe el PDF en la ruta temporal absoluta recibida.
     *
     * @throws OkrDocumentStorageException Si el PDF no es válido o no se pudo mover.
     * @throws Throwable Cualquier error del motor de render se relanza.
     */
    public function writePdf(string $disk, string $relativePath, callable $render): void
    {
        $storage = Storage::disk($disk);
        $final = $storage->path($relativePath);
        File::ensureDirectoryExists(dirname($final));
        $tmp = dirname($final).DIRECTORY_SEPARATOR.'.emitiendo-'.bin2hex(random_bytes(4)).'-'.basename($final);

        try {
            $render($tmp);

            if (! is_file($tmp) || filesize($tmp) < 5 || file_get_contents($tmp, false, null, 0, 4) !== '%PDF') {
                throw new OkrDocumentStorageException('El motor de PDF terminó sin generar un documento válido.');
            }
            if (is_file($final)) {
                // Un PDF previo con el mismo folio solo puede venir de una emisión
                // interrumpida que no llegó a confirmarse — se reemplaza.
                @unlink($final);
            }
            if (! @rename($tmp, $final)) {
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
     * El candado dura 180 s; si no se obtiene en $waitSeconds se aborta.
     *
     * @template T
     *
     * @param  string  $key  Nombre del candado (normalmente por Objective/tipo de documento).
     * @param  callable(): T  $callback  Trabajo a ejecutar bajo el candado.
     * @param  int  $waitSeconds  Segundos máximos de espera por el candado.
     * @return T Resultado del callback.
     *
     * @throws \RuntimeException Si otra emisión mantiene el candado más allá de la espera.
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
     * Solo borra si el folio sigue siendo provisional; los errores se registran
     * en log y no se propagan.
     *
     * @param  class-string<Model>  $modelClass  Modelo del documento (carta o warning).
     * @param  int|null  $id  Id de la fila provisional; null no hace nada.
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

    /**
     * Borra un PDF que se escribió pero cuya emisión NO se confirmó en BD.
     * Los errores se registran como warning y no se propagan.
     *
     * @param  string  $disk  Disco de Storage.
     * @param  string|null  $relativePath  Ruta del PDF; null/vacía no hace nada.
     */
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

    /**
     * ¿La excepción es la violación del índice "un solo documento vigente"?
     * Reconoce SQLSTATE 23000 (MySQL/SQLite) / 23505 (PostgreSQL) y los mensajes
     * típicos de duplicado.
     *
     * @param  Throwable  $e  Excepción capturada.
     * @return bool true si es una violación de unicidad de BD.
     */
    public static function isCurrentGuardViolation(Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $message = $e->getMessage();

        return $sqlState === '23000' || $sqlState === '23505'
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry');
    }
}
