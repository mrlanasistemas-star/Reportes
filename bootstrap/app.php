<?php

use App\Http\Middleware\Api\EnsureApiAbility;
use App\Http\Middleware\Api\LogApiQuery;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            \App\Http\Middleware\EnsureUserNotDisabled::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'ensure.ability' => EnsureApiAbility::class,
            'log.api-query'  => LogApiQuery::class,
            'module'         => \App\Http\Middleware\EnsureModuleAccess::class,
            'permission'     => \App\Http\Middleware\EnsureActionPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Error técnico real (no validación, permiso ni 404): el usuario recibe un
        // mensaje humano con una referencia; el detalle (SQL, rutas, traza) solo
        // queda en el log del servidor bajo esa misma referencia.
        $exceptions->context(function (\Throwable $e) {
            if (\App\Support\TechnicalErrorReference::isTechnical($e)) {
                return ['error_ref' => \App\Support\TechnicalErrorReference::forCurrentRequest()];
            }

            return [];
        });
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            if (! \App\Support\TechnicalErrorReference::isTechnical($e) || config('app.debug')) {
                return null;
            }
            if (! ($request->expectsJson() || $request->header('X-Inertia'))) {
                return null;
            }
            $ref = \App\Support\TechnicalErrorReference::forCurrentRequest();

            return response()->json([
                'message' => \App\Support\TechnicalErrorReference::message($ref),
                'reference' => $ref,
            ], 500, ['X-Error-Reference' => $ref]);
        });
        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, \Illuminate\Http\Request $request) {
            $maxMb = round(((int) ini_get('post_max_size')) ?: 50);
            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json(['message' => "El archivo es demasiado grande. Máximo permitido: {$maxMb} MB."], 413);
            }
            return back()->withErrors(['file' => "El archivo es demasiado grande. Máximo permitido: {$maxMb} MB."]);
        });
    })->create();
