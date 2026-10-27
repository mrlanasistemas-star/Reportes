import Swal from 'sweetalert2';

// Pantalla de carga para descargas de PDF/Excel (27-oct-2026 — "coloca una
// pantalla de carga cuando se descargue el pdf o el excel"). Antes un
// <a href=".../radiografia.pdf"> dejaba la página quieta mientras el servidor
// generaba el archivo (a veces 10-30 s) y parecía que no pasaba nada.
//
// Dos usos:
//   1) attachDownloadInterceptor(): escucha los clics en <a> cuyo destino es una
//      descarga (ver isDownloadUrl) y la hace por fetch con la pantalla de carga.
//   2) downloadFile(url) / showDownloadLoading()/hideDownloadLoading(): llamado a
//      mano desde botones que ya descargaban por fetch o con window.location.
//
// Reutiliza la tarjeta y estilos de #fv-route-loading (app.css). Un <a> con
// `data-no-download-overlay` o `target="_blank"` (sin atributo download) no se
// intercepta: "Ver" en otra pestaña no es una descarga.
const OVERLAY_ID = 'fv-download-loading';
const SHOW_DELAY_MS = 150;
const SLOW_NOTICE_MS = 12_000;

const DOWNLOAD_PATH_PATTERNS: RegExp[] = [
    /\.(xlsx|xlsm|xls|csv|pdf|zip|docx)$/i,
    /\/(excel|pdf|xlsx|csv|descargar|download|credenciales)$/i,
    /\/exportar\/[^/]+$/i,
];

type Kind = 'pdf' | 'excel' | 'archivo';

let overlayEl: HTMLDivElement | null = null;
let showTimer: ReturnType<typeof setTimeout> | undefined;
let tickTimer: ReturnType<typeof setInterval> | undefined;
let refCount = 0;
let currentAbort: AbortController | null = null;

export function isDownloadUrl(url: URL): boolean {
    return url.origin === window.location.origin && DOWNLOAD_PATH_PATTERNS.some((p) => p.test(url.pathname));
}

function kindOf(url: string): Kind {
    const path = new URL(url, window.location.href).pathname.toLowerCase();

    if (/pdf/.test(path)) {
        return 'pdf';
    }

    if (/(xlsx|xlsm|xls|csv|excel)/.test(path)) {
        return 'excel';
    }

    return 'archivo';
}

const KIND_LABEL: Record<Kind, string> = { pdf: 'el PDF', excel: 'el Excel', archivo: 'el archivo' };

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
            <p class="fv-route-loading-title">Generando el archivo…</p>
            <p class="fv-route-loading-text">La descarga empieza sola en cuanto esté listo.</p>
            <div class="fv-route-loading-bar" aria-hidden="true">
                <span class="fv-route-loading-bar-fill"></span>
            </div>
            <button type="button" class="fv-route-loading-action">Cancelar descarga</button>
        </div>
    `;
    el.querySelector('.fv-route-loading-action')?.addEventListener('click', () => {
        currentAbort?.abort();
    });
    document.body.appendChild(el);
    overlayEl = el;

    return el;
}

function setTexts(kind: Kind, elapsedMs: number): void {
    const el = ensureOverlay();
    const title = el.querySelector<HTMLElement>('.fv-route-loading-title');
    const text = el.querySelector<HTMLElement>('.fv-route-loading-text');

    if (elapsedMs < SLOW_NOTICE_MS) {
        if (title) {
            title.textContent = `Generando ${KIND_LABEL[kind]}…`;
        }

        if (text) {
            text.textContent = 'Estamos preparando la información. La descarga empieza sola en cuanto esté listo.';
        }

        return;
    }

    if (title) {
        title.textContent = `Sigue generando… ${Math.round(elapsedMs / 1000)} s`;
    }

    if (text) {
        text.textContent = 'Este archivo reúne mucha información y puede tardar un poco más. No cierres ni recargues la ventana.';
    }
}

export function showDownloadLoading(kind: Kind = 'archivo', cancelable = false): void {
    refCount += 1;
    const el = ensureOverlay();
    const startedAt = Date.now();
    el.querySelector<HTMLElement>('.fv-route-loading-action')?.toggleAttribute('hidden', !cancelable);
    setTexts(kind, 0);
    clearInterval(tickTimer);
    tickTimer = setInterval(() => setTexts(kind, Date.now() - startedAt), 1000);
    clearTimeout(showTimer);
    showTimer = setTimeout(() => el.classList.add('is-visible'), SHOW_DELAY_MS);
}

export function hideDownloadLoading(): void {
    refCount = Math.max(0, refCount - 1);

    if (refCount > 0) {
        return;
    }

    clearTimeout(showTimer);
    clearInterval(tickTimer);
    overlayEl?.classList.remove('is-visible');
}

function filenameFrom(resp: Response, url: string): string {
    const header = resp.headers.get('Content-Disposition') ?? '';
    const star = /filename\*=(?:UTF-8'')?([^;]+)/i.exec(header)?.[1];

    if (star) {
        try {
            return decodeURIComponent(star.trim().replace(/^"|"$/g, ''));
        } catch {
            // cae al filename simple
        }
    }

    const plain = /filename="?([^";]+)"?/i.exec(header)?.[1];

    return plain?.trim() || decodeURIComponent(new URL(url, window.location.href).pathname.split('/').pop() || 'descarga');
}

async function errorMessage(resp: Response): Promise<string> {
    const body = await resp.text().catch(() => '');

    try {
        const message = JSON.parse(body)?.message;

        if (message) {
            return String(message);
        }
    } catch {
        // no es JSON
    }

    const text = (new DOMParser().parseFromString(body, 'text/html').body.textContent ?? '').replace(/\s+/g, ' ').trim();

    if (resp.status === 403) {
        return 'Tu rol no tiene permiso para descargar este archivo.';
    }

    if (resp.status === 404) {
        return 'El archivo ya no está disponible.';
    }

    return text && text.length < 300 ? text : `El servidor respondió ${resp.status}. Intenta nuevamente en unos segundos.`;
}

function saveBlob(blob: Blob, filename: string): void {
    const href = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = href;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(href), 10_000);
}

/**
 * Descarga por fetch con la pantalla de carga. Si el servidor redirige (p. ej.
 * "ya se descargó" o falta un dato, con mensaje flash) o responde HTML, se navega
 * a la URL de forma normal para que la página muestre ese mensaje.
 */
export async function downloadFile(url: string): Promise<boolean> {
    const kind = kindOf(url);
    const abort = new AbortController();
    currentAbort = abort;
    showDownloadLoading(kind, true);

    try {
        const resp = await fetch(url, { credentials: 'same-origin', redirect: 'manual', signal: abort.signal, headers: { Accept: '*/*' } });

        if (resp.type === 'opaqueredirect' || resp.status === 0) {
            window.location.href = url;

            return false;
        }

        if (resp.status === 419) {
            window.location.reload();

            return false;
        }

        if (!resp.ok) {
            hideDownloadLoading();
            void Swal.fire({ icon: 'error', title: `No se pudo descargar ${KIND_LABEL[kind]}`, text: await errorMessage(resp), confirmButtonColor: '#047857' });

            return false;
        }

        if ((resp.headers.get('Content-Type') ?? '').includes('text/html')) {
            window.location.href = url;

            return false;
        }

        saveBlob(await resp.blob(), filenameFrom(resp, url));

        return true;
    } catch (e) {
        if ((e as Error)?.name !== 'AbortError') {
            void Swal.fire({
                icon: 'error',
                title: `No se pudo descargar ${KIND_LABEL[kind]}`,
                text: e instanceof TypeError ? 'Sin conexión con el servidor. Revisa tu red e intenta de nuevo.' : (e as Error)?.message || 'Intenta nuevamente en unos segundos.',
                confirmButtonColor: '#047857',
            });
        }

        return false;
    } finally {
        if (currentAbort === abort) {
            currentAbort = null;
        }

        hideDownloadLoading();
    }
}

export function attachDownloadInterceptor(): void {
    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const anchor = (event.target as Element | null)?.closest?.('a[href]') as HTMLAnchorElement | null;

        if (!anchor || anchor.hasAttribute('data-no-download-overlay')) {
            return;
        }

        if (anchor.target === '_blank' && !anchor.hasAttribute('download')) {
            return;
        }

        const raw = anchor.getAttribute('href') ?? '';

        if (raw === '' || raw.startsWith('#') || raw.startsWith('blob:') || raw.startsWith('javascript:')) {
            return;
        }

        const url = new URL(anchor.href, window.location.href);

        if (!isDownloadUrl(url)) {
            return;
        }

        event.preventDefault();
        void downloadFile(url.toString());
    });
}
