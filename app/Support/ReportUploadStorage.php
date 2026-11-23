<?php

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Almacenamiento de los archivos originales que suben los usuarios
 * (report_uploads: NOI, Lendus, ERP, cartera, IMSS...).
 *
 * Los originales contienen datos personales y financieros de todas las
 * sucursales, así que se guardan en el disco PRIVADO ('local' →
 * storage/app/private), que no tiene symlink público ni URL directa.
 *
 * Compatibilidad hacia atrás: hasta noviembre 2026 se guardaban en el disco
 * 'public' (storage/app/public). Las rutas relativas en BD (stored_path) son
 * las mismas en ambos discos, por lo que toda lectura busca primero en el
 * privado y, si no lo encuentra, en el público. Así producción sigue
 * funcionando antes y después de correr `reportes:mover-cargas-a-privado`.
 */
final class ReportUploadStorage
{
    /** Disco donde se guardan todas las cargas nuevas. */
    public const DISK = 'local';

    /** Disco donde vivían las cargas antes de la corrección (solo lectura/limpieza). */
    public const LEGACY_DISK = 'public';

    /**
     * Disco privado donde se guardan las cargas nuevas.
     *
     * @return FilesystemAdapter Disco 'local'.
     */
    public static function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::disk(self::DISK);
    }

    /**
     * Disco público heredado (solo para leer o limpiar cargas antiguas).
     *
     * @return FilesystemAdapter Disco 'public'.
     */
    public static function legacyDisk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter */
        return Storage::disk(self::LEGACY_DISK);
    }

    /**
     * Guarda el archivo subido en el disco privado y devuelve la ruta relativa.
     *
     * @param  UploadedFile  $file  Archivo recibido en la petición.
     * @param  string  $directory  Carpeta relativa dentro del disco privado.
     * @return string Ruta relativa que se guarda en `report_uploads.stored_path`.
     *
     * @throws \RuntimeException Si el archivo no pudo guardarse.
     */
    public static function store(UploadedFile $file, string $directory = 'reports'): string
    {
        $path = $file->store($directory, self::DISK);

        if (! is_string($path) || $path === '') {
            throw new \RuntimeException('No se pudo guardar el archivo en el almacenamiento privado.');
        }

        return $path;
    }

    /**
     * Disco donde existe físicamente la ruta (privado primero, luego el
     * público heredado). Null si no existe en ninguno.
     *
     * @param  string|null  $path  Ruta relativa guardada en BD.
     * @return FilesystemAdapter|null Disco que contiene el archivo, o null.
     */
    public static function diskFor(?string $path): ?FilesystemAdapter
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (self::disk()->exists($path)) {
            return self::disk();
        }

        if (self::legacyDisk()->exists($path)) {
            return self::legacyDisk();
        }

        return null;
    }

    /**
     * Indica si la ruta existe en el disco privado o en el público heredado.
     *
     * @param  string|null  $path  Ruta relativa guardada en BD.
     * @return bool true si el archivo existe en alguno de los dos discos.
     */
    public static function exists(?string $path): bool
    {
        return self::diskFor($path) !== null;
    }

    /**
     * Ruta absoluta del archivo. Si no existe en ningún disco devuelve la
     * ruta esperada en el disco privado (los llamadores ya validan exists()).
     *
     * @param  string  $path  Ruta relativa guardada en BD.
     * @return string Ruta absoluta en el sistema de archivos.
     */
    public static function path(string $path): string
    {
        return (self::diskFor($path) ?? self::disk())->path($path);
    }

    /**
     * Efecto secundario: borra físicamente el archivo; no toca la BD.
     *
     * Elimina el archivo de ambos discos (privado y público heredado).
     *
     * @param  string|null  $path  Ruta relativa; null o vacía no hace nada.
     */
    public static function delete(?string $path): void
    {
        if ($path === null || $path === '') {
            return;
        }

        foreach ([self::disk(), self::legacyDisk()] as $disk) {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }
}
