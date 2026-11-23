/**
 * Módulo: control total de movimientos (pestaña Validación) del Estado de Resultados.
 *
 * Filtra en el navegador los movimientos del dataset (por enfoque, archivo,
 * estado y texto) y, cuando la vista lo necesita, pide al servidor las filas de
 * los mismos archivos que no están en el dataset del periodo:
 * GET `/control-movimientos` (paginado) y GET `/control-movimientos/exportar` (Excel),
 * ambas atendidas por EstadoResultadosControlController.
 */
import { computed, ref, watch } from 'vue';
import {
    FILTERS,
    controlOf,
    grossOf,
    matchesFilter,
    searchText,
} from '@/components/estado-resultados/movementControl';
import type {
    ControlFileRef,
    ControlFilter,
    ControlRef,
    ControlledMovement,
    OutsidePage,
    OutsideRow,
} from '@/components/estado-resultados/movementControl';
import { apiJson, isAbortError } from '@/components/estado-resultados/types';
import { downloadFile } from '@/lib/downloadOverlay';
import { showError, swal } from './erSwal';

/** Contexto que la pantalla del ER inyecta al control de movimientos. */
interface Ctx {
    /** Movimientos del dataset del periodo mostrado. */
    movements: () => ControlledMovement[];
    /** `/estado-resultados/{periodo}` + path */
    url: (path: string) => string;
    /** Query string con el alcance (la versión se quita antes de llamar al servidor). */
    scopeQuery: () => string;
}

/** Enfoque: lo que abrió la lista (una partida sin resolver, una validación, un archivo). */
interface Focus {
    title: string;
    note: string | null;
    /** Huellas de los movimientos de la partida (null = sin restricción). */
    fingerprints: Set<string> | null;
    /** Partidas sin huella (no se pueden mostrar como movimiento individual). */
    redacted: number;
    /** Archivos involucrados (activan la vista remota «no_guardado»). */
    files: ControlFileRef[];
}

/** Máximo de huellas que se mandan en la URL de exportación; con más se exporta por estado. */
const MAX_EXPORT_FPS = 300;

/**
 * Control total de movimientos de la pestaña Validación: filtros por estado, por archivo y por
 * partida; filas de los mismos archivos en otros periodos / no guardadas (endpoint liviano,
 * paginado) y exportación a Excel de la lista filtrada.
 * @param ctx Movimientos del dataset y helpers de URL/alcance.
 * @returns Estado de filtros, listas calculadas, filas remotas y acciones.
 */
export function useMovementControl(ctx: Ctx) {
    /** Filtro por estado de control. */
    const filter = ref<ControlFilter>('todos');
    /** Archivo (carga) seleccionado, o null para todos. */
    const uploadId = ref<number | null>(null);
    /** Texto de búsqueda libre. */
    const search = ref('');
    /** Cuántas filas locales se pintan (se reinicia a 100 al cambiar de vista). */
    const limit = ref(100);
    /** Enfoque activo (partida/validación/archivo que abrió la lista). */
    const focus = ref<Focus | null>(null);

    /** Filas traídas del servidor que no están en el dataset (acumuladas por página). */
    const remote = ref<OutsideRow[]>([]);
    const remoteTotal = ref(0);
    const remotePage = ref(1);
    const remoteLastPage = ref(1);
    const remoteFiles = ref<NonNullable<OutsidePage['files']>>([]);
    const remoteLoading = ref(false);
    const remoteError = ref<string | null>(null);
    /** Controlador de la petición remota en curso (se aborta al pedir otra). */
    let abort: AbortController | null = null;

    /** Movimientos del dataset tras el enfoque y el archivo (antes del filtro por estado). */
    const base = computed(() => {
        const fps = focus.value?.fingerprints ?? null;

        return ctx
            .movements()
            .filter(
                (m) =>
                    (fps === null || fps.has(m.fingerprint)) &&
                    (uploadId.value === null || m.upload_id === uploadId.value),
            );
    });

    /**
     * Conteo e importe bruto por filtro sobre `base`. «revisar» suma los movimientos
     * con alguna bandera, además de contarlos en su estado.
     */
    const counts = computed(() => {
        const out: Record<string, { count: number; amount: number }> = {};

        for (const f of FILTERS) {
            out[f.key] = { count: 0, amount: 0 };
        }

        for (const m of base.value) {
            const c = controlOf(m);
            const g = grossOf(m);
            out.todos.count++;
            out.todos.amount += g;
            out[c.status].count++;
            out[c.status].amount += g;

            if (c.flags.length) {
                out.revisar.count++;
                out.revisar.amount += g;
            }
        }

        return out;
    });

    /** `base` filtrado por estado y por el texto de búsqueda. */
    const filtered = computed(() => {
        const q = search.value.trim().toLowerCase();

        return base.value.filter(
            (m) =>
                matchesFilter(m, filter.value) &&
                (!q || searchText(m).includes(q)),
        );
    });
    /** Filas a pintar (las primeras `limit`). */
    const visible = computed(() => filtered.value.slice(0, limit.value));
    /** Importe bruto total de la lista filtrada. */
    const filteredAmount = computed(() =>
        filtered.value.reduce((s, m) => s + grossOf(m), 0),
    );

    /** Archivos con movimientos en la lista (para el filtro por archivo). */
    const files = computed(() => {
        const map = new Map<number, string>();

        for (const m of ctx.movements()) {
            if (m.upload_id && m.file) {
                map.set(m.upload_id, m.file);
            }
        }

        return [...map.entries()].map(([id, file]) => ({ id, file }));
    });

    /**
     * ¿La vista actual necesita filas que no están en el dataset del periodo?
     * - enfoque con archivos → «no_guardado»; enfoque sin archivos → nada remoto;
     * - filtro «no_guardado» → «no_guardado»;
     * - filtros «fuera», «duplicado» o «todos» → «fuera».
     */
    const remoteVista = computed<'fuera' | 'no_guardado' | null>(() => {
        if (focus.value?.fingerprints) {
            return focus.value.files.length ? 'no_guardado' : null;
        }

        if (filter.value === 'no_guardado') {
            return 'no_guardado';
        }

        return ['fuera', 'duplicado', 'todos'].includes(filter.value)
            ? 'fuera'
            : null;
    });

    /**
     * Pide al servidor una página de filas remotas para la vista actual (cancelando
     * la anterior). La página 1 reemplaza la lista; las siguientes se agregan.
     * Con filtro «fuera» se descartan en el navegador las filas de otro estado.
     * @param page Página a cargar.
     */
    async function loadRemote(page = 1): Promise<void> {
        const vista = remoteVista.value;
        abort?.abort();

        if (!vista) {
            abort = null;
            remote.value = [];
            remoteTotal.value = 0;
            remoteFiles.value = [];
            remoteLoading.value = false;

            return;
        }

        // Controlador propio de esta petición: sólo la más reciente apaga el
        // indicador de carga (una abortada no debe ocultar el de la nueva).
        const controller = new AbortController();
        abort = controller;
        remoteLoading.value = true;
        remoteError.value = null;
        const params = new URLSearchParams(ctx.scopeQuery());
        params.delete('version');
        params.set('vista', vista);
        params.set('page', String(page));

        if (uploadId.value !== null) {
            params.set('archivo', String(uploadId.value));
        }

        if (vista === 'fuera' && filter.value === 'duplicado') {
            params.set('ubicacion', 'duplicado');
        }

        try {
            const res = await apiJson<OutsidePage>(
                ctx.url(`/control-movimientos?${params.toString()}`),
                { signal: controller.signal },
            );

            if (controller !== abort) {
                return;
            }

            const rows =
                vista === 'fuera' && filter.value === 'fuera'
                    ? res.data.filter((r) => r.status === 'fuera')
                    : res.data;
            remote.value = page === 1 ? rows : [...remote.value, ...rows];
            remoteTotal.value = res.total;
            remotePage.value = res.page;
            remoteLastPage.value = res.last_page;
            remoteFiles.value = res.files ?? [];
        } catch (e) {
            if (!isAbortError(e) && controller === abort) {
                remoteError.value =
                    e instanceof Error
                        ? e.message
                        : 'No se pudieron cargar las filas de los archivos.';
            }
        } finally {
            if (controller === abort) {
                remoteLoading.value = false;
            }
        }
    }

    /** Al cambiar vista remota, archivo o filtro: reinicia el límite y recarga la página 1. */
    watch([remoteVista, uploadId, filter], () => {
        limit.value = 100;
        void loadRemote(1);
    });

    /** Cambia el filtro por estado. */
    function setFilter(f: ControlFilter): void {
        filter.value = f;
    }

    /**
     * Abre la lista enfocada en las partidas que explican una validación o diferencia.
     * Reinicia filtro, archivo y búsqueda. Si no hay partidas, archivos, filtro ni
     * archivo que mostrar, sólo informa que la validación no viene de movimientos.
     */
    function focusOn(opts: {
        title: string;
        note?: string | null;
        items?: ControlRef[];
        files?: ControlFileRef[];
        filter?: ControlFilter;
        uploadId?: number | null;
    }): void {
        const items = opts.items ?? [];
        const fps = new Set(
            items.map((i) => i.fingerprint).filter((f): f is string => !!f),
        );
        focus.value =
            items.length || opts.files?.length
                ? {
                      title: opts.title,
                      note: opts.note ?? null,
                      fingerprints: fps,
                      redacted: items.filter((i) => !i.fingerprint).length,
                      files: opts.files ?? [],
                  }
                : null;
        filter.value = opts.filter ?? 'todos';
        uploadId.value = opts.uploadId ?? null;
        search.value = '';

        if (focus.value === null && !opts.filter && !opts.uploadId) {
            void swal.fire({
                icon: 'info',
                title: opts.title,
                text:
                    opts.note ??
                    'Esta validación no proviene de movimientos individuales.',
            });
        }
    }

    /** Quita el enfoque y el filtro por archivo, y vuelve a «todos» (conserva la búsqueda). */
    function clearFocus(): void {
        focus.value = null;
        uploadId.value = null;
        filter.value = 'todos';
    }

    /**
     * Exporta a Excel la lista filtrada (estado + archivo + huellas del enfoque).
     * Si el enfoque supera MAX_EXPORT_FPS huellas, pide confirmar exportar sólo por estado.
     */
    async function exportExcel(): Promise<void> {
        const params = new URLSearchParams(ctx.scopeQuery());
        params.delete('version');
        params.set('estado', filter.value);

        if (uploadId.value !== null) {
            params.set('archivo', String(uploadId.value));
        }

        const fps = focus.value?.fingerprints
            ? [...focus.value.fingerprints]
            : [];

        if (fps.length > MAX_EXPORT_FPS) {
            const ok = await swal.fire({
                icon: 'question',
                title: 'Lista demasiado grande para exportar por partida',
                text: `La partida tiene ${fps.length.toLocaleString('es-MX')} movimientos. Se exportará la lista por estado «${filter.value}» (sin limitar a esta partida). ¿Continuar?`,
                showCancelButton: true,
                confirmButtonText: 'Exportar',
                cancelButtonText: 'Cancelar',
            });

            if (!ok.isConfirmed) {
                return;
            }
        } else {
            fps.forEach((f) => params.append('huellas[]', f));
        }

        try {
            await downloadFile(
                ctx.url(`/control-movimientos/exportar?${params.toString()}`),
            );
        } catch (e) {
            showError(e, 'No se pudo exportar la lista');
        }
    }

    return {
        filter,
        uploadId,
        search,
        limit,
        focus,
        counts,
        filtered,
        visible,
        filteredAmount,
        files,
        remote,
        remoteTotal,
        remotePage,
        remoteLastPage,
        remoteFiles,
        remoteLoading,
        remoteError,
        remoteVista,
        loadRemote,
        setFilter,
        focusOn,
        clearFocus,
        exportExcel,
    };
}

/** Tipo del objeto que devuelve `useMovementControl` (para props de componentes hijos). */
export type MovementControl = ReturnType<typeof useMovementControl>;
