<script setup lang="ts">
/**
 * MovementControlList — lista de control total de movimientos (pestaña Validación).
 *
 * La usa ValidationPanel. Muestra filtros por estado con conteos, búsqueda,
 * filtro por archivo, la tabla de movimientos del periodo (con selección
 * múltiple para clasificar) y, aparte, las filas de los mismos archivos que
 * quedaron en otros periodos o que se leyeron y no se guardaron (paginadas
 * desde el servidor). Permite exportar a Excel.
 *
 * Props:
 * - control: estado y acciones de la lista (composable useMovementControl).
 * - canClassify: permiso para clasificar movimientos.
 * - canExport: permiso para exportar a Excel.
 * - lockReason: motivo por el que no se puede clasificar (p. ej. periodo aprobado).
 * - notSavedCount: filas leídas y no guardadas (conteo del filtro "No guardados").
 *
 * Emits:
 * - open: abrir el detalle de un movimiento (huella).
 * - classify: clasificar los movimientos seleccionados (huellas).
 */
import {
    AlertTriangle,
    Download,
    Layers,
    List,
    FileX2,
    Loader2,
    Lock,
    Search,
    Tags,
    X,
} from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import type { MovementControl } from '@/composables/estado-resultados/useMovementControl';
import type { RuleDraft } from './conceptCatalog';
import { FILTERS, STATUS_META, controlOf } from './movementControl';
import type { ControlledMovement } from './movementControl';
import PendingGroupsView from './PendingGroupsView.vue';
import { mxn, num } from './types';
import type { TreatmentMeta } from './types';

/**
 * Lista de CONTROL TOTAL: cada movimiento con su estado (completado, pendiente, repetido,
 * fuera de este periodo, no guardado), a qué línea fue y por qué, y las señales de concepto
 * a revisar. Desde aquí se abre el detalle y se clasifica.
 */
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
    reload: [];
    'open-catalog': [draft: Partial<RuleDraft>];
}>();

/** Pendientes como lista o agrupados por concepto. */
const view = ref<'lista' | 'grupos'>('lista');
/** La vista agrupada sólo aplica al filtro «Pendientes». */
const showGroups = computed(
    () =>
        !!props.groups &&
        view.value === 'grupos' &&
        props.control.filter.value === 'pendiente',
);

/**
 * Atajo reactivo al objeto de control recibido: si el padre cambia la prop
 * `control`, la lista sigue al objeto nuevo (en la plantilla se desenvuelve solo).
 */
const c = computed(() => props.control);
/** Huellas de los movimientos seleccionados. */
const selected = ref<Set<string>>(new Set());
/** Se puede clasificar: hay permiso y el periodo no está bloqueado. */
const canAct = computed(() => props.canClassify && !props.lockReason);

/** Un movimiento se puede seleccionar si no está restringido, no es NOI de corporativo y tiene huella. */
const selectable = (m: ControlledMovement) =>
    canAct.value && !m.redacted && m.source !== 'noi_corp' && !!m.fingerprint;

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

/**
 * Marca o desmarca un movimiento.
 * @param fp huella del movimiento.
 */
function toggle(fp: string): void {
    const next = new Set(selected.value);

    if (next.has(fp)) {
        next.delete(fp);
    } else {
        next.add(fp);
    }

    selected.value = next;
}

/** Todos los movimientos visibles seleccionables están marcados. */
const allVisibleSelected = computed(() => {
    const list = c.value.visible.value.filter(selectable);

    return (
        list.length > 0 && list.every((m) => selected.value.has(m.fingerprint))
    );
});

/** Marca todos los visibles seleccionables, o desmarca todo si ya estaban marcados. */
function toggleAll(): void {
    const list = c.value.visible.value
        .filter(selectable)
        .map((m) => m.fingerprint);
    selected.value = allVisibleSelected.value ? new Set() : new Set(list);
}

/** Color del texto de cada señal según su severidad. */
const SEVERITY_CLS: Record<string, string> = {
    alta: 'text-rose-700 dark:text-rose-400',
    media: 'text-amber-700 dark:text-amber-400',
    baja: 'text-muted-foreground',
};

/** Mostrar la sección de filas de otros periodos/no guardadas (cargando, con datos o con error). */
const showRemote = computed(
    () =>
        c.value.remoteVista.value !== null &&
        (c.value.remoteLoading.value ||
            c.value.remote.value.length > 0 ||
            c.value.remoteFiles.value.length > 0 ||
            !!c.value.remoteError.value),
);

// Al montar se pide la primera página de filas fuera del periodo.
onMounted(() => void c.value.loadRemote(1));
</script>

<template>
    <section
        id="er-control-movimientos"
        class="rounded-2xl border bg-card shadow-soft-xs"
        aria-label="Control de movimientos"
    >
        <header class="space-y-3 border-b px-5 py-4">
            <!-- Título y exportación -->
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div class="min-w-0">
                    <h3 class="text-base font-extrabold">
                        Control de movimientos
                    </h3>
                    <p class="text-xs text-muted-foreground">
                        Abre cualquier partida para ver qué movimientos la
                        componen, en qué estado están y por qué.
                    </p>
                </div>
                <button
                    v-if="canExport"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-xl border px-3 py-1.5 text-xs font-semibold transition hover:bg-muted"
                    @click="c.exportExcel()"
                >
                    <Download class="size-3.5" /> Exportar a Excel
                </button>
            </div>

            <!-- Partida o archivo enfocado, con "Ver todo" -->
            <div
                v-if="c.focus.value || c.uploadId.value !== null"
                class="flex items-start gap-2 rounded-xl bg-sky-50 p-2.5 text-xs text-sky-900 dark:bg-sky-950/30 dark:text-sky-200"
            >
                <div class="min-w-0 flex-1">
                    <p class="font-bold">
                        {{
                            c.focus.value?.title ??
                            'Movimientos de ' +
                                (c.files.value.find(
                                    (f) => f.id === c.uploadId.value,
                                )?.file ?? 'un archivo')
                        }}
                    </p>
                    <p v-if="c.focus.value?.note" class="mt-0.5">
                        {{ c.focus.value.note }}
                    </p>
                    <p
                        v-if="c.focus.value?.redacted"
                        class="mt-0.5 flex items-center gap-1"
                    >
                        <Lock class="size-3" />
                        {{ c.focus.value.redacted }} movimiento(s) restringidos
                        por permisos (nómina / Dirección General) no se
                        muestran.
                    </p>
                </div>
                <button
                    type="button"
                    class="inline-flex shrink-0 items-center gap-1 rounded-lg px-2 py-1 font-semibold hover:bg-sky-100 dark:hover:bg-sky-900/40"
                    @click="c.clearFocus()"
                >
                    <X class="size-3.5" /> Ver todo
                </button>
            </div>

            <!-- Filtros por estado con su conteo -->
            <div class="flex flex-wrap gap-1.5" role="tablist">
                <button
                    v-for="f in FILTERS"
                    :key="f.key"
                    type="button"
                    role="tab"
                    :aria-selected="c.filter.value === f.key"
                    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition"
                    :class="
                        c.filter.value === f.key
                            ? 'border-foreground bg-foreground text-background'
                            : 'hover:bg-muted'
                    "
                    @click="c.setFilter(f.key)"
                >
                    {{ f.label }}
                    <span class="tabular-nums opacity-75">{{
                        f.key === 'no_guardado'
                            ? (notSavedCount ?? 0).toLocaleString('es-MX')
                            : (
                                  c.counts.value[f.key]?.count ?? 0
                              ).toLocaleString('es-MX')
                    }}</span>
                </button>
            </div>

            <!-- Pendientes: lista o agrupados por concepto -->
            <div
                v-if="groups && c.filter.value === 'pendiente'"
                class="inline-flex rounded-xl border p-0.5 text-xs font-semibold"
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
                    class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1 transition"
                    :class="
                        view === o.k
                            ? 'bg-foreground text-background'
                            : 'hover:bg-muted'
                    "
                    @click="view = o.k"
                >
                    <component :is="o.i" class="size-3.5" /> {{ o.l }}
                </button>
            </div>

            <!-- Búsqueda, archivo y clasificar seleccionados -->
            <div class="flex flex-wrap items-center gap-2">
                <label class="relative min-w-48 flex-1">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-2.5 size-3.5 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="c.search.value"
                        type="search"
                        placeholder="Buscar concepto, archivo, fila, folio…"
                        class="w-full rounded-xl border bg-background py-1.5 pr-3 pl-8 text-xs"
                    />
                </label>
                <select
                    v-model="c.uploadId.value"
                    class="rounded-xl border bg-background px-2 py-1.5 text-xs"
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
                <button
                    v-if="canAct && selected.size"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-foreground px-3 py-1.5 text-xs font-semibold text-background"
                    @click="emit('classify', [...selected])"
                >
                    <Tags class="size-3.5" /> Clasificar {{ selected.size }}
                </button>
            </div>
        </header>

        <!-- Pendientes agrupados por concepto -->
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

        <!-- Movimientos de este periodo (dataset) -->
        <div
            v-else-if="c.filter.value !== 'no_guardado'"
            class="max-h-[560px] overflow-auto"
        >
            <table class="w-full text-xs">
                <thead
                    class="sticky top-0 z-10 bg-card text-left text-muted-foreground shadow-[0_1px_0_var(--border)]"
                >
                    <tr>
                        <th v-if="canAct" class="w-8 px-3 py-2">
                            <input
                                type="checkbox"
                                :checked="allVisibleSelected"
                                aria-label="Seleccionar visibles"
                                @change="toggleAll"
                            />
                        </th>
                        <th class="px-3 py-2 font-semibold">Fecha</th>
                        <th class="px-3 py-2 font-semibold">Archivo · fila</th>
                        <th class="px-3 py-2 font-semibold">Concepto</th>
                        <th class="px-3 py-2 text-right font-semibold">
                            Importe
                        </th>
                        <th class="px-3 py-2 font-semibold">
                            Estado y destino
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="m in c.visible.value"
                        :key="
                            m.fingerprint + (m.upload_id ?? '') + m.source_row
                        "
                        class="cursor-pointer align-top transition hover:bg-muted/50"
                        @click="!m.redacted && emit('open', m.fingerprint)"
                    >
                        <td v-if="canAct" class="px-3 py-2" @click.stop>
                            <input
                                v-if="selectable(m)"
                                type="checkbox"
                                :checked="selected.has(m.fingerprint)"
                                :aria-label="'Seleccionar ' + (m.concept ?? '')"
                                @change="toggle(m.fingerprint)"
                            />
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap tabular-nums">
                            {{ m.date ?? '—' }}
                        </td>
                        <td class="px-3 py-2">
                            <span
                                class="block max-w-44 truncate"
                                :title="m.file ?? ''"
                                >{{
                                    m.source === 'erp'
                                        ? 'ERP de gastos'
                                        : m.source === 'noi_corp'
                                          ? 'NOI de corporativo'
                                          : (m.file ?? '—')
                                }}</span
                            >
                            <span class="text-muted-foreground"
                                >{{ m.account_label ?? m.account_slot }} · fila
                                {{ m.source_row }}</span
                            >
                        </td>
                        <td class="max-w-72 px-3 py-2">
                            <span class="line-clamp-2">{{
                                m.concept ?? '—'
                            }}</span>
                        </td>
                        <td
                            class="px-3 py-2 text-right whitespace-nowrap tabular-nums"
                        >
                            <span v-if="num(m.cargo)" class="font-semibold"
                                >{{ mxn(m.cargo) }}
                                <span class="text-muted-foreground"
                                    >cargo</span
                                ></span
                            >
                            <span
                                v-if="num(m.abono)"
                                class="block font-semibold"
                                >{{ mxn(m.abono) }}
                                <span class="text-muted-foreground"
                                    >abono</span
                                ></span
                            >
                        </td>
                        <td class="min-w-64 px-3 py-2">
                            <span
                                class="inline-flex rounded-full px-2 py-px text-[10.5px] font-bold"
                                :class="STATUS_META[controlOf(m).status].cls"
                                >{{ controlOf(m).label }}</span
                            >
                            <span
                                class="mt-1 block text-[11px] leading-snug text-muted-foreground"
                                >{{ controlOf(m).explain }}</span
                            >
                            <span
                                v-for="fl in controlOf(m).flags"
                                :key="fl.key"
                                class="mt-1 flex items-start gap-1 text-[11px] leading-snug font-semibold"
                                :class="SEVERITY_CLS[fl.severity] ?? ''"
                            >
                                <AlertTriangle class="mt-px size-3 shrink-0" />
                                {{ fl.label }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p
                v-if="!c.filtered.value.length"
                class="p-6 text-center text-sm text-muted-foreground"
            >
                No hay movimientos de este periodo con este filtro.
            </p>
            <!-- Totales del filtro y "Mostrar más" -->
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-t px-5 py-2.5 text-xs text-muted-foreground"
            >
                <span class="tabular-nums"
                    >{{
                        c.filtered.value.length.toLocaleString('es-MX')
                    }}
                    movimiento(s) · {{ mxn(c.filteredAmount.value) }}</span
                >
                <button
                    v-if="c.filtered.value.length > c.visible.value.length"
                    type="button"
                    class="rounded-lg px-2 py-1 font-semibold text-foreground hover:bg-muted"
                    @click="c.limit.value += 200"
                >
                    Mostrar más ({{
                        c.filtered.value.length - c.visible.value.length
                    }}
                    restantes)
                </button>
            </div>
        </div>

        <!-- Filas de los mismos archivos que NO están en este periodo -->
        <div v-if="showRemote" class="border-t">
            <div class="flex items-center gap-2 px-5 pt-3">
                <FileX2 class="size-4 text-muted-foreground" />
                <h4 class="text-sm font-bold">
                    {{
                        c.remoteVista.value === 'no_guardado'
                            ? 'Filas leídas del archivo y no guardadas'
                            : 'Filas de estos archivos en otros periodos'
                    }}
                    <span
                        v-if="c.remoteVista.value === 'fuera'"
                        class="font-normal text-muted-foreground"
                        >({{
                            c.remoteTotal.value.toLocaleString('es-MX')
                        }})</span
                    >
                </h4>
                <Loader2
                    v-if="c.remoteLoading.value"
                    class="size-3.5 animate-spin text-muted-foreground"
                />
            </div>
            <p
                v-if="c.remoteError.value"
                class="mx-5 mt-2 rounded-lg bg-rose-50 p-2 text-xs text-rose-800 dark:bg-rose-950/30 dark:text-rose-300"
            >
                {{ c.remoteError.value }}
            </p>
            <!-- Resumen por archivo -->
            <ul
                v-if="c.remoteFiles.value.length"
                class="mx-5 mt-2 space-y-1 text-xs"
            >
                <li
                    v-for="f in c.remoteFiles.value"
                    :key="f.upload_id"
                    class="rounded-lg bg-muted/50 p-2"
                >
                    <b>{{ f.file }}</b> · {{ f.count }} fila(s) ·
                    {{ mxn(f.amount) }}
                    <span
                        v-if="f.message"
                        class="block text-muted-foreground"
                        >{{ f.message }}</span
                    >
                </li>
            </ul>
            <!-- Tabla de filas fuera del periodo o no guardadas -->
            <div class="max-h-[420px] overflow-auto">
                <table class="w-full text-xs">
                    <tbody class="divide-y">
                        <tr
                            v-for="r in c.remote.value"
                            :key="
                                (r.id ?? 'ng') +
                                '-' +
                                r.upload_id +
                                '-' +
                                r.source_row
                            "
                            class="align-top"
                        >
                            <td
                                class="px-5 py-2 whitespace-nowrap tabular-nums"
                            >
                                {{ r.date ?? '—' }}
                            </td>
                            <td class="px-3 py-2">
                                <span
                                    class="block max-w-44 truncate"
                                    :title="r.file ?? ''"
                                    >{{ r.file ?? '—' }}</span
                                >
                                <span class="text-muted-foreground"
                                    >{{ r.account_label ?? r.account_slot }} ·
                                    fila {{ r.source_row }}</span
                                >
                            </td>
                            <td class="max-w-72 px-3 py-2">
                                <span class="line-clamp-2">{{
                                    r.concept ?? '—'
                                }}</span>
                            </td>
                            <td
                                class="px-3 py-2 text-right font-semibold whitespace-nowrap tabular-nums"
                            >
                                {{ mxn(num(r.cargo) ? r.cargo : r.abono) }}
                                <span
                                    class="font-normal text-muted-foreground"
                                    >{{
                                        num(r.cargo) ? 'cargo' : 'abono'
                                    }}</span
                                >
                            </td>
                            <td class="min-w-64 px-3 py-2">
                                <span
                                    class="inline-flex rounded-full px-2 py-px text-[10.5px] font-bold"
                                    :class="STATUS_META[r.status].cls"
                                    >{{ r.status_label }}</span
                                >
                                <span
                                    class="mt-1 block text-[11px] leading-snug text-muted-foreground"
                                    >{{ r.explain }}</span
                                >
                                <span
                                    v-if="
                                        r.treatment_label &&
                                        r.part === 'otro_periodo'
                                    "
                                    class="mt-0.5 block text-[11px] text-muted-foreground"
                                    >Allá: {{ r.treatment_label }}</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p
                v-if="
                    !c.remoteLoading.value &&
                    !c.remote.value.length &&
                    !c.remoteError.value
                "
                class="px-5 py-4 text-xs text-muted-foreground"
            >
                Sin filas con este filtro.
            </p>
            <!-- Paginación -->
            <div
                v-if="c.remotePage.value < c.remoteLastPage.value"
                class="border-t px-5 py-2.5 text-right"
            >
                <button
                    type="button"
                    class="rounded-lg px-2 py-1 text-xs font-semibold hover:bg-muted"
                    :disabled="c.remoteLoading.value"
                    @click="c.loadRemote(c.remotePage.value + 1)"
                >
                    Cargar más
                </button>
            </div>
        </div>
    </section>
</template>
