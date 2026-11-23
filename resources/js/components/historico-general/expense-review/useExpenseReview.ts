import { computed, ref, watch } from 'vue';
import type { Ref } from 'vue';
import type {
    DecisionPayload,
    ReviewFilter,
    ReviewOptions,
    ReviewPage,
} from './types';
import { reviewGet, reviewPost } from './types';

/**
 * Estado del listado de Revisión de gastos: filtros, paginación, selección y guardado.
 * La búsqueda se aplica con debounce; cambiar filtro/búsqueda/fuente vuelve a la página 1.
 *
 * Rutas que llama (prefijo `/historico-general/{periodo}/revision-gastos`):
 * - GET `` → página del listado (`ReviewPage`).
 * - GET `/opciones` → catálogos y permisos (`ReviewOptions`).
 * - POST `/decisiones` → guarda una decisión para ids concretos o para todo el filtro actual.
 *
 * @param periodId Id del periodo activo (reactivo); si es `null` no se hace ninguna petición.
 * @returns Estado reactivo (filtros, datos, selección) y acciones (`load`, `save`, etc.).
 */
export function useExpenseReview(periodId: Ref<number | null>) {
    /** Ruta base del apartado para el periodo actual. */
    const base = computed(
        () => `/historico-general/${periodId.value}/revision-gastos`,
    );

    /** Filtro activo, texto de búsqueda, fuente, orden y paginación del listado. */
    const filter = ref<ReviewFilter>('pendientes');
    const search = ref('');
    const source = ref('');
    const sort = ref<'monto' | 'fecha' | 'concepto'>('monto');
    const page = ref(1);
    const perPage = ref(25);

    /** Última página cargada, opciones del apartado y banderas de carga/guardado/error. */
    const data = ref<ReviewPage | null>(null);
    const options = ref<ReviewOptions | null>(null);
    const loading = ref(false);
    const saving = ref(false);
    const error = ref<string | null>(null);

    // Selección: ids explícitos o «todos los del filtro actual».
    const selected = ref<Set<number>>(new Set());
    const allMatching = ref(false);

    /** Número de gastos seleccionados: el total del filtro si se eligió «todos», si no los ids marcados. */
    const selectionCount = computed(() =>
        allMatching.value
            ? (data.value?.pagination.total ?? 0)
            : selected.value.size,
    );

    /** Contador de peticiones: solo la respuesta más reciente actualiza el estado (evita carreras). */
    let requestSeq = 0;
    /** Página de la última petición lanzada (evita una segunda petición desde el watch de `page`). */
    let requestedPage: number | null = null;

    /**
     * Carga la página actual del listado con los filtros vigentes.
     * Ignora respuestas obsoletas (si llegó otra petición después) y guarda el error legible.
     */
    async function load() {
        if (!periodId.value) {
            return;
        }

        const seq = ++requestSeq;
        requestedPage = page.value;
        loading.value = true;
        error.value = null;

        try {
            const res = await reviewGet<ReviewPage>(base.value, {
                filter: filter.value,
                q: search.value.trim(),
                source: source.value,
                sort: sort.value,
                page: page.value,
                per_page: perPage.value,
            });

            if (seq === requestSeq) {
                data.value = res;
                page.value = res.pagination.page;
            }
        } catch (e: any) {
            if (seq === requestSeq) {
                error.value =
                    e?.message ?? 'No se pudo cargar el desglose de gastos.';
            }
        } finally {
            if (seq === requestSeq) {
                loading.value = false;
            }
        }
    }

    /**
     * Carga las opciones del apartado (colaboradores, sucursales, fuentes y permisos).
     * Si falla, deja `options` en `null` (la UI queda en modo sin opciones).
     */
    async function loadOptions() {
        if (!periodId.value) {
            return;
        }

        try {
            options.value = await reviewGet<ReviewOptions>(
                `${base.value}/opciones`,
            );
        } catch {
            options.value = null;
        }
    }

    /** Vacía la selección (ids y modo «todos los del filtro»). */
    function clearSelection() {
        selected.value = new Set();
        allMatching.value = false;
    }

    /**
     * Marca o desmarca un gasto. Sale del modo «todos los del filtro».
     *
     * @param id    Id del gasto.
     * @param value `true` para seleccionar, `false` para quitar.
     */
    function toggle(id: number, value: boolean) {
        const next = new Set(selected.value);

        if (value) {
            next.add(id);
        } else {
            next.delete(id);
        }

        selected.value = next;
        allMatching.value = false;
    }

    /**
     * Marca o desmarca todos los gastos de la página visible. Sale del modo «todos los del filtro».
     *
     * @param value `true` para seleccionar la página, `false` para quitarla.
     */
    function togglePage(value: boolean) {
        const next = new Set(selected.value);

        for (const row of data.value?.rows ?? []) {
            if (value) {
                next.add(row.id);
            } else {
                next.delete(row.id);
            }
        }

        selected.value = next;
        allMatching.value = false;
    }

    /**
     * Guarda una decisión para ids concretos o para la selección actual.
     * Si no se pasan ids y está activo «todos los del filtro», envía los criterios del filtro
     * para que el backend aplique la decisión a todos los gastos que coinciden.
     * Tras guardar recarga el listado; si fue sobre la selección, la limpia.
     *
     * @param payload Decisión de colaborador/sucursal y notas.
     * @param ids     Ids concretos (decisión individual); si se omite se usa la selección.
     * @returns Mensaje del servidor y conteo de decisiones guardadas/eliminadas.
     * @throws ReviewHttpError si el backend rechaza la decisión.
     */
    async function save(payload: DecisionPayload, ids?: number[]) {
        saving.value = true;

        try {
            const useAll = !ids && allMatching.value;
            const res = await reviewPost<{
                message: string;
                saved: number;
                removed: number;
            }>(`${base.value}/decisiones`, {
                ...payload,
                expense_ids: useAll ? [] : (ids ?? [...selected.value]),
                all_matching: useAll,
                criteria: useAll
                    ? {
                          filter: filter.value,
                          q: search.value.trim(),
                          source: source.value || null,
                      }
                    : undefined,
            });

            if (!ids) {
                clearSelection();
            }

            await load();

            return res;
        } finally {
            saving.value = false;
        }
    }

    /** Temporizador del debounce de la búsqueda. */
    let debounce: ReturnType<typeof setTimeout> | undefined;

    /** Búsqueda: espera 350 ms sin teclear, vuelve a la página 1, limpia la selección y recarga. */
    watch(search, () => {
        clearTimeout(debounce);
        debounce = setTimeout(() => {
            page.value = 1;
            clearSelection();
            load();
        }, 350);
    });

    /** Cambio de filtro, fuente, orden o tamaño de página: vuelve a la página 1, limpia la selección y recarga. */
    watch([filter, source, sort, perPage], () => {
        page.value = 1;
        clearSelection();
        load();
    });

    /**
     * Cambio de página desde la paginación: recarga solo si difiere de la página ya
     * cargada y de la ya solicitada (un cambio de filtro fuera de la página 1 pone
     * page=1 y llama load() él mismo; sin esta guarda se lanzaban dos peticiones).
     */
    watch(page, (value, old) => {
        if (
            value !== old &&
            data.value &&
            value !== data.value.pagination.page &&
            value !== requestedPage
        ) {
            load();
        }
    });

    /** Cambio de periodo: reinicia selección y página y recarga listado y opciones. */
    watch(periodId, () => {
        clearSelection();
        page.value = 1;
        load();
        loadOptions();
    });

    return {
        filter,
        search,
        source,
        sort,
        page,
        perPage,
        data,
        options,
        loading,
        saving,
        error,
        selected,
        allMatching,
        selectionCount,
        load,
        loadOptions,
        save,
        toggle,
        togglePage,
        clearSelection,
    };
}
