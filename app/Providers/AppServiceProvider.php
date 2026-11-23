<?php

namespace App\Providers;

use App\Services\Access\RoleCatalogService;
use App\Support\Seo;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

/**
 * Proveedor principal de la aplicación: registra servicios compartidos y
 * configura comportamientos globales (fechas inmutables, bloqueo de comandos
 * destructivos en producción, política de contraseñas, longitud de cadenas,
 * límites de la API y datos SEO del layout).
 */
class AppServiceProvider extends ServiceProvider
{
    /**
     * Registra servicios de la aplicación en el contenedor.
     */
    public function register(): void
    {
        // Catálogo de roles: una instancia por petición/job (se consulta en cada
        // User::isGerencial()) — 16-oct-2026.
        $this->app->scoped(RoleCatalogService::class);
    }

    /**
     * Inicializa la aplicación: valores por defecto, longitud de string 191
     * (índices MySQL utf8mb4), rate limiting de la API y composer SEO de la vista 'app'.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Schema::defaultStringLength(191);
        $this->configureApiRateLimiting();

        // SEO por página (27-oct-2026): título, descripción, robots, canonical,
        // Open Graph y JSON-LD que app.blade.php pinta EN EL SERVIDOR.
        View::composer('app', fn (\Illuminate\View\View $view) => $view->with('seo', Seo::forRequest(request())));
    }

    /**
     * Define 'api-v1' (60/min) y 'api-exports' (20/min), por usuario o, si no hay, por IP.
     *
     * Fase 1 (API general de reportería) — límite por CONSUMIDOR (no por IP:
     * varios consumidores podrían compartir salida NAT), 60 req/min por
     * defecto. Nombrado 'api-v1' — ver routes/api.php.
     */
    protected function configureApiRateLimiting(): void
    {
        RateLimiter::for('api-v1', function (Request $request) {
            $consumerId = $request->user()?->getKey() ?? $request->ip();

            return Limit::perMinute(60)->by('api-v1:'.$consumerId);
        });

        // Límite más estricto para descargas/exportaciones (archivos pesados): 20/min
        // por consumidor, además del límite general 'api-v1' que también aplica.
        RateLimiter::for('api-exports', function (Request $request) {
            $consumerId = $request->user()?->getKey() ?? $request->ip();

            return Limit::perMinute(20)->by('api-exports:'.$consumerId);
        });
    }

    /**
     * Configura comportamientos por defecto para producción: fechas CarbonImmutable,
     * prohíbe comandos destructivos de BD (migrate:fresh, db:wipe...) en producción y,
     * en producción, exige contraseñas de 12+ caracteres con mayúsculas/minúsculas,
     * letras, números, símbolos y no filtradas.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
