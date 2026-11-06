import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { apiJson } from '@/components/estado-resultados/types';
import type { Dataset } from '@/components/estado-resultados/types';

export interface ScopeOption {
    key: string;
    label: string;
}

/**
 * Estado y carga del dataset canónico del Estado de Resultados (periodo +
 * alcance). Descarta respuestas viejas si el usuario cambia de periodo rápido.
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
        { key: 'CONSOLIDADO', label: 'Consolidado MR LANA' },
    ]);
    /** Versión aprobada/sustituida que se consulta (null = la vigente o en vivo). */
    const viewVersion = ref<number | null>(initial.version ?? null);
    const dataset = ref<Dataset | null>(null);
    const loading = ref(false);
    const loadError = ref<string | null>(null);
    /** Se dispara tras cada recarga exitosa (para refrescar vistas abiertas). */
    const version = ref(0);

    const url = (path: string) =>
        `${appBase}/estado-resultados/${periodId.value}${path}`;
    const scopeQuery = () =>
        `alcance=${encodeURIComponent(scope.value)}` +
        (viewVersion.value ? `&version=${viewVersion.value}` : '');
    const consolidated = computed(
        () => dataset.value?.meta.scope.consolidated ?? true,
    );

    let requestId = 0;

    async function loadDataset(): Promise<void> {
        if (!periodId.value) {
            return;
        }

        const current = ++requestId;
        loading.value = true;
        loadError.value = null;

        try {
            const res = await apiJson<{
                dataset: Dataset;
                scopes: ScopeOption[];
            }>(url(`/dataset?${scopeQuery()}`));

            if (current !== requestId) {
                return;
            }

            dataset.value = res.dataset;
            scopes.value = res.scopes;
            version.value++;
        } catch (e) {
            if (current === requestId) {
                loadError.value =
                    e instanceof Error
                        ? e.message
                        : 'No se pudo calcular el Estado de Resultados.';
                dataset.value = null;
            }
        } finally {
            if (current === requestId) {
                loading.value = false;
            }
        }
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

    watch(periodId, () => {
        scope.value = 'CONSOLIDADO';
        viewVersion.value = null;
        syncUrl();
        loadDataset();
    });
    watch(scope, () => {
        syncUrl();
        loadDataset();
    });
    watch(viewVersion, () => {
        syncUrl();
        loadDataset();
    });
    onMounted(loadDataset);

    return {
        periodId,
        scope,
        scopes,
        viewVersion,
        dataset,
        loading,
        loadError,
        version,
        consolidated,
        url,
        scopeQuery,
        loadDataset,
    };
}
