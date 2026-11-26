import { ref, shallowRef } from 'vue';
import type { HardDataDataset } from '@/types/hardData';

/**
 * Carga los datos duros de un periodo (GET /datos-duros/periodos/{id}) con caché
 * en memoria + sessionStorage: volver a un periodo ya visto es instantáneo y se
 * revalida en segundo plano (stale-while-revalidate). sessionStorage se borra al
 * cerrar la pestaña, así que las cifras nunca quedan guardadas en el equipo.
 */
const STORAGE_PREFIX = 'datos-duros:v1:';
const memory = new Map<number, HardDataDataset>();

function readStored(periodId: number): HardDataDataset | null {
    if (memory.has(periodId)) {
        return memory.get(periodId)!;
    }

    try {
        const raw = sessionStorage.getItem(STORAGE_PREFIX + periodId);

        if (raw) {
            const parsed = JSON.parse(raw) as HardDataDataset;
            memory.set(periodId, parsed);

            return parsed;
        }
    } catch {
        // sessionStorage lleno o deshabilitado: solo memoria.
    }

    return null;
}

function store(periodId: number, data: HardDataDataset): void {
    memory.set(periodId, data);

    try {
        sessionStorage.setItem(STORAGE_PREFIX + periodId, JSON.stringify(data));
    } catch {
        // Sin espacio: se queda solo en memoria.
    }
}

export function useHardDataset() {
    const dataset = shallowRef<HardDataDataset | null>(null);
    const loading = ref(false);
    const revalidating = ref(false);
    const error = ref<string | null>(null);
    let controller: AbortController | null = null;
    let seq = 0;

    async function load(periodId: number): Promise<void> {
        controller?.abort();
        controller = new AbortController();
        const mySeq = ++seq;
        error.value = null;

        const cached = readStored(periodId);

        if (cached) {
            dataset.value = cached;
            revalidating.value = true;
        } else {
            loading.value = true;
        }

        try {
            const resp = await fetch(`/datos-duros/periodos/${periodId}`, {
                signal: controller.signal,
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });

            if (!resp.ok) {
                const body = await resp.json().catch(() => null);

                throw new Error(
                    body?.message || `El servidor respondió ${resp.status}.`,
                );
            }

            const json = (await resp.json()) as HardDataDataset;

            if (mySeq !== seq) {
                return;
            }

            store(periodId, json);
            dataset.value = json;
        } catch (e: unknown) {
            if (
                (e as { name?: string })?.name === 'AbortError' ||
                mySeq !== seq
            ) {
                return;
            }

            // Con datos en caché no se interrumpe la consulta: solo se avisa.
            error.value =
                e instanceof TypeError
                    ? 'Sin conexión con el servidor. Revisa tu red e intenta de nuevo.'
                    : (e as Error)?.message ||
                      'No se pudieron cargar los datos del periodo.';
        } finally {
            if (mySeq === seq) {
                loading.value = false;
                revalidating.value = false;
            }
        }
    }

    return { dataset, loading, revalidating, error, load };
}
