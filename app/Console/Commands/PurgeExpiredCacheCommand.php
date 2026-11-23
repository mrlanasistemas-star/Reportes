<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comando `reportes:purgar-cache-vencida` — purga las filas VENCIDAS de la caché en base de
 * datos (23-nov-2026).
 *
 * Con CACHE_STORE=database Laravel sólo borra una fila vencida cuando alguien vuelve a leer esa
 * misma clave: las claves que ya nadie pide (firmas de días anteriores, periodos viejos,
 * candados huérfanos) se quedan para siempre y la tabla `cache` crece sin límite.
 *
 * Seguro: sólo borra filas cuyo `expiration` ya pasó — exactamente las que Laravel ya trata
 * como inexistentes —, nunca una clave vigente. Borra por lotes de claves (portable
 * MySQL/MariaDB/SQLite, sin un DELETE masivo que bloquee la tabla). Con Redis (u otro store)
 * no hace nada: Redis expira sus claves solo.
 *
 * Cuándo usarlo: lo ejecuta el scheduler diario a las 03:10.
 * Escribe en BD: SÍ (borra filas vencidas de `cache` y `cache_locks`).
 *
 * Ejemplos:
 *   php artisan reportes:purgar-cache-vencida
 *   php artisan reportes:purgar-cache-vencida --store=database --lote=500
 */
class PurgeExpiredCacheCommand extends Command
{
    protected $signature = 'reportes:purgar-cache-vencida
        {--store= : Store de caché a purgar (por defecto, el configurado en CACHE_STORE)}
        {--lote=1000 : Claves por lote}';

    protected $description = 'Borra de la tabla de caché (store database) sólo las filas ya vencidas, incluidos candados vencidos.';

    /**
     * Verifica que el store sea de base de datos y purga la tabla de caché y la de candados.
     *
     * @return int Siempre self::SUCCESS.
     */
    public function handle(): int
    {
        $storeName = (string) ($this->option('store') ?: config('cache.default'));
        $store = (array) config("cache.stores.{$storeName}", []);

        if (($store['driver'] ?? null) !== 'database') {
            $this->info("El store «{$storeName}» no es de base de datos (driver: ".($store['driver'] ?? 'desconocido').'): no hay nada que purgar.');

            return self::SUCCESS;
        }

        $batch = max(100, (int) $this->option('lote'));
        $now = time();
        $table = (string) ($store['table'] ?? 'cache');
        $lockTable = (string) ($store['lock_table'] ?? '') ?: $table.'_locks';

        $cache = $this->purge($store['connection'] ?? null, $table, $now, $batch);
        $locks = $this->purge($store['lock_connection'] ?? $store['connection'] ?? null, $lockTable, $now, $batch);

        $this->info("Caché vencida eliminada: {$cache} fila(s) de «{$table}» y {$locks} candado(s) vencido(s) de «{$lockTable}».");

        return self::SUCCESS;
    }

    /**
     * Borra por lotes las filas con `expiration` vencida de una tabla, re-verificando el vencimiento
     * al borrar para no tocar claves renovadas entre la lectura y el borrado.
     *
     * @param  string|null  $connection  Conexión de BD del store (null = predeterminada).
     * @param  string  $table  Tabla de caché o de candados.
     * @param  int  $now  Marca de tiempo Unix de corte.
     * @param  int  $batch  Claves por lote.
     * @return int Filas eliminadas (0 si la tabla o la columna expiration no existen).
     */
    private function purge(?string $connection, string $table, int $now, int $batch): int
    {
        $schema = Schema::connection($connection);
        if (! $schema->hasTable($table) || ! $schema->hasColumn($table, 'expiration')) {
            return 0;
        }

        $db = DB::connection($connection);
        $total = 0;
        do {
            $keys = $db->table($table)->where('expiration', '<=', $now)->limit($batch)->pluck('key')->all();
            if ($keys === []) {
                break;
            }
            // Se vuelve a exigir el vencimiento: si otra petición renovó la clave entre la lectura
            // y el borrado, esa fila (ya vigente) no se toca.
            $total += $db->table($table)->whereIn('key', $keys)->where('expiration', '<=', $now)->delete();
        } while (count($keys) === $batch);

        return $total;
    }
}
