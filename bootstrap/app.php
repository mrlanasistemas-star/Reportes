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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $e, \Illuminate\Http\Request $request) {
            $maxMb = round(((int) ini_get('post_max_size')) ?: 50);
            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json(['message' => "El archivo es demasiado grande. Máximo permitido: {$maxMb} MB."], 413);
            }
            return back()->withErrors(['file' => "El archivo es demasiado grande. Máximo permitido: {$maxMb} MB."]);
        });
    })->create();
