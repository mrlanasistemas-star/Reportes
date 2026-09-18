import { router } from '@inertiajs/vue3';

// Pantalla de carga global (cierre 17-sep-2026, ronda 6 — "tarda muchísimo entrar,
// no sé si está haciendo algo"). Dos disparadores distintos comparten el mismo
// overlay singleton (creado una sola vez en el DOM, fuera del árbol de Inertia):
//   1) attachInertiaRouteLoading() — cualquier navegación Inertia (login → dashboard,
//      <Link>, router.visit).
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

let overlayEl: HTMLDivElement | null = null;
let showTimer: ReturnType<typeof setTimeout> | undefined;
let refCount = 0;

function ensureOverlay(): HTMLDivElement {
    if (overlayEl) return overlayEl;

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

export function showRouteLoading(delay = SHOW_DELAY_MS): void {
    refCount += 1;
    const el = ensureOverlay();
    clearTimeout(showTimer);
    showTimer = setTimeout(() => {
        el.classList.add('is-visible');
    }, delay);
}

export function hideRouteLoading(): void {
    refCount = Math.max(0, refCount - 1);
    if (refCount > 0) return;
    clearTimeout(showTimer);
    ensureOverlay().classList.remove('is-visible');
}

export function attachInertiaRouteLoading(): void {
    router.on('start', () => showRouteLoading());
    router.on('finish', () => hideRouteLoading());
}
