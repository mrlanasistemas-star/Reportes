<script setup lang="ts">
/**
 * MovementControlList — lista de control total de movimientos (pestaña Validación).
 *
 * Vive dentro del modal «Control de movimientos» de ValidationPanel. Arriba, una barra fija
 * con el enfoque activo, los filtros por estado con conteos, la búsqueda, el filtro por
 * archivo, la vista Lista/Agrupados de pendientes, la selección y la exportación a Excel.
 * En medio, con desplazamiento interno, los movimientos del periodo AGRUPADOS por motivo
 * (la explicación va una vez por grupo) y por concepto (grupos plegables con total); los de
 * Dirección General al final. Debajo, las filas de los mismos archivos que quedaron en otros
 * periodos o que se leyeron y no se guardaron (paginadas desde el servidor). Pie fijo con
 * el total del filtro, «Mostrar más» y «Clasificar N».
 *
 * Props:
 * - control: estado y acciones de la lista (composable useMovementControl).
 * - canClassify: permiso para clasificar movimientos.
 * - canExport: permiso para exportar a Excel.
 * - lockReason: motivo por el que no se puede clasificar (p. ej. periodo aprobado).
 * - notSavedCount: filas leídas y no guardadas (conteo del filtro "No guardados").
 * - groups: datos de la vista «Agrupados» de pendientes (si falta, no se ofrece).
 *
 * Emits:
 * - open: abrir el detalle de un movimiento (huella).
 * - classify: clasificar los movimientos seleccionados (huellas).
 * - consider: marcar «Ya considerado» los seleccionados (huellas).
 * - reload / open-catalog: los reenvía desde la vista agrupada.
 */
import {
    CheckCircle2,
    CheckSquare,
    Download,
    FileX2,
    Layers,
    List,
    Loader2,
    Lock,
    Search,
    Tags,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import type { MovementControl } from '@/composables/estado-resultados/useMovementControl';
import type { RuleDraft } from './conceptCatalog';
import { FILTERS } from './movementControl';
import type { ControlledMovement } from './movementControl';
import MovementGroupList from './MovementGroupList.vue';
import {
    groupRows,
    layoutGroups,
    rowFromMovement,
    rowFromOutside,
} from './movementGroups';
import PendingGroupsView from './PendingGroupsView.vue';
import { mxn } from './types';
import type { TreatmentMeta } from './types';

const props = defineProps<{
    control: MovementControl;
    canClassify: boolean;
    canExport: boolean;
    lockReason?: string | null;
    /** Filas leídas y no guardadas en los archivos que aportan (file_control). */
    notSavedCount?: number;
    /** Vista «Agrupados» de pendientes (si falta `groups`, no se ofrece). */
    groups?: {
        movements: ControlledMovement[];
        url: (path: string) => string;
        scopeQuery: () => string;
        treatments: TreatmentMeta[];
        canCatalog: boolean;
        periodId: number;
        canProcess: boolean;
        process: () => Promise<void>;
    } | null;
}>();
const emit = defineEmits<{
    open: [fingerprint: string];
    classify: [fingerprints: string[]];
    consider: [fingerprints: string[]];
    reload: [];
    'open-catalog': [draft: Partial<RuleDraft>];
}>();

// ── Estado (todo antes de cualquier watch) ─────────────────────────────

/** Pendientes como lista o agrupados por concepto (servidor). */
const view = ref<'lista' | 'grupos'>('lista');
/**
 * Atajo reactivo al objeto de control recibido: si el padre cambia la prop
 * `control`, la lista sigue al objeto nuevo.
 */
const c = computed(() => props.control);
/** Huellas de los movimientos seleccionados. */
const selected = ref<Set<string>>(new Set());
/** Se puede clasificar: hay permiso y el periodo no está bloqueado. */
const canAct = computed(() => props.canClassify && !props.lockReason);
/** La vista agrupada del servidor sólo aplica al filtro «Pendientes». */
const showGroups = computed(
    () =>
        !!props.groups &&
        view.value === 'grupos' &&
        c.value.filter.value === 'pendiente',
);

/** Movimientos filtrados del periodo, agrupados por motivo y concepto. */
const localGroups = computed(() =>
    groupRows(
        c.value.filtered.value.map((m) => rowFromMovement(m, canAct.value)),
    ),
);
/** Lo que cabe en el límite actual («Mostrar más» lo amplía). */
const layout = computed(() =>
    layoutGroups(localGroups.value, c.value.limit.value),
);
/** Filas remotas (otros periodos / no guardadas), agrupadas igual. */
const remoteLayout = computed(() =>
    layoutGroups(
        groupRows(c.value.remote.value.map(rowFromOutside)),
        Number.MAX_SAFE_INTEGER,
    ),
);

/** Huellas seleccionables de lo que se ve. */
const visibleFps = computed(() =>
    layout.value.covered
        .filter((r) => r.selectable && r.fingerprint)
        .map((r) => r.fingerprint as string),
);
/** Todas las visibles seleccionables están marcadas. */
const allVisibleSelected = computed(
    () =>
        visibleFps.value.length > 0 &&
        visibleFps.value.every((f) => selected.value.has(f)),
);

/** Mostrar la sección de filas de otros periodos/no guardadas (cargando, con datos o con error). */
const showRemote = computed(
    () =>
        c.value.remoteVista.value !== null &&
        (c.value.remoteLoading.value ||
            c.value.remote.value.length > 0 ||
            c.value.remoteFiles.value.length > 0 ||
            !!c.value.remoteError.value),
);

/** Título del enfoque (partida, validación o archivo). */
const focusTitle = computed(
    () =>
        c.value.focus.value?.title ??
        'Movimientos de ' +
            (c.value.files.value.find((f) => f.id === c.value.uploadId.value)
                ?.file ?? 'un archivo'),
);

/**
 * Marca o desmarca varias huellas.
 * @param fps huellas.
 * @param on true = marcar.
 */
function toggleMany(fps: string[], on: boolean): void {
    const next = new Set(selected.value);
    fps.forEach((f) => (on ? next.add(f) : next.delete(f)));
    selected.value = next;
}

/** Marca todas las visibles seleccionables, o desmarca todo si ya estaban. */
function toggleAll(): void {
    selected.value = allVisibleSelected.value
        ? new Set()
        : new Set(visibleFps.value);
}

// Limpia la selección al cambiar el objeto de control, el filtro, la búsqueda,
// el foco o el archivo: así nunca se clasifican movimientos que ya no se ven.
watch(
    () => [
        c.value,
        c.value.filter.value,
        c.value.search.value,
        c.value.focus.value,
        c.value.uploadId.value,
    ],
    () => (selected.value = new Set()),
);

// Al montar se pide la primera página de filas fuera del periodo.
onMounted(() => void c.value.loadRemote(1));
</script>

<template>
    <div
        id="er-control-movimientos"
        class="flex min-h-0 flex-1 flex-col"
        aria-label="Control de movimientos"
    >
        <!-- Barra fija: enfoque, filtros, búsqueda y acciones -->
        <div class="shrink-0 space-y-3 border-b bg-muted/25 px-5 py-3 sm:px-6">
            <div
                v-if="c.focus.value || c.uploadId.value !== null"
                class="flex animate-in items-start gap-3 rounded-2xl border border-sky-200 bg-sky-50 px-3.5 py-2.5 text-sm text-sky-900 duration-200 fade-in-0 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-200"
            >
                <div class="min-w-0 flex-1">
                    <p class="font-bold">{{ focusTitle }}</p>
                    <p
                        v-if="c.focus.value?.note"
                        class="mt-0.5 line-clamp-2 text-xs"
                        :title="c.focus.value.note"
                    >
                        {{ c.focus.value.note }}
                    </p>
                    <p
                        v-if="c.focus.value?.redacted"
                        class="mt-0.5 flex items-center gap-1 text-xs"
                    >
                        <Lock class="size-3" />
                        {{ c.focus.value.redacted }} restringido(s) por permisos
                        (nómina / Dirección General) no se muestran.
                    </p>
                </div>
                <button
                    type="button"
                    class="inline-flex shrink-0 items-center gap-1 rounded-xl px-2.5 py-1.5 text-sm font-semibold hover:bg-sky-100 focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:outline-none dark:hover:bg-sky-900/40"
                    @click="c.clearFocus()"
                >
                    <X class="size-4" /> Ver todo
                </button>
            </div>

            <!-- Filtros por estado con su conteo -->
            <div
                class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-0.5 [scrollbar-width:thin]"
                role="tablist"
                aria-label="Filtrar por estado"
            >
                <button
                    v-for="f in FILTERS"
                    :key="f.key"
                    type="button"
                    role="tab"
                    :aria-selected="c.filter.value === f.key"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-sm font-semibold transition focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:outline-none"
                    :class="
                        c.filter.value === f.key
                            ? 'border-emerald-700 bg-emerald-700 text-white shadow-sm dark:border-emerald-500 dark:bg-emerald-600'
                            : 'bg-background hover:bg-muted'
                    "
                    @click="c.setFilter(f.key)"
                >
                    {{ f.label }}
                    <span
                        class="rounded-full px-1.5 text-xs tabular-nums"
                        :class="
                            c.filter.value === f.key
                                ? 'bg-white/20'
                                : 'bg-muted text-muted-foreground'
                        "
                        >{{
                            f.key === 'no_guardado'
                                ? (notSavedCount ?? 0).toLocaleString('es-MX')
                                : (
                                      c.counts.value[f.key]?.count ?? 0
                                  ).toLocaleString('es-MX')
                        }}</span
                    >
                </button>
            </div>

            <!-- Búsqueda, archivo, vista y acciones -->
            <div class="flex flex-wrap items-center gap-2">
                <label class="relative min-w-52 flex-1">
                    <span class="sr-only">Buscar movimientos</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="c.search.value"
                        type="search"
                        placeholder="Buscar concepto, archivo, fila, folio…"
                        class="h-10 w-full rounded-xl border bg-background pr-3 pl-9 text-sm shadow-xs transition outline-none focus-visible:border-emerald-500 focus-visible:ring-2 focus-visible:ring-emerald-500/30"
                    />
                </label>
                <select
                    v-model="c.uploadId.value"
                    class="h-10 max-w-64 rounded-xl border bg-background px-3 text-sm shadow-xs outline-none focus-visible:ring-2 focus-visible:ring-emerald-500/30"
                    aria-label="Filtrar por archivo"
                >
                    <option :value="null">Todos los archivos</option>
                    <option
                        v-for="f in c.files.value"
                        :key="f.id"
                        :value="f.id"
                    >
                        {{ f.file }}
                    </option>
                </select>
                <div
                    v-if="groups && c.filter.value === 'pendiente'"
                    class="inline-flex h-10 items-center rounded-xl border bg-background p-0.5 text-sm font-semibold"
                    role="tablist"
                    aria-label="Vista de pendientes"
                >
                    <button
                        v-for="o in [
                            { k: 'lista', l: 'Lista', i: List },
                            { k: 'grupos', l: 'Agrupados', i: Layers },
                        ] as const"
                        :key="o.k"
                        type="button"
                        role="tab"
                        :aria-selected="view === o.k"
                        class="inline-flex h-full items-center gap-1.5 rounded-lg px-3 transition"
                        :class="
                            view === o.k
                                ? 'bg-foreground text-background'
                                : 'hover:bg-muted'
                        "
                        @click="view = o.k"
                    >
                        <component :is="o.i" class="size-4" /> {{ o.l }}
                    </button>
                </div>
                <button
                    v-if="canAct && !showGroups && visibleFps.length"
                    type="button"
                    class="inline-flex h-10 items-center gap-1.5 rounded-xl border bg-background px-3 text-sm font-semibold transition hover:bg-muted"
                    :aria-pressed="allVisibleSelected"
                    @click="toggleAll"
                >
                    <CheckSquare class="size-4" />
                    {{
                        allVisibleSelected
                            ? 'Quitar selección'
                            : 'Marcar visibles'
                    }}
                </button>
                <button
                    v-if="canExport"
                    type="button"
                    class="inline-flex h-10 items-center gap-1.5 rounded-xl border bg-background px-3 text-sm font-semibold transition hover:bg-muted"
                    @click="c.exportExcel()"
                >
                    <Download class="size-4" /> Exportar a Excel
                </button>
            </div>
        </div>

        <!-- Cuerpo con desplazamiento interno -->
        <div
            class="min-h-0 flex-1 overflow-y-auto overscroll-contain scroll-smooth"
        >
            <!-- Pendientes agrupados por concepto (servidor) -->
            <PendingGroupsView
                v-if="showGroups && groups"
                :movements="groups.movements"
                :url="groups.url"
                :scope-query="groups.scopeQuery"
                :treatments="groups.treatments"
                :can-classify="canClassify"
                :can-catalog="groups.canCatalog"
                :lock-reason="lockReason"
                :period-id="groups.periodId"
                :can-process="groups.canProcess"
                :process="groups.process"
                @open="(fp) => emit('open', fp)"
                @reload="emit('reload')"
                @open-catalog="(d) => emit('open-catalog', d)"
            />

            <!-- Movimientos de este periodo, agrupados -->
            <div
                v-else-if="c.filter.value !== 'no_guardado'"
                class="px-4 py-4 sm:px-6"
            >
                <MovementGroupList
                    v-if="layout.sections.length"
                    :sections="layout.sections"
                    :selectable="canAct"
                    :selected="selected"
                    @toggle="toggleMany"
                    @open="(fp) => emit('open', fp)"
                />
                <p
                    v-else
                    class="rounded-2xl border border-dashed p-8 text-center text-[15px] text-muted-foreground"
                >
                    No hay movimientos de este periodo con este filtro.
                </p>
                <button
                    v-if="layout.remaining > 0"
                    type="button"
                    class="mt-4 w-full rounded-2xl border border-dashed py-3 text-sm font-semibold text-muted-foreground transition hover:border-emerald-400 hover:text-foreground"
                    @click="c.limit.value += 200"
                >
                    Mostrar más ({{ layout.remaining.toLocaleString('es-MX') }}
                    restantes)
                </button>
            </div>

            <!-- Filas de los mismos archivos que NO están en este periodo -->
            <div v-if="showRemote" class="border-t px-4 py-4 sm:px-6">
                <div class="mb-3 flex items-center gap-2">
                    <span
                        class="grid size-8 place-items-center rounded-xl bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200"
                    >
                        <FileX2 class="size-4" />
                    </span>
                    <h4 class="text-base font-bold">
                        {{
                            c.remoteVista.value === 'no_guardado'
                                ? 'Filas leídas del archivo y no guardadas'
                                : 'Filas de estos archivos en otros periodos'
                        }}
                        <span
                            v-if="c.remoteVista.value === 'fuera'"
                            class="font-normal text-muted-foreground tabular-nums"
                            >({{
                                c.remoteTotal.value.toLocaleString('es-MX')
                            }})</span
                        >
                    </h4>
                    <Loader2
                        v-if="c.remoteLoading.value"
                        class="size-4 animate-spin text-muted-foreground"
                    />
                </div>
                <p
                    v-if="c.remoteError.value"
                    class="mb-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-800 dark:bg-rose-950/30 dark:text-rose-300"
                    role="alert"
                >
                    {{ c.remoteError.value }}
                </p>
                <!-- Resumen por archivo -->
                <ul
                    v-if="c.remoteFiles.value.length"
                    class="mb-3 grid gap-2 sm:grid-cols-2"
                >
                    <li
                        v-for="f in c.remoteFiles.value"
                        :key="f.upload_id"
                        class="rounded-xl border bg-muted/40 px-3 py-2 text-sm"
                    >
                        <p class="truncate font-semibold" :title="f.file">
                            {{ f.file }}
                        </p>
                        <p class="text-muted-foreground tabular-nums">
                            {{ f.count }} fila(s) · {{ mxn(f.amount) }}
                        </p>
                        <p
                            v-if="f.message"
                            class="mt-0.5 line-clamp-2 text-xs text-muted-foreground"
                            :title="f.message"
                        >
                            {{ f.message }}
                        </p>
                    </li>
                </ul>
                <MovementGroupList
                    v-if="remoteLayout.sections.length"
                    :sections="remoteLayout.sections"
                />
                <p
                    v-else-if="!c.remoteLoading.value && !c.remoteError.value"
                    class="text-sm text-muted-foreground"
                >
                    Sin filas con este filtro.
                </p>
                <button
                    v-if="c.remotePage.value < c.remoteLastPage.value"
                    type="button"
                    class="mt-3 w-full rounded-2xl border border-dashed py-2.5 text-sm font-semibold text-muted-foreground transition hover:text-foreground disabled:opacity-50"
                    :disabled="c.remoteLoading.value"
                    @click="c.loadRemote(c.remotePage.value + 1)"
                >
                    Cargar más
                </button>
            </div>
        </div>

        <!-- Pie fijo: total del filtro y acción -->
        <div
            v-if="!showGroups && c.filter.value !== 'no_guardado'"
            class="flex shrink-0 flex-wrap items-center gap-3 border-t bg-muted/30 px-5 py-3 sm:px-6"
        >
            <p
                class="min-w-0 flex-1 text-sm text-muted-foreground tabular-nums"
            >
                <b class="text-foreground">{{
                    c.filtered.value.length.toLocaleString('es-MX')
                }}</b>
                movimiento(s) ·
                <b class="text-foreground">{{ mxn(c.filteredAmount.value) }}</b>
                bruto
            </p>
            <p
                v-if="lockReason && canClassify"
                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                :title="lockReason"
            >
                <Lock class="size-3.5" /> Solo lectura
            </p>
            <button
                v-if="canAct"
                type="button"
                class="inline-flex h-10 items-center gap-1.5 rounded-xl border bg-background px-4 text-sm font-bold shadow-sm transition hover:border-teal-400 hover:bg-teal-50 focus-visible:ring-2 focus-visible:ring-teal-500 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-40 dark:hover:bg-teal-950/30"
                :disabled="!selected.size"
                title="Ya tomados en cuenta (Radiografía, otro periodo u otra fuente)"
                @click="emit('consider', [...selected])"
            >
                <CheckCircle2 class="size-4 text-teal-600" />
                Ya considerado
            </button>
            <button
                v-if="canAct"
                type="button"
                class="inline-flex h-10 items-center gap-1.5 rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white shadow-sm transition hover:bg-emerald-800 focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="!selected.size"
                @click="emit('classify', [...selected])"
            >
                <Tags class="size-4" />
                Clasificar{{ selected.size ? ` ${selected.size}` : '' }}
            </button>
        </div>
    </div>
</template>
