import { onBeforeUnmount, ref } from 'vue';
import {
    describeHttpFailure,
    isActiveStatus,
} from '@/components/efectividad/format';
import type { RunDetail } from '@/components/efectividad/types';
import { progress as progressRoute } from '@/routes/efectividad-cobranza';

/**
 * Consulta el estado REAL (BD) de una ejecución mientras siga activa.
 *
 * El ID que se sigue siempre proviene del servidor (props de la página o la
 * respuesta de "Generar"); este composable no es la fuente de verdad — si el
 * usuario sale y vuelve, la página vuelve a recibir las ejecuciones activas
 * desde la BD y reanuda el seguimiento.
 *
 *  - Nunca hay dos solicitudes simultáneas.
 *  - Con la pestaña oculta se omiten consultas (el trabajo sigue en el servidor).
 *  - Se detiene solo al llegar a un estado final o al desmontar la página.
 */
export function useEfectividadRunPolling(options: {
    onUpdate: (detail: RunDetail) => void;
    onFinished?: (detail: RunDetail) => void;
    onError?: (message: string) => void;
    intervalMs?: number;
}) {
    const refreshing = ref(false);
    const error = ref<string | null>(null);
    let timer: ReturnType<typeof setInterval> | null = null;
    let trackedId: number | null = null;
    let inFlight = false;

    async function fetchOnce(
        id: number,
        force = false,
    ): Promise<RunDetail | null> {
        if (
            inFlight ||
            (!force &&
                typeof document !== 'undefined' &&
                document.visibilityState === 'hidden')
        ) {
            return null;
        }

        inFlight = true;
        refreshing.value = true;

        let res: Response | null = null;

        try {
            res = await fetch(progressRoute(id).url, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
            });

            if (!res.ok) {
                const message = await describeHttpFailure(res, null);
                error.value = message;
                options.onError?.(message);

                if (
                    res.status === 401 ||
                    res.status === 403 ||
                    res.status === 404 ||
                    res.status === 419
                ) {
                    stop();
                }

                return null;
            }

            const detail = (await res.json()) as RunDetail;
            error.value = null;

            // Respuesta tardía de una ejecución que ya no se sigue: se descarta.
            if (trackedId !== null && id !== trackedId) {
                return detail;
            }

            options.onUpdate(detail);

            if (trackedId === id && !isActiveStatus(detail.status)) {
                stop();
                options.onFinished?.(detail);
            }

            return detail;
        } catch (e) {
            // Falla de red momentánea: se informa pero se sigue intentando — el trabajo
            // continúa en el servidor aunque esta consulta falle.
            const message = await describeHttpFailure(res, e);
            error.value = message;
            options.onError?.(message);

            return null;
        } finally {
            inFlight = false;
            refreshing.value = false;
        }
    }

    function start(id: number) {
        if (trackedId === id && timer) {
            return;
        }

        stop();
        trackedId = id;
        timer = setInterval(() => fetchOnce(id), options.intervalMs ?? 3000);
        void fetchOnce(id, true);
    }

    function stop() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }

        trackedId = null;
    }

    function refreshNow(id: number) {
        return fetchOnce(id, true);
    }

    onBeforeUnmount(stop);

    return { start, stop, refreshNow, refreshing, error };
}
