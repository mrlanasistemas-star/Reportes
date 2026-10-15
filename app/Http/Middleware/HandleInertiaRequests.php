<?php

namespace App\Http\Middleware;

use App\Services\Access\ModuleAccessService;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        // Use the Vite manifest hash so Inertia forces a hard reload
        // whenever JS assets are rebuilt (fixes need for Ctrl+F5).
        $manifest = public_path('build/manifest.json');
        if (file_exists($manifest)) {
            return md5_file($manifest) ?: parent::version($request);
        }
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                // Módulos que el menú debe mostrar (14-oct-2026) — misma fuente que
                // EnsureModuleAccess; ocultar en el menú es solo UX.
                'modules' => app(ModuleAccessService::class)->modulesFor($request->user()),
                // Acciones permitidas "modulo.accion" (prioridad 9) — solo para ocultar
                // botones; la autorización real es el middleware permission:* en cada ruta.
                'permissions' => fn () => app(\App\Services\Access\ActionPermissionService::class)->flatFor($request->user()),
            ],
            // Mensajes de sesión (14-oct-2026, módulo OKR): resultado de acciones
            // como la activación en cascada de OKR individuales.
            // 'error' agregado (15-oct-2026): varios controladores (Responsibles,
            // ReportUpload, MonthlyReport, Period) ya hacían back()->with('error', ...)
            // para bloquear una acción con un motivo real (ej. "no puedes deshabilitar
            // al único administrador") — esa clave nunca se compartía aquí, así que el
            // usuario hacía clic y no pasaba NADA visible, sin saber si funcionó o no.
            'flash' => fn () => $request->hasSession()
                ? ['success' => $request->session()->get('success'), 'warning' => $request->session()->get('warning'), 'error' => $request->session()->get('error')]
                : ['success' => null, 'warning' => null, 'error' => null],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // Indicador de trabajos en segundo plano + notificaciones internas (campana
            // de la cabecera). Dos COUNT por página; el detalle se pide bajo demanda a
            // /notificaciones. Nunca rompe la página si aún no se migró la tabla.
            'backgroundActivity' => fn () => $this->backgroundActivity($request),
        ];
    }

    private function backgroundActivity(Request $request): ?array
    {
        $user = $request->user();
        if (!$user) {
            return null;
        }

        try {
            return [
                // La calculadora de Efectividad ya no deja trabajos persistentes en segundo plano.
                'active_count' => 0,
                'unread_count' => $user->unreadNotifications()->count(),
            ];
        } catch (\Throwable) {
            return ['active_count' => 0, 'unread_count' => 0];
        }
    }

    /**
     * Ensure HTML responses are never cached by the browser.
     * Assets (with content hashes) can be cached long-term; only the HTML shell must be fresh.
     */
    public function handle(Request $request, \Closure $next): Response
    {
        $response = parent::handle($request, $next);

        // Only apply to full page loads (not Inertia XHR partials)
        if (!$request->header('X-Inertia')) {
            $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }
}
