<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Verificación posterior al despliegue: detecta lo que deja una versión "a medias"
 * aunque el frontend ya sea nuevo — rutas que el backend no conoce (caché de rutas
 * vieja u OPcache sin recargar: el sidebar muestra Usuarios/Roles y dan 404),
 * migraciones pendientes, páginas que faltan en el build, storage sin permisos o
 * configuración insegura. Sale con código 1 si algo crítico falla.
 *
 *   php artisan reportes:verificar-despliegue
 */
class VerifyDeploymentCommand extends Command
{
    protected $signature = 'reportes:verificar-despliegue';

    protected $description = 'Comprueba rutas, cachés, migraciones, build de Vite, storage, fuentes y configuración después de desplegar.';

    /** Rutas imprescindibles por módulo (si falta una, el módulo da 404). */
    private const CRITICAL_ROUTES = [
        'dashboard', 'usuarios.index', 'roles.index', 'okr.dashboard', 'okr.activation-check', 'okr.activate',
        'reportes-mensuales.index', 'periodos.index', 'efectividad-cobranza.index', 'guia-sistema.index',
        'asignaciones-empleado-sucursal.index',
    ];

    /** Páginas Inertia que deben venir en el build de producción. */
    private const CRITICAL_PAGES = [
        'resources/js/pages/usuarios/Index.vue', 'resources/js/pages/roles/Index.vue', 'resources/js/pages/Dashboard.vue',
        'resources/js/pages/Okr/Show.vue', 'resources/js/pages/EfectividadCobranza/Index.vue',
    ];

    private bool $failed = false;

    public function handle(Migrator $migrator): int
    {
        $this->info('Verificación de despliegue — Reportes MR LANA');

        $this->checkRoutes();
        $this->checkSidebar();
        $this->checkCaches();
        $this->checkMigrations($migrator);
        $this->checkBuild();
        $this->checkStorage();
        $this->checkFonts();
        $this->checkConfig();

        $this->newLine();
        if ($this->failed) {
            $this->error('Hay problemas críticos: corrígelos antes de dar por terminado el despliegue.');

            return self::FAILURE;
        }
        $this->info('Todo en orden. Recuerda recargar PHP-FPM (opcache) y reiniciar los workers de cola tras cada despliegue.');

        return self::SUCCESS;
    }

    private function checkRoutes(): void
    {
        $missing = array_values(array_filter(self::CRITICAL_ROUTES, fn ($n) => ! Route::has($n)));
        $this->report('Rutas críticas', $missing === [], $missing === []
            ? count(self::CRITICAL_ROUTES).' rutas registradas.'
            : 'Faltan: '.implode(', ', $missing).'. Ejecuta `php artisan optimize:clear && php artisan optimize` y recarga PHP-FPM.');
    }

    private function checkSidebar(): void
    {
        $nav = @file_get_contents(resource_path('js/config/mainNav.ts')) ?: '';
        preg_match_all("/href:\s*'([^']+)'/", $nav, $m);
        $broken = [];
        foreach (array_unique($m[1]) as $path) {
            try {
                Route::getRoutes()->match(Request::create($path, 'GET'));
            } catch (Throwable) {
                $broken[] = $path;
            }
        }
        $this->report('Menú lateral', $broken === [], $broken === []
            ? count(array_unique($m[1])).' enlaces con ruta.'
            : 'Enlaces sin ruta (darían 404): '.implode(', ', $broken).'.');
    }

    private function checkCaches(): void
    {
        $newestRoutes = max(array_map('filemtime', glob(base_path('routes/*.php')) ?: [0]));
        if (app()->routesAreCached()) {
            $fresh = filemtime(app()->getCachedRoutesPath()) >= $newestRoutes;
            $this->report('Caché de rutas', $fresh, $fresh ? 'Vigente.' : 'Es más antigua que routes/*.php: ejecuta `php artisan route:cache` (o optimize).');
        } else {
            $this->report('Caché de rutas', true, 'Sin caché (válido; en producción conviene `php artisan optimize`).', warn: app()->isProduction());
        }
        if (app()->configurationIsCached()) {
            $newestConfig = max(array_map('filemtime', array_merge(glob(config_path('*.php')) ?: [], [base_path('.env')])));
            $fresh = filemtime(app()->getCachedConfigPath()) >= $newestConfig;
            $this->report('Caché de configuración', $fresh, $fresh ? 'Vigente.' : 'Es más antigua que config/ o .env: ejecuta `php artisan config:cache`.');
        }
    }

    private function checkMigrations(Migrator $migrator): void
    {
        try {
            $ran = $migrator->getRepository()->getRan();
            $files = array_keys($migrator->getMigrationFiles([database_path('migrations')]));
            $pending = array_values(array_diff($files, $ran));
            $this->report('Migraciones', $pending === [], $pending === []
                ? count($files).' aplicadas, ninguna pendiente.'
                : count($pending).' pendientes: '.implode(', ', array_slice($pending, 0, 5)).'. Ejecuta `php artisan migrate --force`.');
        } catch (Throwable $e) {
            $this->report('Migraciones', false, 'No se pudo consultar la base de datos: revisa DB_* en .env.');
        }
    }

    private function checkBuild(): void
    {
        $manifest = public_path('build/manifest.json');
        if (! is_file($manifest)) {
            $this->report('Build de Vite', false, 'No existe public/build/manifest.json: ejecuta `npm ci && npm run build`.');

            return;
        }
        $entries = json_decode((string) file_get_contents($manifest), true) ?: [];
        $missing = array_values(array_filter(self::CRITICAL_PAGES, fn ($p) => ! array_key_exists($p, $entries)));
        $this->report('Build de Vite', $missing === [], $missing === []
            ? 'Incluye las páginas críticas ('.count($entries).' entradas).'
            : 'El build no incluye: '.implode(', ', $missing).'. Vuelve a compilar con `npm run build`.');
    }

    private function checkStorage(): void
    {
        $dirs = [storage_path('app/private'), storage_path('framework/cache'), storage_path('framework/sessions'), storage_path('framework/views'), storage_path('logs'), base_path('bootstrap/cache')];
        $bad = array_values(array_filter($dirs, fn ($d) => ! is_dir($d) || ! is_writable($d)));
        $this->report('Permisos de escritura', $bad === [], $bad === [] ? 'storage/ y bootstrap/cache escribibles.' : 'Sin escritura: '.implode(', ', $bad).'.');
        $public = storage_path('app/public');
        $sensitive = is_dir($public) ? array_filter(glob($public.'/*') ?: [], fn ($p) => preg_match('/report|upload|noi|lendus|okr|carta|warning|export|efectividad/i', basename($p))) : [];
        $denied = is_file($public.'/.htaccess') && str_contains((string) file_get_contents($public.'/.htaccess'), 'denied');
        // Los originales viven en storage/app/public/{reports,report_uploads}: el symlink
        // public/storage los expondría. Apache los bloquea con .htaccess; en Nginx se exige
        // `location ^~ /storage/ { deny all; }` (docs/despliegue-vps-limpio.md, sección 10).
        $this->report('Archivos sensibles públicos', $sensitive === [] || $denied, $sensitive === []
            ? 'Nada sensible en storage/app/public.'
            : 'Hay originales en storage/app/public ('.implode(', ', array_map('basename', $sensitive)).'). '
                .($denied ? 'Bloqueados por .htaccess; en Nginx confirma `location ^~ /storage/ { deny all; }`.' : 'Falta storage/app/public/.htaccess y el bloqueo en Nginx: quedarían descargables.'), warn: $denied);
    }

    private function checkFonts(): void
    {
        $fonts = glob(resource_path('fonts/montserrat/*.woff2')) ?: [];
        $this->report('Montserrat local', count($fonts) > 0, count($fonts) > 0 ? count($fonts).' archivos de fuente para PDF.' : 'Faltan resources/fonts/montserrat/*.woff2: los PDF saldrían sin Montserrat.');
    }

    private function checkConfig(): void
    {
        $prod = app()->isProduction();
        $this->report('APP_DEBUG', ! ($prod && config('app.debug')), $prod && config('app.debug') ? 'APP_DEBUG=true en producción expone trazas: ponlo en false.' : 'Correcto.');
        $url = (string) config('app.url');
        $this->report('APP_URL', ! $prod || str_starts_with($url, 'https://'), $url !== '' ? $url : 'Vacío.', warn: true);
        $queue = (string) config('queue.default');
        $this->report('Cola', ! $prod || $queue !== 'sync', "QUEUE_CONNECTION={$queue}".($prod && $queue === 'sync' ? ': en producción usa database/redis con Supervisor.' : '.'), warn: true);
        $mailer = (string) config('mail.default');
        $this->report('Correo', ! in_array($mailer, ['log', 'array'], true) || ! $prod, "MAIL_MAILER={$mailer}".($prod && in_array($mailer, ['log', 'array'], true) ? ': las invitaciones no saldrían.' : '.'), warn: true);
        $chrome = (string) config('services.browsershot.chrome_path', env('BROWSERSHOT_CHROME_PATH', ''));
        $this->report('Chrome para PDF', true, $chrome !== '' ? "Configurado: {$chrome}. Prueba completa: `php artisan reports:pdf-health`." : 'Sin ruta explícita; prueba con `php artisan reports:pdf-health`.', warn: true);
    }

    private function report(string $label, bool $ok, string $detail, bool $warn = false): void
    {
        if (! $ok && ! $warn) {
            $this->failed = true;
        }
        $tag = $ok ? '<info>OK   </info>' : ($warn ? '<comment>AVISO</comment>' : '<error>FALLA</error>');
        $this->line(sprintf('%s %-28s %s', $tag, $label, $detail));
    }
}
