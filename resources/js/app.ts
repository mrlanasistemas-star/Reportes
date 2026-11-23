/**
 * Punto de entrada del frontend (Inertia + Vue 3) de Reportería MR LANA.
 *
 * Responsabilidades:
 * - Arranca la app Inertia y decide qué layout envuelve cada página según su
 *   nombre (auth/*, settings/*, resto → AppLayout).
 * - Engancha los overlays globales de carga (navegación Inertia y descargas).
 * - Registra el service worker mínimo para que el sitio sea instalable (PWA).
 * - Maneja globalmente sesiones expiradas (419/401) y errores técnicos (5xx)
 *   tanto en navegación Inertia como en llamadas fetch() crudas.
 * - Aplica el tema claro/oscuro guardado al cargar la página.
 */
import { createInertiaApp, router } from '@inertiajs/vue3';
import Swal from 'sweetalert2';
import { initializeTheme } from '@/composables/useAppearance';
// Solo por su efecto secundario: engancha el listener de "beforeinstallprompt"
// lo antes posible (el navegador puede disparar el evento antes de que
// cualquier componente que use InstallAppButton llegue a montarse).
import '@/composables/usePwaInstall';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { attachDownloadInterceptor } from '@/lib/downloadOverlay';
import { attachInertiaRouteLoading } from '@/lib/routeLoadingOverlay';

const appName = import.meta.env.VITE_APP_NAME || 'Reportes';
// SEO (27-oct-2026): el título siempre lleva la marca — misma regla que
// App\Support\Seo::appTitle() en PHP, para que el <title> que pinta el servidor
// y el que pone Inertia al montar sean idénticos ("Reportes" → "Reportes Mr Lana").
const brandTitle = /mr\s*lana/i.test(appName) ? appName : `${appName} Mr Lana`;

// Pantalla de carga global (cierre 17-sep-2026, ronda 6 — "tarda muchísimo entrar,
// no sé si está haciendo algo") — cubre CUALQUIER navegación Inertia del sitio,
// incluyendo login → dashboard, sin tocar cómo createInertiaApp resuelve/monta cada
// página. Ver resources/js/lib/routeLoadingOverlay.ts.
attachInertiaRouteLoading();

// Pantalla de carga al descargar PDF/Excel desde cualquier enlace — ver
// resources/js/lib/downloadOverlay.ts.
attachDownloadInterceptor();

// Service worker mínimo (sin caché) solo para que el navegador considere el
// sitio "instalable" y dispare beforeinstallprompt — ver public/sw.js.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Silencioso: si falla, simplemente no se ofrece instalar la app.
        });
    });
}

// Cierre 17-sep-2026, ronda 4 — "tengo que recargar para que todo funcione". Causa
// real más probable: una pestaña abierta por horas (SESSION_LIFETIME=120min) cuya
// sesión/token CSRF expiró en el servidor — cualquier POST/fetch posterior devuelve
// 419 (o 401 si además cerró sesión), y como NINGÚN sitio de la app manejaba eso,
// fallaba en silencio (el botón "no hacía nada") hasta un F5 manual, que sí arranca
// una sesión/token frescos. Dos frentes, porque la app mezcla navegación Inertia
// (<Link>/router.visit) CON fetch() crudo en ~11 páginas (Preview.vue, Historico-
// General, OKR, etc.) — un solo arreglo no cubre ambos caminos:
//
//   1) Navegación Inertia: router.on('httpException', ...) — dispara cuando la
//      respuesta NO es una respuesta Inertia válida (p.ej. la página de error
//      419/500 de Laravel) — recarga sola en vez de dejar la SPA en un estado roto.
//   2) fetch() crudo: se envuelve window.fetch UNA sola vez aquí (nunca en cada
//      archivo) para detectar 419/401 en CUALQUIER llamada — muestra un aviso claro
//      y ofrece recargar, en vez de fallar sin ningún mensaje visible.
//
// Ninguno de los dos cambia el comportamiento normal (200 OK) de ninguna pantalla.
/**
 * Manejador global de respuestas no-Inertia (páginas de error de Laravel).
 * - 419/401: recarga la página para obtener sesión y token CSRF nuevos.
 * - 5xx con cuerpo {message, reference}: muestra un SweetAlert con el mensaje
 *   técnico en lugar de la página de error cruda.
 */
router.on('httpException', (event) => {
    const status = event.detail.response?.status;

    if (status === 419 || status === 401) {
        event.preventDefault();
        window.location.reload();

        return;
    }

    // Error técnico: el backend responde {message, reference} (ver
    // App\Support\TechnicalErrorReference). Se muestra ese mensaje con su
    // referencia en vez de la página de error cruda del servidor.
    if (status !== undefined && status >= 500) {
        let data: unknown = event.detail.response?.data;

        if (typeof data === 'string') {
            try {
                data = JSON.parse(data);
            } catch {
                data = null;
            }
        }

        const body = data as { message?: unknown; reference?: unknown } | null;

        if (body && typeof body.message === 'string' && body.reference) {
            event.preventDefault();
            Swal.fire({
                icon: 'error',
                title: 'No se completó la operación',
                text: body.message,
                confirmButtonText: 'Entendido',
            });
        }
    }
});

/** Referencia al fetch nativo, antes de envolverlo. */
const originalFetch = window.fetch.bind(window);
/** Evita mostrar el aviso de sesión expirada más de una vez por carga. */
let sessionExpiredNoticeShown = false;
/**
 * Envoltura global de window.fetch: si alguna llamada responde 419/401,
 * avisa que la sesión expiró y recarga la página. La respuesta se devuelve
 * intacta al llamador en todos los casos.
 */
window.fetch = async (...args: Parameters<typeof fetch>) => {
    const response = await originalFetch(...args);

    if (
        (response.status === 419 || response.status === 401) &&
        !sessionExpiredNoticeShown
    ) {
        sessionExpiredNoticeShown = true;
        Swal.fire({
            icon: 'info',
            title: 'Tu sesión expiró',
            text: 'Ha pasado mucho tiempo desde tu última acción. Vamos a recargar la página para renovar tu sesión.',
            timer: 2500,
            timerProgressBar: true,
            showConfirmButton: false,
            allowOutsideClick: false,
        }).then(() => window.location.reload());
    }

    return response;
};

/**
 * Inicializa Inertia.
 * - title: agrega la marca al título de cada página (sin duplicarla).
 * - layout: elige el layout persistente según el nombre del componente de página.
 * - progress: barra superior con el color primario del tema.
 */
createInertiaApp({
    title: (title) =>
        !title
            ? brandTitle
            : title.includes(brandTitle)
              ? title
              : `${title} — ${brandTitle}`,
    layout: (name) => {
        switch (true) {
            // auth/Login y auth/ForgotPassword traen su propio layout de pantalla
            // completa (mitad y mitad, cierre 17-sep-2026 ronda 4) — envolverlos en
            // AuthLayout/AuthSimpleLayout agregaba un wrapper vacío de más (nunca se
            // le pasa title/description).
            case name === 'auth/Login':
            case name === 'auth/ForgotPassword':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    // Barra de navegación con el token --primary (Inertia lo inserta en CSS, así
    // que sigue al tema claro/oscuro);
    // sin spinner: para cargas largas ya está el overlay global.
    progress: {
        color: 'var(--primary)',
        showSpinner: false,
    },
});

// Aplica el modo claro/oscuro guardado al cargar la página.
initializeTheme();
