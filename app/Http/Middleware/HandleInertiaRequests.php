<?php

namespace App\Http\Middleware;

use App\Services\Access\ActionPermissionService;
use App\Services\Access\DataScopeService;
use App\Services\Access\ModuleAccessService;
use Illuminate\Http\Request;
use Inertia\Middleware;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware de Inertia: define la plantilla raíz, la versión de assets y los
 * props compartidos por TODAS las páginas Vue (usuario, módulos visibles,
 * permisos, alcance por sucursal, mensajes flash, estado del sidebar y
 * contador de notificaciones). Además evita que el navegador cachee el HTML.
 */
class HandleInertiaRequests extends Middleware
{
    /**
     * Plantilla Blade raíz que se carga en la primera visita (resources/views/app.blade.php).
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Versión actual de los assets. Se usa el hash MD5 del manifest de Vite
     * para que Inertia fuerce una recarga completa cuando se recompila el JS;
     * si no hay manifest (o falla la lectura) se usa la versión por defecto.
     *
     * @see https://inertiajs.com/asset-versioning
     *
     * @param  Request  $request  Petición entrante.
     * @return string|null Hash del manifest o versión por defecto de Inertia.
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
     * Props compartidos por defecto con todas las páginas. Los marcados como
     * closures (permissions, dataScope, flash, backgroundActivity) se evalúan
     * de forma perezosa por Inertia. Ocultar módulos/botones aquí es solo UX:
     * la autorización real vive en los middlewares de cada ruta.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @param  Request  $request  Petición entrante.
     * @return array<string, mixed> Props compartidos.
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            // Prefijo de instalación de la app ("/reportes/public" en WAMP bajo
            // subdirectorio, "" en producción servida en la raíz del dominio). El
            // frontend lo antepone al construir URLs de fetch/descarga para que
            // funcionen igual en subdirectorio o en raíz, sin hardcodear nada.
            'base' => $request->getBaseUrl(),
            'auth' => [
                'user' => $request->user(),
                // Módulos que el menú debe mostrar (14-oct-2026) — misma fuente que
                // EnsureModuleAccess; ocultar en el menú es solo UX.
                'modules' => app(ModuleAccessService::class)->modulesFor($request->user()),
                // Acciones permitidas "modulo.accion" (prioridad 9) — solo para ocultar
                // botones; la autorización real es el middleware permission:* en cada ruta.
                'permissions' => fn () => app(ActionPermissionService::class)->flatFor($request->user()),
                // Fase 6.4 — alcance por sucursal (general o lista). Solo UX; el control
                // real está en EnsureBranchScope y DataScopeService en cada controlador.
                'dataScope' => fn () => $request->user() ? app(DataScopeService::class)->describe($request->user()) : null,
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

    /**
     * Datos de la campana de la cabecera: trabajos activos (siempre 0, ya no
     * hay trabajos persistentes de Efectividad) y notificaciones no leídas del
     * usuario (un COUNT a la tabla notifications). Cualquier error (p.ej. tabla
     * aún no migrada) devuelve ceros para no romper la página.
     *
     * @param  Request  $request  Petición entrante.
     * @return array{active_count:int, unread_count:int}|null Null si no hay usuario autenticado.
     */
    private function backgroundActivity(Request $request): ?array
    {
        $user = $request->user();
        if (! $user) {
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
     * Ejecuta el middleware de Inertia y, en cargas de página completas (sin
     * cabecera X-Inertia), agrega cabeceras para que el navegador nunca cachee
     * el HTML. Los assets con hash sí pueden cachearse a largo plazo; solo el
     * "cascarón" HTML debe estar siempre fresco.
     *
     * @param  Request  $request  Petición entrante.
     * @param  \Closure  $next  Siguiente capa del pipeline.
     * @return Response Respuesta con cabeceras anti-caché cuando aplica.
     */
    public function handle(Request $request, \Closure $next): Response
    {
        $response = parent::handle($request, $next);

        // Only apply to full page loads (not Inertia XHR partials)
        if (! $request->header('X-Inertia')) {
            $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }
}
