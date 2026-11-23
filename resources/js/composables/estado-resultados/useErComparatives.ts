/**
 * Módulo: comparativas del Estado de Resultados.
 *
 * Consulta `{base}/estado-resultados/{periodo}/comparativas`
 * (EstadoResultadosComparativoController::show) (vs. mes anterior,
 * mismo mes del año anterior, evolución, acumulado y por sucursal). El cálculo
 * puede ser lento, por eso hay:
 * - caché a nivel de módulo (compartida entre componentes y pestañas, máx. 24),
 * - deduplicación de peticiones en curso por clave,
 * - precarga, cancelación y un contador de segundos para explicar la espera.
 */
import { computed, onBeforeUnmount, ref } from 'vue';
import type { Ref } from 'vue';
import type {
    ComparativasPayload,
    CompMode,
} from '@/components/estado-resultados/comparativas';
import { apiJson, isAbortError } from '@/components/estado-resultados/types';
import { useToast } from '@/composables/useToast';

/** Parámetros de una consulta de comparativas (definen también su clave de caché). */
export interface CompRequest {
    /** Periodo base de la comparación. */
    periodId: number;
    /** Alcance (consolidado o sucursal). */
    scope: string;
    /** Modos de comparación solicitados. */
    modes: CompMode[];
    /** Periodo contra el que se compara (opcional; el servidor elige si falta). */
    contra?: number | null;
    /** Meses a incluir en la evolución. */
    meses?: number;
    /** Si se piden los datos de gráficas (por defecto sí). */
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

/**
 * Consulta exacta de la pestaña (la misma clave sirve para precargarla).
 * @param periodId Periodo mostrado.
 * @param scope Alcance mostrado.
 * @param rev Revisión del dataset (invalida la caché cuando cambian los datos).
 * @returns La petición con los modos de la pestaña y 6 meses de evolución.
 */
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
/** Máximo de respuestas en caché; se descarta la usada hace más tiempo (LRU por orden de inserción). */
const MAX_CACHED = 24;

/**
 * Clave estable de una consulta: los modos se ordenan para que el orden en que
 * se pidieron no genere claves distintas.
 */
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

/**
 * Arma la URL GET de comparativas con sus parámetros (modos, alcance, gráficas,
 * contra y meses). `rev` no viaja al servidor: sólo distingue la clave de caché.
 */
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

/**
 * Una sola petición por clave aunque la pidan las tarjetas, la pestaña y la precarga.
 * Al resolver, guarda la respuesta en caché (reinsertándola para marcarla como reciente).
 * @returns Promesa compartida con la respuesta del servidor.
 */
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
 * @param base Prefijo de la app (props.base de Inertia).
 * @param options Opciones de comportamiento.
 * @returns Estado reactivo (data, loading, error, elapsed, slow) y acciones.
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
    /** Intervalo que incrementa `elapsed` cada segundo mientras se espera. */
    let timer: ReturnType<typeof setInterval> | undefined;
    /** Número de carga vigente: descarta respuestas de cargas anteriores. */
    let seq = 0;
    /** Última consulta pedida (para cancelar o refrescar). */
    let last: CompRequest | null = null;
    let unmounted = false;

    /** Detiene el contador de segundos. */
    function stop(): void {
        clearInterval(timer);
        timer = undefined;
    }

    /**
     * Carga una consulta: si está en caché (y no es `force`) la muestra al instante;
     * si no, la pide (compartiendo la petición en curso) y lleva el contador.
     * Si el componente se desmontó mientras esperaba, opcionalmente avisa con un toast.
     * @param req Consulta a cargar.
     * @param force true para ignorar la caché.
     * @returns La respuesta, o null si falló o quedó obsoleta.
     */
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

    /** Lee de la caché sin pedir nada al servidor (null si no está). */
    function peek(req: CompRequest): ComparativasPayload | null {
        return cache.get(keyOf(req)) ?? null;
    }

    /** Al desmontar no se cancela la petición (sigue para la caché); sólo se marca y se detiene el contador. */
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
