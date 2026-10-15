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

// Corrección 15-oct-2026 — "al activar un OKR incompleto aparece el error y además
// el modal morado, que se queda abierto". El módulo OKR NUNCA usa la pantalla
// completa: cada acción (activar, guardar KR, filtros, check-in, evidencias,
// documentos) tiene su propio indicador local (spinner en el botón + mensaje al
// terminar). La pantalla completa queda solo para los procesos extensos ya
// autorizados (Reportería / Histórico General).
const LOCAL_FEEDBACK_MODULE_PATTERNS: RegExp[] = [/^\/okr(\/|$)/];

type OverlayVisit = { url: URL; method?: string };

function isExcludedFromGlobalOverlay(visit: OverlayVisit): boolean {
    if (BACKGROUND_ENQUEUE_PATH_PATTERNS.some((pattern) => pattern.test(visit.url.pathname))) {
        return true;
    }

    if (LOCAL_FEEDBACK_MODULE_PATTERNS.some((pattern) => pattern.test(visit.url.pathname))) {
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

// 16-oct-2026 — "la pantalla de carga no aguanta: dice tiempo de espera, intenta
// de nuevo, pero si lo dejo sí carga". Antes un watchdog de 45 s cerraba el
// overlay con un aviso de reintento aunque la petición SEGUÍA viva (módulos con
// mucha información tardan más). Ahora la pantalla espera mientras la carga
// siga en curso: a los 12 s muestra un contador tranquilo, a los 60 s ofrece
// "Seguir usando la aplicación" (sin cancelar nada) y solo a los 10 min se
// libera sola, como red de seguridad ante un evento perdido de Inertia.
const SLOW_NOTICE_MS = 12_000;
const OFFER_DISMISS_MS = 60_000;
const HARD_SAFETY_MS = 600_000;
let watchdogTimer: ReturnType<typeof setTimeout> | undefined;
let tickTimer: ReturnType<typeof setInterval> | undefined;
let startedAt = 0;

function setSlowState(elapsedMs: number): void {
    const el = ensureOverlay();
    const title = el.querySelector<HTMLElement>('.fv-route-loading-title');
    const text = el.querySelector<HTMLElement>('.fv-route-loading-text');
    const action = el.querySelector<HTMLElement>('.fv-route-loading-action');

    if (elapsedMs < SLOW_NOTICE_MS) {
        if (title) {
title.textContent = 'Espera un momento';
}

        if (text) {
text.textContent = 'Estamos cargando toda la información que necesitas, esto puede tardar unos segundos.';
}

        action?.setAttribute('hidden', '');

        return;
    }

    const seconds = Math.round(elapsedMs / 1000);

    if (title) {
title.textContent = `Sigue cargando… ${seconds} s`;
}

    if (text) {
text.textContent = 'Este módulo reúne mucha información y puede tardar un poco más. No cierres ni recargues la ventana: se abrirá en cuanto termine.';
}

    if (elapsedMs >= OFFER_DISMISS_MS) {
        action?.removeAttribute('hidden');
    }
}

function armWatchdog(): void {
    clearTimeout(watchdogTimer);
    clearInterval(tickTimer);
    startedAt = Date.now();
    setSlowState(0);
    tickTimer = setInterval(() => setSlowState(Date.now() - startedAt), 1000);
    watchdogTimer = setTimeout(() => {
        if (refCount <= 0) {
            return;
        }

        console.warn('[routeLoadingOverlay] 10 min sin confirmación de Inertia — se libera la pantalla por seguridad.');
        refCount = 0;
        forceHideOverlay();
        void toast.fire({ icon: 'warning', title: 'La carga no respondió después de 10 minutos. Revisa tu conexión y vuelve a intentarlo.' });
    }, HARD_SAFETY_MS);
}

function stopWatchdog(): void {
    clearTimeout(watchdogTimer);
    clearInterval(tickTimer);
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
            <button type="button" class="fv-route-loading-action" hidden>Seguir usando la aplicación</button>
        </div>
    `;
    // Ocultar NO cancela la carga: Inertia abrirá la pantalla cuando termine.
    el.querySelector('.fv-route-loading-action')?.addEventListener('click', () => {
        stopWatchdog();
        forceHideOverlay();
    });
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

    stopWatchdog();
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
