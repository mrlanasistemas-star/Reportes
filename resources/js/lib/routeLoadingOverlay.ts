import { router } from '@inertiajs/vue3';
import { toast } from '@/components/feedback/Toast';

// Pantalla de carga global (cierre 17-sep-2026, ronda 6 — "tarda muchísimo entrar,
// no sé si está haciendo algo"). Dos disparadores distintos comparten el mismo
// overlay singleton (creado una sola vez en el DOM, fuera del árbol de Inertia):
//   1) attachInertiaRouteLoading() — cualquier navegación Inertia (login → dashboard,
//      <Link>, router.visit, useForm().post()).
//   2) showRouteLoading()/hideRouteLoading() — llamado a mano donde el "cambio de
//      pantalla" no es una navegación Inertia sino un fetch() propio (p. ej.
//      Dashboard.vue al cambiar de periodo).
// refCount evita que una llamada termine oculte el overlay mientras otra sigue en
// curso (p. ej. si ambos disparadores coincidieran).
//
// SHOW_DELAY_MS (ronda 7 — "me sale hasta cambiando de módulo, hay módulos que no
// tardan, quítalo ahí"): el timer de showRouteLoading() ya se cancelaba solo si
// 'finish' llegaba antes del delay (nunca se agrega la clase is-visible) — el
// overlay JAMÁS debería aparecer en una navegación rápida. El bug real era el
// propio delay: 150ms es más corto que casi cualquier ida y vuelta al servidor,
// así que en la práctica SIEMPRE alcanzaba a mostrarse, hasta en módulos livianos.
// 600ms es el umbral clásico de "esto se siente lento, hace falta feedback" — por
// debajo de eso una navegación se percibe instantánea y no debe interrumpirse con
// pantalla completa.
const OVERLAY_ID = 'fv-route-loading';
const SHOW_DELAY_MS = 600;

// Cierre 18-sep-2026 — "Generar reporte"/"Cargar registros" solo encolan un Job
// (GenerateRadiographyJob/UpdatePeriodDatabaseJob) y el backend responde de inmediato;
// el resto lo hace el worker en segundo plano y avisa por correo. Antes, este mismo
// router.post (Inertia) disparaba el overlay global de pantalla completa como si fuera
// una navegación pesada, dando la sensación de que el navegador se quedaba bloqueado
// esperando terminar TODA la generación. Estas rutas solo confirman "job encolado" —
// el feedback correcto es el spinner local del botón (ya implementado en
// Historico-General/index.vue vía isGeneratingReport/isSubmitting) + el toast/alert de
// "en cola", nunca el overlay de página completa. NO incluye incidencias (fuera de
// alcance de este fix) ni procesar-ahora (ese sí bloquea la pestaña a propósito, y ya
// tiene su propio SweetAlert de "Procesando…").
const BACKGROUND_ENQUEUE_PATH_PATTERNS: RegExp[] = [
    /\/generar-radiografia$/,
    /\/generar-reporte\/cancelar$/,
    /\/actualizar-bd$/,
    /\/actualizacion-bd\/cancelar$/,
    /\/actualizacion-bd\/reencolar$/,
    /\/actualizacion-bd\/limpiar$/,
    /\/procesar-fuentes-pendientes$/,
    /\/reprocesar-fuentes-con-error$/,
];

// Corrección (oct-2026) — "sube un Excel en Histórico General, la pantalla morada
// 'Espera un momento' se queda trabada y bloquea todo el sitio". POST /historico-general
// (ReportUploadController::store) GUARDA el archivo y responde de inmediato — no es una
// navegación pesada ni un proceso en segundo plano: es exactamente el mismo caso que la
// lista de arriba (confirmación rápida, nunca debe tomar la pantalla completa). El
// feedback real ya existe de forma local: el diálogo "Subiendo archivo" de
// Historico-General/index.vue (con el progreso real de subida, ver onProgress) y el
// estado de cada SourceUploadCard. Solo aplica al MÉTODO POST — la carga normal de la
// página (`GET /historico-general`) sí debe mostrar el overlay si tarda, como cualquier
// otra navegación.
const UPLOAD_SAVE_PATH_PATTERNS: RegExp[] = [/^\/historico-general\/?$/];

type OverlayVisit = { url: URL; method?: string };

function isExcludedFromGlobalOverlay(visit: OverlayVisit): boolean {
    if (BACKGROUND_ENQUEUE_PATH_PATTERNS.some((pattern) => pattern.test(visit.url.pathname))) {
        return true;
    }

    const method = (visit.method ?? 'get').toLowerCase();

    return (
        method === 'post' &&
        UPLOAD_SAVE_PATH_PATTERNS.some((pattern) => pattern.test(visit.url.pathname))
    );
}

let overlayEl: HTMLDivElement | null = null;
let showTimer: ReturnType<typeof setTimeout> | undefined;
let refCount = 0;

// Corrección (oct-2026): un contador ciego puede desincronizarse si, por cualquier
// motivo (una versión futura de Inertia, un evento duplicado, dos triggers
// coincidiendo), 'start'/'finish' no llegan exactamente en pares para la MISMA
// visita. En vez de confiar solo en el conteo, cada visita de Inertia se identifica
// por el objeto `visit` que el propio router entrega (estable entre 'start' y
// 'finish' — ver @inertiajs/core RequestParams.all()): nunca se incrementa dos
// veces la misma visita, nunca se decrementa una que no se contó.
const activeInertiaVisits = new WeakSet<object>();

// Watchdog de seguridad: ninguna carga real de este sitio debería tardar más de
// esto. Si el overlay lleva encendido más tiempo, es evidencia de un evento de
// Inertia perdido (no de que la operación siga viva) — se libera la interfaz y SE
// AVISA al usuario; nunca se finge que algo terminó bien.
const WATCHDOG_MS = 45_000;
let watchdogTimer: ReturnType<typeof setTimeout> | undefined;

function armWatchdog(): void {
    clearTimeout(watchdogTimer);
    watchdogTimer = setTimeout(() => {
        if (refCount <= 0) {
            return;
        }

        console.warn(
            `[routeLoadingOverlay] La pantalla de carga llevaba más de ${WATCHDOG_MS / 1000}s activa sin confirmación de Inertia — se liberó por seguridad.`,
        );
        refCount = 0;
        forceHideOverlay();
        void toast.fire({
            icon: 'warning',
            title: 'Esto está tardando más de lo normal. Puedes seguir usando la aplicación; si la operación no se completó, inténtala de nuevo.',
        });
    }, WATCHDOG_MS);
}

function ensureOverlay(): HTMLDivElement {
    if (overlayEl) {
        return overlayEl;
    }

    const el = document.createElement('div');
    el.id = OVERLAY_ID;
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.innerHTML = `
        <div class="fv-route-loading-card">
            <img src="/logoMrLana.png" alt="" class="fv-route-loading-logo" />
            <p class="fv-route-loading-title">Espera un momento</p>
            <p class="fv-route-loading-text">Estamos cargando toda la información que necesitas, esto puede tardar unos segundos.</p>
            <div class="fv-route-loading-bar" aria-hidden="true">
                <span class="fv-route-loading-bar-fill"></span>
            </div>
            <div class="fv-route-loading-dots" aria-hidden="true">
                <span></span><span></span><span></span>
            </div>
        </div>
    `;
    document.body.appendChild(el);
    overlayEl = el;

    return el;
}

function forceHideOverlay(): void {
    clearTimeout(showTimer);
    ensureOverlay().classList.remove('is-visible');
}

export function showRouteLoading(delay = SHOW_DELAY_MS): void {
    refCount += 1;
    armWatchdog();
    const el = ensureOverlay();
    clearTimeout(showTimer);
    showTimer = setTimeout(() => {
        el.classList.add('is-visible');
    }, delay);
}

export function hideRouteLoading(): void {
    refCount = Math.max(0, refCount - 1);

    if (refCount > 0) {
        return;
    }

    clearTimeout(watchdogTimer);
    forceHideOverlay();
}

export function attachInertiaRouteLoading(): void {
    router.on('start', (event) => {
        const visit = event.detail.visit;

        if (isExcludedFromGlobalOverlay(visit)) {
            return;
        }

        // Nunca contar dos veces la MISMA visita (p. ej. si Inertia alguna vez
        // disparara 'start' más de una vez para el mismo objeto de visita).
        if (activeInertiaVisits.has(visit)) {
            return;
        }

        activeInertiaVisits.add(visit);
        showRouteLoading();
    });
    router.on('finish', (event) => {
        const visit = event.detail.visit;

        // Si esta visita nunca se contó (estaba excluida, o 'finish' llegó
        // duplicado), nunca se decrementa: eso es lo que de verdad desincroniza
        // el contador y deja el overlay sin forma de cerrarse.
        if (!activeInertiaVisits.has(visit)) {
            return;
        }

        activeInertiaVisits.delete(visit);
        hideRouteLoading();
    });
}
