<script setup lang="ts">
/**
 * MovementGroupList — lista agrupada de la pestaña Validación (Control de movimientos).
 *
 * La usa MovementControlList (movimientos del periodo y filas de otros periodos). Cada
 * grupo por MOTIVO tiene un encabezado plegable con el chip de estado, el motivo, su
 * explicación (UNA sola vez), el conteo y la suma de cargos/abonos. Dentro, los conceptos
 * repetidos se juntan en un grupo plegable con su total y casilla para marcar todos.
 *
 * Props:
 * - sections: grupos ya recortados al límite (layoutGroups).
 * - selectable: mostrar casillas de selección.
 * - selected: huellas marcadas.
 *
 * Emits:
 * - toggle(fps, on): marcar/desmarcar varias huellas.
 * - open(fp): abrir el detalle de un movimiento.
 */
import { ChevronDown, Crown, Layers } from 'lucide-vue-next';
import { ref } from 'vue';
import { STATUS_META } from './movementControl';
import type { ControlRowView, LayoutSection } from './movementGroups';
import MovementRow from './MovementRow.vue';
import { mxn } from './types';

const props = withDefaults(
    defineProps<{
        sections: LayoutSection[];
        selectable?: boolean;
        selected?: Set<string>;
    }>(),
    { selectable: false, selected: () => new Set<string>() },
);
const emit = defineEmits<{
    toggle: [fps: string[], on: boolean];
    open: [fingerprint: string];
}>();

/** Grupos por motivo plegados (por clave). */
const collapsed = ref<Set<string>>(new Set());
/** Grupos de concepto desplegados (clave de motivo + concepto). */
const expanded = ref<Set<string>>(new Set());

/**
 * Alterna una clave en un conjunto reactivo.
 * @param which conjunto a cambiar (plegados o desplegados).
 * @param key clave.
 */
function flip(which: 'collapsed' | 'expanded', key: string): void {
    const set = which === 'collapsed' ? collapsed : expanded;
    const next = new Set(set.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    set.value = next;
}

/** Huellas seleccionables de unas filas. */
const fpsOf = (rows: ControlRowView[]) =>
    rows
        .filter((r) => r.selectable && r.fingerprint)
        .map((r) => r.fingerprint as string);

/** Estado de la casilla de un grupo de filas: todas, algunas o ninguna marcadas. */
function stateOf(rows: ControlRowView[]): 'all' | 'some' | 'none' {
    const fps = fpsOf(rows);
    const on = fps.filter((f) => props.selected.has(f)).length;

    return on === 0 ? 'none' : on === fps.length ? 'all' : 'some';
}

/** Marca o desmarca todas las filas seleccionables. */
function toggleRows(rows: ControlRowView[]): void {
    emit('toggle', fpsOf(rows), stateOf(rows) !== 'all');
}

/** Rango de fechas de un grupo de filas. */
function dateRange(rows: ControlRowView[]): string {
    const dates = rows
        .map((r) => r.date)
        .filter((d): d is string => !!d)
        .sort();

    if (!dates.length) {
        return '—';
    }

    return dates[0] === dates[dates.length - 1]
        ? dates[0]
        : `${dates[0]} – ${dates[dates.length - 1]}`;
}
</script>

<template>
    <div class="space-y-4">
        <section
            v-for="s in sections"
            :key="s.group.key"
            class="overflow-hidden rounded-2xl border bg-card shadow-soft-xs"
            :class="
                s.group.dg
                    ? 'border-violet-200 dark:border-violet-900'
                    : s.group.status === 'pendiente'
                      ? 'border-amber-200 dark:border-amber-900'
                      : ''
            "
        >
            <!-- Encabezado del motivo: se explica UNA vez -->
            <header
                class="flex items-start gap-3 px-4 py-3"
                :class="
                    s.group.dg
                        ? 'bg-violet-50/70 dark:bg-violet-950/20'
                        : s.group.status === 'pendiente'
                          ? 'bg-amber-50/60 dark:bg-amber-950/15'
                          : 'bg-muted/40'
                "
            >
                <button
                    type="button"
                    class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-lg transition hover:bg-background focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:outline-none"
                    :aria-expanded="!collapsed.has(s.group.key)"
                    :aria-label="
                        (collapsed.has(s.group.key)
                            ? 'Desplegar '
                            : 'Plegar ') + s.group.title
                    "
                    @click="flip('collapsed', s.group.key)"
                >
                    <ChevronDown
                        class="size-4 transition-transform duration-200"
                        :class="collapsed.has(s.group.key) ? '-rotate-90' : ''"
                    />
                </button>
                <div class="min-w-0 flex-1">
                    <p class="flex flex-wrap items-center gap-2">
                        <span
                            v-if="s.group.dg"
                            class="inline-flex items-center gap-1 rounded-full bg-violet-600 px-2.5 py-0.5 text-xs font-bold text-white"
                        >
                            <Crown class="size-3.5" /> DG y socios
                        </span>
                        <span
                            v-else-if="s.group.status"
                            class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-bold"
                            :class="STATUS_META[s.group.status].cls"
                        >
                            <span
                                class="size-1.5 rounded-full"
                                :class="STATUS_META[s.group.status].dot"
                            />
                            {{ STATUS_META[s.group.status].label }}
                        </span>
                        <span
                            class="min-w-0 text-[15px] leading-snug font-bold"
                            :title="s.group.title"
                            >{{ s.group.title }}</span
                        >
                    </p>
                    <p
                        v-if="s.group.explain"
                        class="mt-1 line-clamp-2 text-sm text-muted-foreground"
                        :title="s.group.explain"
                    >
                        {{ s.group.explain }}
                    </p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="text-sm font-bold tabular-nums">
                        {{ s.group.rows.length.toLocaleString('es-MX') }}
                        <span class="font-normal text-muted-foreground"
                            >mov.</span
                        >
                    </p>
                    <p
                        v-if="s.group.cargos"
                        class="text-sm font-semibold text-rose-700 tabular-nums dark:text-rose-400"
                    >
                        {{ mxn(s.group.cargos) }}
                    </p>
                    <p
                        v-if="s.group.abonos"
                        class="text-sm font-semibold text-emerald-700 tabular-nums dark:text-emerald-400"
                    >
                        {{ mxn(s.group.abonos) }}
                    </p>
                </div>
                <input
                    v-if="selectable && fpsOf(s.group.rows).length"
                    type="checkbox"
                    class="mt-1.5 size-4 shrink-0 cursor-pointer accent-emerald-600"
                    :checked="stateOf(s.group.rows) === 'all'"
                    :indeterminate="stateOf(s.group.rows) === 'some'"
                    :aria-label="'Seleccionar el grupo ' + s.group.title"
                    @change="toggleRows(s.group.rows)"
                />
            </header>

            <!-- Filas y conceptos agrupados -->
            <div
                v-if="!collapsed.has(s.group.key)"
                class="animate-in divide-y divide-border/70 duration-200 fade-in-0 slide-in-from-top-1 motion-reduce:animate-none"
            >
                <template
                    v-for="e in s.entries"
                    :key="e.kind === 'row' ? e.row.id : e.bundle.key"
                >
                    <MovementRow
                        v-if="e.kind === 'row'"
                        :row="e.row"
                        :selectable="selectable"
                        :checked="
                            !!e.row.fingerprint &&
                            selected.has(e.row.fingerprint)
                        "
                        :group-explain="s.group.explain"
                        @toggle="
                            emit(
                                'toggle',
                                [e.row.fingerprint as string],
                                !selected.has(e.row.fingerprint as string),
                            )
                        "
                        @open="(fp) => emit('open', fp)"
                    />
                    <div v-else>
                        <!-- Grupo plegable del mismo concepto -->
                        <div
                            class="grid grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 px-4 py-2.5"
                        >
                            <span class="flex w-5 justify-center">
                                <input
                                    v-if="
                                        selectable &&
                                        fpsOf(e.bundle.rows).length
                                    "
                                    type="checkbox"
                                    class="size-4 cursor-pointer accent-emerald-600"
                                    :checked="stateOf(e.bundle.rows) === 'all'"
                                    :indeterminate="
                                        stateOf(e.bundle.rows) === 'some'
                                    "
                                    :aria-label="
                                        'Seleccionar los ' +
                                        e.bundle.rows.length +
                                        ' de ' +
                                        e.bundle.label
                                    "
                                    @change="toggleRows(e.bundle.rows)"
                                />
                            </span>
                            <button
                                type="button"
                                class="flex min-w-0 items-center gap-3 rounded-xl text-left focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:outline-none"
                                :aria-expanded="
                                    expanded.has(s.group.key + e.bundle.key)
                                "
                                @click="
                                    flip('expanded', s.group.key + e.bundle.key)
                                "
                            >
                                <span
                                    class="grid size-9 shrink-0 place-items-center rounded-xl text-sm font-extrabold tabular-nums"
                                    :class="
                                        e.bundle.direction === 'abono'
                                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                            : 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300'
                                    "
                                    >{{ e.bundle.rows.length }}</span
                                >
                                <span class="min-w-0">
                                    <span
                                        class="flex items-center gap-1.5 text-[15px] font-semibold"
                                    >
                                        <Layers
                                            class="size-4 shrink-0 text-muted-foreground"
                                        />
                                        <span
                                            class="truncate"
                                            :title="e.bundle.label"
                                            >{{ e.bundle.label }}</span
                                        >
                                    </span>
                                    <span
                                        class="block truncate text-xs text-muted-foreground tabular-nums"
                                    >
                                        {{ e.bundle.rows.length }} movimientos ·
                                        {{ dateRange(e.bundle.rows) }}
                                        <template v-if="e.bundle.variants > 1">
                                            ·
                                            {{ e.bundle.variants }}
                                            variantes</template
                                        >
                                    </span>
                                </span>
                                <ChevronDown
                                    class="size-4 shrink-0 text-muted-foreground transition-transform duration-200"
                                    :class="
                                        expanded.has(s.group.key + e.bundle.key)
                                            ? 'rotate-180'
                                            : ''
                                    "
                                />
                            </button>
                            <span
                                class="text-right text-[15px] font-extrabold whitespace-nowrap tabular-nums"
                                :class="
                                    e.bundle.direction === 'abono'
                                        ? 'text-emerald-700 dark:text-emerald-400'
                                        : 'text-rose-700 dark:text-rose-400'
                                "
                                >{{ mxn(e.bundle.total) }}</span
                            >
                        </div>
                        <div
                            v-if="expanded.has(s.group.key + e.bundle.key)"
                            class="mx-4 mb-3 animate-in divide-y divide-border/60 overflow-hidden rounded-xl border bg-muted/20 duration-200 fade-in-0 slide-in-from-top-1 motion-reduce:animate-none"
                        >
                            <MovementRow
                                v-for="r in e.bundle.rows"
                                :key="r.id"
                                :row="r"
                                :selectable="selectable"
                                :checked="
                                    !!r.fingerprint &&
                                    selected.has(r.fingerprint)
                                "
                                :group-explain="s.group.explain"
                                @toggle="
                                    emit(
                                        'toggle',
                                        [r.fingerprint as string],
                                        !selected.has(r.fingerprint as string),
                                    )
                                "
                                @open="(fp) => emit('open', fp)"
                            />
                        </div>
                    </div>
                </template>
                <p
                    v-if="s.hidden > 0"
                    class="px-4 py-2 text-xs text-muted-foreground"
                >
                    {{ s.hidden.toLocaleString('es-MX') }} más en este grupo ·
                    usa «Mostrar más» abajo.
                </p>
            </div>
        </section>
    </div>
</template>
