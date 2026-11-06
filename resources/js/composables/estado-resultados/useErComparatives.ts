import { computed, onBeforeUnmount, ref } from 'vue';
import type { Ref } from 'vue';
import type {
    ComparativasPayload,
    CompMode,
} from '@/components/estado-resultados/comparativas';
import { apiJson, isAbortError } from '@/components/estado-resultados/types';
import { useToast } from '@/composables/useToast';

export interface CompRequest {
    periodId: number;
    scope: string;
    modes: CompMode[];
    contra?: number | null;
    meses?: number;
    charts?: boolean;
    /**
     * Revisión de los datos del periodo actual (huella del dataset + estado del reporte).
     * Cambia al clasificar, ajustar, procesar o aprobar: la caché del navegador deja de usarse.
     */
    rev?: string | null;
}

/** Modos que pide la pestaña Comparativas en una sola consulta. */
export const TAB_MODES: CompMode[] = [
    'anterior',
    'anio_anterior',
    'evolucion',
    'acumulado',
    'sucursales',
];

/** Consulta exacta de la pestaña (la misma clave sirve para precargarla). */
export function tabRequest(
    periodId: number,
    scope: string,
    rev?: string | null,
): CompRequest {
    return { periodId, scope, modes: TAB_MODES, meses: 6, rev };
}

/** Caché del módulo (sobrevive al cambio de pestaña) y consultas en curso compartidas. */
const cache = new Map<string, ComparativasPayload>();
const inflight = new Map<
    string,
    { promise: Promise<ComparativasPayload>; controller: AbortController }
>();
const MAX_CACHED = 24;

const keyOf = (r: CompRequest) =>
    [
        r.periodId,
        r.scope,
        [...r.modes].sort().join(','),
        r.contra ?? '',
        r.meses ?? '',
        r.charts === false ? 0 : 1,
        r.rev ?? '',
    ].join('|');

function urlOf(base: string, req: CompRequest): string {
    const q = new URLSearchParams({
        modos: req.modes.join(','),
        alcance: req.scope,
        graficas: req.charts === false ? '0' : '1',
    });

    if (req.contra) {
        q.set('contra', String(req.contra));
    }

    if (req.meses) {
        q.set('meses', String(req.meses));
    }

    return `${base}/estado-resultados/${req.periodId}/comparativas?${q.toString()}`;
}

/** Una sola petición por clave aunque la pidan las tarjetas, la pestaña y la precarga. */
function fetchShared(
    base: string,
    req: CompRequest,
): Promise<ComparativasPayload> {
    const key = keyOf(req);
    const running = inflight.get(key);

    if (running) {
        return running.promise;
    }

    const controller = new AbortController();
    const promise = apiJson<ComparativasPayload>(urlOf(base, req), {
        signal: controller.signal,
    })
        .then((res) => {
            cache.delete(key);
            cache.set(key, res);

            while (cache.size > MAX_CACHED) {
                cache.delete(cache.keys().next().value as string);
            }

            return res;
        })
        .finally(() => inflight.delete(key));

    inflight.set(key, { promise, controller });

    return promise;
}

/** Vacía la caché del navegador (p. ej. tras aprobar o abrir una nueva versión). */
export function invalidateComparatives(): void {
    cache.clear();
}

/**
 * Carga de comparativas con caché, consultas compartidas, cancelación y un contador de
 * segundos para explicar la espera (el primer cálculo del día puede tardar).
 *
 * `notifyWhenReady`: si el usuario se va a otra pestaña mientras calcula, la consulta sigue
 * y se avisa con un mensaje cuando queda lista (la pestaña la muestra al instante al volver).
 */
export function useErComparatives(
    base: Ref<string>,
    options: { notifyWhenReady?: boolean } = {},
) {
    const toast = useToast();
    const data = ref<ComparativasPayload | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const elapsed = ref(0);
    /** Más de 30 s esperando: se dice con honestidad y se ofrece cancelar o reintentar. */
    const slow = computed(() => elapsed.value >= 30);
    let timer: ReturnType<typeof setInterval> | undefined;
    let seq = 0;
    let last: CompRequest | null = null;
    let unmounted = false;

    function stop(): void {
        clearInterval(timer);
        timer = undefined;
    }

    async function load(
        req: CompRequest,
        force = false,
    ): Promise<ComparativasPayload | null> {
        const key = keyOf(req);
        last = req;

        if (!force && cache.has(key)) {
            ++seq;
            stop();
            loading.value = false;
            data.value = cache.get(key) ?? null;
            error.value = null;

            return data.value;
        }

        if (force) {
            cache.delete(key);
        }

        const current = ++seq;
        loading.value = true;
        error.value = null;
        elapsed.value = 0;
        stop();
        timer = setInterval(() => elapsed.value++, 1000);

        try {
            const res = await fetchShared(base.value, req);

            if (unmounted) {
                if (options.notifyWhenReady && elapsed.value >= 3) {
                    toast.success(
                        'Las comparativas ya están listas. Ábrelas cuando quieras: se muestran al instante.',
                    );
                }

                return res;
            }

            if (current !== seq) {
                return null;
            }

            data.value = res;

            return res;
        } catch (e) {
            if (current === seq && !unmounted && !isAbortError(e)) {
                error.value =
                    e instanceof Error
                        ? e.message
                        : 'No se pudieron calcular las comparativas.';
            }

            return null;
        } finally {
            if (current === seq) {
                loading.value = false;
                stop();
            }
        }
    }

    /** Deja de esperar y cancela la consulta en curso (el servidor la termina en segundo plano). */
    function cancel(): void {
        if (last) {
            inflight.get(keyOf(last))?.controller.abort();
        }

        ++seq;
        loading.value = false;
        stop();
    }

    /** Vuelve a pedir la última consulta al servidor, sin caché. */
    function refresh(): Promise<ComparativasPayload | null> {
        return last ? load(last, true) : Promise.resolve(null);
    }

    /** Precarga en segundo plano (sin estado visible); los errores se ignoran. */
    function prefetch(req: CompRequest): void {
        const key = keyOf(req);

        if (cache.has(key) || inflight.has(key)) {
            return;
        }

        fetchShared(base.value, req).catch(() => undefined);
    }

    function peek(req: CompRequest): ComparativasPayload | null {
        return cache.get(keyOf(req)) ?? null;
    }

    onBeforeUnmount(() => {
        unmounted = true;
        stop();
    });

    return {
        data,
        loading,
        error,
        elapsed,
        slow,
        load,
        cancel,
        refresh,
        prefetch,
        peek,
    };
}
