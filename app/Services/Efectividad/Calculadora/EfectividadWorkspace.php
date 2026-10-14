<?php

namespace App\Services\Efectividad\Calculadora;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Espacio de trabajo EFÍMERO de la calculadora de Efectividad.
 *
 * Nada se guarda en la base de datos. Cada carga vive en
 * storage/app/private/efectividad-tmp/{token}/ (fuera de `public`), ligada al
 * usuario que la creó, con el contenido ya leído de los Excel CIFRADO
 * (Crypt/APP_KEY). Los Excel originales se borran en cuanto se leen.
 *
 * Se elimina: al pulsar "Limpiar", al cerrar/abandonar la página (beacon), al
 * expirar el TTL (comando `efectividad:limpiar-temporales`, programado cada
 * 10 min) y en cada nueva carga del mismo usuario se purgan sus espacios
 * vencidos. Límite: si el servidor se apaga, los archivos cifrados restantes
 * se borran en la siguiente ejecución del comando programado.
 */
class EfectividadWorkspace
{
    public const TTL_MINUTOS = 60;

    public static function raiz(): string
    {
        return storage_path('app/private/efectividad-tmp');
    }

    public function crear(int $userId): string
    {
        $token = Str::random(40);
        $dir = $this->dir($token);
        File::ensureDirectoryExists($dir, 0700);
        $this->escribir($token, 'meta', ['user_id' => $userId, 'creado' => time(), 'archivos' => []]);

        return $token;
    }

    /** @throws EfectividadArchivoException si no existe, expiró o pertenece a otro usuario */
    public function meta(string $token, int $userId): array
    {
        if (! preg_match('/^[A-Za-z0-9]{40}$/', $token) || ! is_file($this->ruta($token, 'meta'))) {
            throw new EfectividadArchivoException('La sesión de cálculo expiró. Vuelve a cargar tus archivos.');
        }
        $meta = $this->leer($token, 'meta');
        if ((int) ($meta['user_id'] ?? 0) !== $userId) {
            throw new EfectividadArchivoException('La sesión de cálculo expiró. Vuelve a cargar tus archivos.');
        }
        if (time() - (int) $meta['creado'] > self::TTL_MINUTOS * 60) {
            $this->eliminar($token);
            throw new EfectividadArchivoException('La sesión de cálculo expiró. Vuelve a cargar tus archivos.');
        }

        return $meta;
    }

    public function guardarMeta(string $token, array $meta): void
    {
        $this->escribir($token, 'meta', $meta);
    }

    public function escribir(string $token, string $nombre, array $datos): void
    {
        $ruta = $this->ruta($token, $nombre);
        file_put_contents($ruta, Crypt::encryptString(json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)), LOCK_EX);
        @chmod($ruta, 0600);
    }

    public function leer(string $token, string $nombre): ?array
    {
        $ruta = $this->ruta($token, $nombre);
        if (! is_file($ruta)) {
            return null;
        }

        return json_decode(Crypt::decryptString(file_get_contents($ruta)), true, 512, JSON_THROW_ON_ERROR);
    }

    public function borrarParte(string $token, string $nombre): void
    {
        @unlink($this->ruta($token, $nombre));
    }

    public function eliminar(string $token): void
    {
        if (preg_match('/^[A-Za-z0-9]{40}$/', $token)) {
            File::deleteDirectory($this->dir($token));
        }
    }

    /** Borra los espacios vencidos (o de cualquier antigüedad si $minutos = 0). Devuelve cuántos. */
    public function purgarVencidos(int $minutos = self::TTL_MINUTOS): int
    {
        if (! is_dir(self::raiz())) {
            return 0;
        }
        $n = 0;
        foreach (File::directories(self::raiz()) as $dir) {
            if (basename($dir) === 'exports') {
                // PDF/PNG ya enviados se borran al enviarse; aquí solo restos de envíos interrumpidos.
                foreach (File::allFiles($dir) as $f) {
                    if (time() - $f->getMTime() > min($minutos, 10) * 60) {
                        @unlink($f->getPathname());
                    }
                }

                continue;
            }
            if (time() - (int) @filemtime($dir) > $minutos * 60) {
                File::deleteDirectory($dir);
                $n++;
            }
        }
        foreach (File::files(self::raiz()) as $f) {
            if (time() - $f->getMTime() > $minutos * 60) {
                @unlink($f->getPathname());
            }
        }

        return $n;
    }

    private function dir(string $token): string
    {
        return self::raiz().DIRECTORY_SEPARATOR.$token;
    }

    private function ruta(string $token, string $nombre): string
    {
        return $this->dir($token).DIRECTORY_SEPARATOR.preg_replace('/[^a-z0-9_-]/i', '', $nombre).'.bin';
    }
}
