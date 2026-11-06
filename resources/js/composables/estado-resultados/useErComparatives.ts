import { computed, onBeforeUnmount, ref } from 'vue';
import type { Ref } from 'vue';
import type {
    ComparativasPayload,
    CompMode,
} from '@/components/estado-resultados/comparativas';
import { apiJson } from '@/components/estado-resultados/types';

export interface CompRequest {
    periodId: number;
    scope: string;
    modes: CompMode[];
    contra?: number | null;
    meses?: number;
    charts?: boolean;
}

/** Caché del módulo (sobrevive al cambio de pestaña): la primera consulta tarda ~20 s en frío. */
const cache = new Map<string, ComparativasPayload>();

const keyOf = (r: CompRequest) =>
    [
        r.periodId,
        r.scope,
        [...r.modes].sort().join(','),
        r.contra ?? '',
        r.meses ?? '',
        r.charts === false ? 0 : 1,
    ].join('|');

/**
 * Carga de comparativas con caché, cancelación de respuestas viejas y un contador
 * de segundos para explicar la espera (el cálculo en frío puede tardar).
 */
export function useErComparatives(base: Ref<string>) {
    const data = ref<ComparativasPayload | null>(null);
    const loading = ref(false);
    const error = ref<string | null>(null);
    const elapsed = ref(0);
    let timer: ReturnType<typeof setInterval> | undefined;
    let seq = 0;

    const stage = computed(() =>
        elapsed.value < 4
            ? 'Leyendo los periodos…'
            : elapsed.value < 12
              ? 'Calculando cada periodo con el mismo modelo del reporte…'
              : 'Casi listo: armando comparaciones y gráficas…',
    );

    function stop(): void {
        clearInterval(timer);
        timer = undefined;
    }

    async function load(
        req: CompRequest,
        force = false,
    ): Promise<ComparativasPayload | null> {
        const key = keyOf(req);

        if (!force && cache.has(key)) {
            data.value = cache.get(key) ?? null;
            error.value = null;

            return data.value;
        }

        const current = ++seq;
        loading.value = true;
        error.value = null;
        elapsed.value = 0;
        stop();
        timer = setInterval(() => elapsed.value++, 1000);

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

        try {
            const res = await apiJson<ComparativasPayload>(
                `${base.value}/estado-resultados/${req.periodId}/comparativas?${q.toString()}`,
            );

            if (current !== seq) {
                return null;
            }

            cache.set(key, res);
            data.value = res;

            return res;
        } catch (e) {
            if (current === seq) {
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

    function peek(req: CompRequest): ComparativasPayload | null {
        return cache.get(keyOf(req)) ?? null;
    }

    onBeforeUnmount(stop);

    return { data, loading, error, elapsed, stage, load, peek };
}
