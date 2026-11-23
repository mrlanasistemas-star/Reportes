<?php

namespace App\Console\Commands;

use App\Support\ReportUploadStorage;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Comando `reportes:mover-cargas-a-privado` — mueve los archivos originales que los usuarios
 * subieron (y que hasta noviembre 2026 se guardaban en el disco público storage/app/public) al
 * disco privado storage/app/private, conservando la misma ruta relativa.
 *
 * - report_uploads.stored_path no cambia (la ruta relativa es la misma); la lectura busca
 *   primero en el privado y luego en el público.
 * - okr_evidences con disk = 'public' (evidencias anteriores a sept-2026) se mueven y su
 *   columna disk pasa a 'local'.
 *
 * Idempotente: un archivo que ya está en el privado con el mismo contenido solo se limpia del
 * público; si el contenido difiere NO se toca y se reporta como conflicto para revisión manual.
 * Al final informa (sin tocarlos) los archivos públicos que la BD no referencia.
 *
 * Cuándo usarlo: una vez por entorno tras desplegar el cambio a disco privado; correr antes con
 * `--dry-run`.
 * Escribe en BD: SÍ (solo `okr_evidences.disk`); mueve y borra archivos en disco salvo
 * `--dry-run` (`--keep-public` conserva la copia pública).
 *
 * Ejemplos:
 *   php artisan reportes:mover-cargas-a-privado --dry-run
 *   php artisan reportes:mover-cargas-a-privado
 */
class MoveUploadsToPrivateCommand extends Command
{
    protected $signature = 'reportes:mover-cargas-a-privado
                            {--dry-run : Solo muestra lo que haría, sin mover ni borrar nada}
                            {--keep-public : Copia al privado pero conserva la copia pública (no recomendado)}';

    protected $description = 'Mueve los archivos subidos del disco público (storage/app/public) al privado (storage/app/private) conservando la ruta';

    /** @var array<string, int> */
    private array $totals = ['movidos' => 0, 'ya_privados' => 0, 'limpiados' => 0, 'faltantes' => 0, 'conflictos' => 0, 'errores' => 0];

    /**
     * Recorre las rutas de report_uploads y las evidencias OKR del disco público, mueve cada archivo,
     * imprime la tabla de totales y los restos no referenciados.
     *
     * @return int SUCCESS si no hubo conflictos ni errores; FAILURE en caso contrario.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $keepPublic = (bool) $this->option('keep-public');

        $private = ReportUploadStorage::disk();
        $public = ReportUploadStorage::legacyDisk();

        $this->info(($dryRun ? '[SIMULACIÓN] ' : '').'Origen: '.$public->path('').'  →  Destino: '.$private->path(''));

        // 1) Cargas de reportes (sin global scopes: incluye fotografías de cartera).
        $paths = DB::table('report_uploads')
            ->whereNotNull('stored_path')
            ->where('stored_path', '!=', '')
            ->orderBy('id')
            ->pluck('stored_path')
            ->unique()
            ->values();

        $referenced = $paths->all();

        $this->line("report_uploads: {$paths->count()} ruta(s) registradas.");
        foreach ($paths as $path) {
            $this->moveOne($public, $private, (string) $path, $dryRun, $keepPublic);
        }

        // 2) Evidencias OKR heredadas en el disco público.
        if (Schema::hasTable('okr_evidences') && Schema::hasColumn('okr_evidences', 'disk')) {
            $evidences = DB::table('okr_evidences')
                ->where(fn ($q) => $q->where('disk', ReportUploadStorage::LEGACY_DISK)->orWhereNull('disk'))
                ->whereNotNull('stored_path')
                ->orderBy('id')
                ->get(['id', 'stored_path']);

            $this->line("okr_evidences en disco público: {$evidences->count()}.");
            foreach ($evidences as $evidence) {
                $referenced[] = (string) $evidence->stored_path;
                $result = $this->moveOne($public, $private, (string) $evidence->stored_path, $dryRun, $keepPublic);
                if (! $dryRun && in_array($result, ['movido', 'ya_privado'], true)) {
                    DB::table('okr_evidences')->where('id', $evidence->id)->update(['disk' => ReportUploadStorage::DISK]);
                }
            }
        }

        $this->newLine();
        $this->table(['Movidos', 'Ya en privado', 'Copias públicas limpiadas', 'Sin archivo físico', 'Conflictos', 'Errores'], [[
            $this->totals['movidos'], $this->totals['ya_privados'], $this->totals['limpiados'],
            $this->totals['faltantes'], $this->totals['conflictos'], $this->totals['errores'],
        ]]);

        if ($dryRun) {
            $this->warn('Simulación: no se movió ni borró nada. Repite sin --dry-run para aplicar.');
        }

        $leftovers = $this->publicLeftovers($public, $referenced);
        if ($leftovers !== []) {
            $this->warn('Quedan en storage/app/public archivos no referenciados por la BD (no se tocan): '.implode(', ', array_slice($leftovers, 0, 10)).(count($leftovers) > 10 ? ' …' : ''));
        }

        return ($this->totals['conflictos'] + $this->totals['errores']) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Mueve un archivo del disco público al privado verificando el SHA-256 de la copia; si ya existe
     * en el privado con el mismo contenido solo limpia la copia pública, y si difiere lo reporta como
     * conflicto. Actualiza los contadores de `$totals`.
     *
     * @param  FilesystemAdapter  $public  Disco público heredado (origen).
     * @param  FilesystemAdapter  $private  Disco privado (destino).
     * @param  string  $path  Ruta relativa del archivo.
     * @param  bool  $dryRun  true para solo simular.
     * @param  bool  $keepPublic  true para conservar la copia pública.
     * @return 'movido'|'ya_privado'|'faltante'|'conflicto'|'error' Resultado de la operación.
     */
    private function moveOne(FilesystemAdapter $public, FilesystemAdapter $private, string $path, bool $dryRun, bool $keepPublic): string
    {
        $inPublic = $public->exists($path);
        $inPrivate = $private->exists($path);

        if (! $inPublic && ! $inPrivate) {
            $this->totals['faltantes']++;
            $this->line("  <fg=yellow>sin archivo</>  {$path}", null, 'v');

            return 'faltante';
        }

        if (! $inPublic) {
            $this->totals['ya_privados']++;

            return 'ya_privado';
        }

        if ($inPrivate) {
            if (hash_file('sha256', $public->path($path)) !== hash_file('sha256', $private->path($path))) {
                $this->totals['conflictos']++;
                $this->error("  conflicto    {$path} existe en ambos discos con contenido distinto; revísalo a mano.");

                return 'conflicto';
            }

            $this->totals['ya_privados']++;
            if (! $keepPublic) {
                $this->totals['limpiados']++;
                $this->line("  limpiar      {$path} (copia pública idéntica)", null, 'v');
                if (! $dryRun) {
                    $public->delete($path);
                }
            }

            return 'ya_privado';
        }

        $this->line("  mover        {$path}", null, 'v');
        if ($dryRun) {
            $this->totals['movidos']++;

            return 'movido';
        }

        try {
            $source = $public->path($path);
            $target = $private->path($path);
            $dir = dirname($target);
            if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                throw new \RuntimeException("No se pudo crear {$dir}");
            }
            if (! copy($source, $target)) {
                throw new \RuntimeException('copy() falló');
            }
            if (hash_file('sha256', $source) !== hash_file('sha256', $target)) {
                @unlink($target);
                throw new \RuntimeException('la copia no coincide con el original');
            }
            @touch($target, (int) filemtime($source));
            if (! $keepPublic) {
                $public->delete($path);
            }
        } catch (\Throwable $e) {
            $this->totals['errores']++;
            $this->error("  error        {$path}: {$e->getMessage()}");

            return 'error';
        }

        $this->totals['movidos']++;

        return 'movido';
    }

    /**
     * Archivos en las carpetas de cargas del disco público que la BD no
     * referencia (restos de pruebas o cargas borradas). Solo se informan.
     *
     * @param  list<string>  $referenced
     * @return list<string>
     */
    private function publicLeftovers(FilesystemAdapter $public, array $referenced): array
    {
        $referenced = array_flip($referenced);
        $files = [];
        foreach (['reports', 'report_uploads', 'okr-evidences'] as $dir) {
            if ($public->directoryExists($dir)) {
                $files = array_merge($files, $public->allFiles($dir));
            }
        }

        return array_values(array_filter($files, fn ($f) => ! str_starts_with(basename($f), '.') && ! isset($referenced[$f])));
    }
}
