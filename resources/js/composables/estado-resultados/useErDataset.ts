import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    ApiError,
    apiJson,
    isAbortError,
} from '@/components/estado-resultados/types';
import type { Dataset } from '@/components/estado-resultados/types';

export interface ScopeOption {
    key: string;
    label: string;
}

interface Entry {
    dataset: Dataset;
    scopes: ScopeOption[];
}

/** Últimos datasets vistos en esta pestaña (volver a un alcance/periodo es instantáneo). */
const MAX_CACHED = 8;

/**
 * Estado y carga del dataset canónico del Estado de Resultados (periodo + alcance).
 *
 *  - Un solo disparador: cambiar periodo (que ajusta alcance y versión) hace UNA petición.
 *  - La petición anterior se cancela (AbortController): nunca pisa una respuesta vieja.
 *  - Al cambiar de periodo se conserva el alcance si sigue permitido (un usuario de sucursal
 *    nunca cae en el consolidado, que el servidor le rechaza); si no, el primero permitido.
 *  - Volver a un periodo/alcance ya visto se muestra al instante y se revalida en silencio.
 *  - Si falla una RECARGA (tras guardar algo) se conserva lo último cargado y se avisa.
 */
export function useErDataset(initial: {
    periodId: number;
    scope: string;
    version?: number | null;
}) {
    const page = usePage();
    const appBase = (page.props as { base?: string }).base ?? '';

    const periodId = ref<number>(initial.periodId);
    const scope = ref<string>(initial.scope || 'CONSOLIDADO');
    const scopes = ref<ScopeOption[]>([
        {
            key: initial.scope || 'CONSOLIDADO',
            label: initial.scope || 'CONSOLIDADO',
        },
    ]);
    /** Versión aprobada/sustituida que se consulta (null = la vigente o en vivo). */
    const viewVersion = ref<number | null>(initial.version ?? null);
    const dataset = ref<Dataset | null>(null);
    /** true mientras se espera al servidor sin una versión utilizable de lo pedido. */
    const loading = ref(false);
    /** true mientras se revalida en silencio algo que ya se muestra (de la memoria). */
    const revalidating = ref(false);
    const loadError = ref<string | null>(null);
    /** Estado HTTP del último error (403 alcance, 404, 419 sesión…), 0 = red. */
    const loadErrorStatus = ref<number | null>(null);
    /** Se dispara tras cada recarga exitosa (para refrescar vistas abiertas). */
    const version = ref(0);
    /** Momento en que empezó la carga en curso (para la pantalla de carga). */
    const loadingSince = ref<number | null>(null);

    const url = (path: string) =>
        `${appBase}/estado-resultados/${periodId.value}${path}`;
    const scopeQuery = () =>
        `alcance=${encodeURIComponent(scope.value)}` +
        (viewVersion.value ? `&version=${viewVersion.value}` : '');
    const consolidated = computed(
        () => dataset.value?.meta.scope.consolidated ?? true,
    );
    const keyOf = () =>
        `${periodId.value}|${scope.value}|${viewVersion.value ?? ''}`;
    const datasetKey = (d: Dataset | null) =>
        d
            ? `${d.meta.period.id}|${d.meta.scope.key}|${viewVersion.value ?? ''}`
            : null;
    /** Falló una recarga pero se sigue mostrando lo último cargado de lo mismo. */
    const reloadFailed = computed(
        () => !!loadError.value && datasetKey(dataset.value) === keyOf(),
    );

    const memory = new Map<string, Entry>();
    let controller: AbortController | null = null;
    let requestId = 0;

    function remember(key: string, entry: Entry): void {
        memory.delete(key);
        memory.set(key, entry);

        while (memory.size > MAX_CACHED) {
            memory.delete(memory.keys().next().value as string);
        }
    }

    /**
     * Carga el dataset del periodo/alcance/versión actuales.
     * `force` (por defecto tras una acción) ignora la memoria: siempre pregunta al servidor.
     */
    async function loadDataset(opts: { force?: boolean } = {}): Promise<void> {
        if (!periodId.value) {
            return;
        }

        const force = opts.force ?? true;
        const key = keyOf();
        const cached = force ? null : memory.get(key);
        const current = ++requestId;
        controller?.abort();
        controller = new AbortController();
        loadError.value = null;
        loadErrorStatus.value = null;

        if (cached) {
            dataset.value = cached.dataset;
            scopes.value = cached.scopes;
            version.value++;
            loading.value = false;
            revalidating.value = true;
        } else {
            loading.value = true;
            loadingSince.value = Date.now();
        }

        try {
            const res = await apiJson<Entry>(url(`/dataset?${scopeQuery()}`), {
                signal: controller.signal,
            });

            if (current !== requestId) {
                return;
            }

            remember(key, res);
            dataset.value = res.dataset;
            scopes.value = res.scopes;
            version.value++;
        } catch (e) {
            if (current !== requestId || isAbortError(e)) {
                return;
            }

            const status = e instanceof ApiError ? e.status : 0;

            // Alcance que no existe en este periodo o no permitido: se pasa al primero permitido.
            const fallback = scopes.value[0]?.key;

            if (
                (status === 403 || status === 422) &&
                fallback &&
                fallback !== scope.value &&
                !cached
            ) {
                scope.value = fallback; // dispara una sola recarga (schedule)

                return;
            }

            loadError.value =
                e instanceof Error
                    ? e.message
                    : 'No se pudo calcular el Estado de Resultados.';
            loadErrorStatus.value = status;

            // Recarga fallida: se conserva lo último cargado de lo MISMO. Si era otro
            // periodo/alcance no se muestran cifras ajenas.
            if (datasetKey(dataset.value) !== key) {
                dataset.value = null;
            }
        } finally {
            if (current === requestId) {
                loading.value = false;
                revalidating.value = false;
                loadingSince.value = null;
            }
        }
    }

    /** Cancela la carga en curso (p. ej. «Cancelar» en la pantalla de carga). */
    function abort(): void {
        requestId++;
        controller?.abort();
        loading.value = false;
        revalidating.value = false;
        loadingSince.value = null;
    }

    // Mantiene la URL compartible (periodo + alcance) sin recargar la página.
    function syncUrl(): void {
        if (typeof window === 'undefined') {
            return;
        }

        const u = new URL(window.location.href);
        u.searchParams.set('periodo', String(periodId.value));
        u.searchParams.set('alcance', scope.value);

        if (viewVersion.value) {
            u.searchParams.set('version', String(viewVersion.value));
        } else {
            u.searchParams.delete('version');
        }

        window.history.replaceState(window.history.state, '', u.toString());
    }

    // Varios cambios en el mismo ciclo (periodo → alcance → versión) = UNA sola carga.
    let scheduled = false;

    function schedule(): void {
        if (scheduled) {
            return;
        }

        scheduled = true;
        queueMicrotask(() => {
            scheduled = false;
            syncUrl();
            loadDataset({ force: false });
        });
    }

    watch(periodId, () => {
        // Se conserva el alcance si el usuario lo tiene permitido; si no, el primero permitido.
        if (!scopes.value.some((s) => s.key === scope.value)) {
            scope.value = scopes.value[0]?.key ?? initial.scope;
        }

        viewVersion.value = null;
        schedule();
    });
    watch(scope, schedule);
    watch(viewVersion, schedule);
    onMounted(() => loadDataset({ force: false }));
    onBeforeUnmount(() => controller?.abort());

    return {
        periodId,
        scope,
        scopes,
        viewVersion,
        dataset,
        loading,
        revalidating,
        loadError,
        loadErrorStatus,
        reloadFailed,
        loadingSince,
        version,
        consolidated,
        url,
        scopeQuery,
        /** Recarga desde el servidor (tras guardar, procesar, aprobar…). */
        loadDataset: () => loadDataset({ force: true }),
        abort,
    };
}
