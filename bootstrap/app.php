<?php

use App\Http\Middleware\AddRobotsHeader;
use App\Http\Middleware\Api\EnsureApiAbility;
use App\Http\Middleware\Api\LogApiQuery;
use App\Http\Middleware\EnsureActionPermission;
use App\Http\Middleware\EnsureBranchScope;
use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\EnsureUserNotDisabled;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\TechnicalErrorReference;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // SEO (27-oct-2026): X-Robots-Tag en toda respuesta — noindex salvo las
        // páginas públicas de config/seo.php (ver App\Support\Seo).
        $middleware->append(AddRobotsHeader::class);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            EnsureUserNotDisabled::class,
            // Fase 6.4 — alcance por sucursal: un branch_id fuera del alcance del usuario → 403.
            EnsureBranchScope::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'ensure.ability' => EnsureApiAbility::class,
            'log.api-query' => LogApiQuery::class,
            'module' => EnsureModuleAccess::class,
            'permission' => EnsureActionPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Error técnico real (no validación, permiso ni 404): el usuario recibe un
        // mensaje humano con una referencia; el detalle (SQL, rutas, traza) solo
        // queda en el log del servidor bajo esa misma referencia.
        $exceptions->context(function (Throwable $e) {
            if (TechnicalErrorReference::isTechnical($e)) {
                return ['error_ref' => TechnicalErrorReference::forCurrentRequest()];
            }

            return [];
        });
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! TechnicalErrorReference::isTechnical($e) || config('app.debug')) {
                return null;
            }
            if (! ($request->expectsJson() || $request->header('X-Inertia'))) {
                return null;
            }
            $ref = TechnicalErrorReference::forCurrentRequest();

            return response()->json([
                'message' => TechnicalErrorReference::message($ref),
                'reference' => $ref,
            ], 500, ['X-Error-Reference' => $ref]);
        });
        // Mantenimiento (`php artisan down`): la web y la PWA ven SIEMPRE la pantalla
        // resources/views/errors/503.blade.php, también con APP_DEBUG; una visita Inertia
        // recarga la página completa. Las peticiones JSON/API conservan su 503 en JSON.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 503 || $request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return null;
            }
            if ($request->header('X-Inertia')) {
                return Inertia::location($request->fullUrl());
            }

            return response()->view('errors.503', [], 503, ['Retry-After' => '60']);
        });
        $exceptions->render(function (PostTooLargeException $e, Request $request) {
            $maxMb = round(((int) ini_get('post_max_size')) ?: 50);
            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json(['message' => "El archivo es demasiado grande. Máximo permitido: {$maxMb} MB."], 413);
            }

            return back()->withErrors(['file' => "El archivo es demasiado grande. Máximo permitido: {$maxMb} MB."]);
        });
    })->create();
