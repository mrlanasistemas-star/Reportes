<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Catálogo de roles: una instancia por petición/job (se consulta en cada
        // User::isGerencial()) — 16-oct-2026.
        $this->app->scoped(\App\Services\Access\RoleCatalogService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Schema::defaultStringLength(191);
        $this->configureApiRateLimiting();
    }

    /**
     * Fase 1 (API general de reportería) — límite por CONSUMIDOR (no por IP:
     * varios consumidores podrían compartir salida NAT), 60 req/min por
     * defecto. Nombrado 'api-v1' — ver routes/api.php.
     */
    protected function configureApiRateLimiting(): void
    {
        RateLimiter::for('api-v1', function (\Illuminate\Http\Request $request) {
            $consumerId = $request->user()?->getKey() ?? $request->ip();

            return Limit::perMinute(60)->by('api-v1:' . $consumerId);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
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
