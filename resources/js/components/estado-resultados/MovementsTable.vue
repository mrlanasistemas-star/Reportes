<script setup lang="ts">
import { useEventListener } from '@vueuse/core';
import {
    ChevronLeft,
    ChevronRight,
    Copy,
    HelpCircle,
    Inbox,
    Lock,
    PanelRightOpen,
    Search,
    Sparkles,
    Tags,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useToast } from '@/composables/useToast';
import { QUICK_FILTERS } from './movementFilters';
import type { MovementFilter } from './movementFilters';
import { mxn, num, reconciliationTone } from './types';
import type { Dataset, Movement } from './types';

const props = defineProps<{
    movements: Movement[];
    canClassify: boolean;
    toneOf: (treatment: string) => string;
    resolve: (key: string) => MovementFilter | null;
    /** Catálogo de dimensiones del dataset (para los filtros por dimensión). */
    dimensionCatalog?: Dataset['dimensions'];
    /** Aviso de movimientos ocultos por permisos (nómina / DG). */
    hiddenNote?: string | null;
    /** Motivo de solo lectura: oculta las acciones de clasificación. */
    lockReason?: string | null;
}>();

const canAct = computed(() => props.canClassify && !props.lockReason);
const filterKey = defineModel<string | null>('filter', { default: null });

const emit = defineEmits<{
    open: [m: Movement];
    classify: [fingerprints: string[]];
    acceptSuggestions: [fingerprints: string[], ask: boolean];
}>();

const toast = useToast();
const search = ref('');
const origin = ref('todos');
/** Filtros por dimensión independiente ('' = todas). */
const dimInclusion = ref('');
const dimReview = ref('');
const dimCategory = ref('');
const hasDimensions = computed(() =>
    props.movements.some((m) => !!m.dimensions),
);

type Dim = 'inclusion' | 'review' | 'category';

/** Opciones con conteo; sólo las que existen en los movimientos visibles. */
function dimOptions(dim: Dim) {
    const counts = new Map<string, number>();

    for (const m of props.movements) {
        const k = m.dimensions?.[dim]?.key ?? '__none';
        counts.set(k, (counts.get(k) ?? 0) + 1);
    }

    const catalog = props.dimensionCatalog?.[dim] ?? [];
    const label = (k: string) =>
        k === '__none'
            ? dim === 'category'
                ? 'Sin categoría del ER'
                : 'Sin dato'
            : (catalog.find((c) => c.key === k)?.label ??
              props.movements.find((m) => m.dimensions?.[dim]?.key === k)
                  ?.dimensions?.[dim]?.label ??
              k);

    return [...counts.entries()].map(([key, count]) => ({
        key,
        count,
        label: label(key),
    }));
}

const dimFilters = computed(() => [
    {
        key: 'inclusion' as Dim,
        label: 'Inclusión',
        opts: dimOptions('inclusion'),
    },
    { key: 'review' as Dim, label: 'Revisión', opts: dimOptions('review') },
    {
        key: 'category' as Dim,
        label: 'Categoría',
        opts: dimOptions('category'),
    },
]);
const dimRefs = {
    inclusion: dimInclusion,
    review: dimReview,
    category: dimCategory,
};
const dimActive = computed(
    () => !!(dimInclusion.value || dimReview.value || dimCategory.value),
);

function setDim(dim: Dim, value: string): void {
    dimRefs[dim].value = value;
}

function dimMatch(m: Movement): boolean {
    const k = (dim: Dim) => m.dimensions?.[dim]?.key ?? '__none';

    return (
        (!dimInclusion.value || k('inclusion') === dimInclusion.value) &&
        (!dimReview.value || k('review') === dimReview.value) &&
        (!dimCategory.value || k('category') === dimCategory.value)
    );
}

function clearDims(): void {
    dimInclusion.value = '';
    dimReview.value = '';
    dimCategory.value = '';
}

function clearAll(): void {
    filterKey.value = null;
    search.value = '';
    origin.value = 'todos';
    clearDims();
}
const page = ref(1);
const perPage = 50;
const selected = ref<Set<string>>(new Set());
const searchInput = ref<HTMLInputElement | null>(null);
const scroller = ref<HTMLElement | null>(null);

const active = computed(() =>
    filterKey.value ? props.resolve(filterKey.value) : null,
);
/** Un foco que no es chip rápido (sección, tratamiento) se muestra aparte. */
const externalFocus = computed(() =>
    active.value && !QUICK_FILTERS.some((f) => f.key === active.value?.key)
        ? active.value
        : null,
);

const origins = computed(() => {
    const map = new Map<string, string>();

    for (const m of props.movements) {
        map.set(
            m.account_slot,
            m.source === 'erp'
                ? 'ERP de gastos'
                : (m.account_label ?? m.account_slot),
        );
    }

    return [...map.entries()].map(([key, label]) => ({ key, label }));
});

const counts = computed(() =>
    Object.fromEntries(
        QUICK_FILTERS.map((c) => [
            c.key,
            props.movements.filter(c.test).length,
        ]),
    ),
);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    const f = active.value;

    return props.movements.filter((m) => {
        if (f && !f.test(m)) {
            return false;
        }

        if (!dimMatch(m)) {
            return false;
        }

        if (origin.value !== 'todos' && m.account_slot !== origin.value) {
            return false;
        }

        if (!q) {
            return true;
        }

        return [
            m.concept,
            m.beneficiary,
            m.folio,
            m.uuid,
            m.reference,
            m.branch_name,
            m.sucursal_raw,
            m.treatment_label,
            m.reconciliation_label,
            m.suggested_label,
            m.cargo,
            m.abono,
        ].some((v) => (v ?? '').toString().toLowerCase().includes(q));
    });
});

const pages = computed(() =>
    Math.max(1, Math.ceil(filtered.value.length / perPage)),
);
const rows = computed(() =>
    filtered.value.slice((page.value - 1) * perPage, page.value * perPage),
);

watch([filterKey, search, origin, dimInclusion, dimReview, dimCategory], () => {
    page.value = 1;
    scroller.value?.scrollTo({ top: 0 });
});
watch(pages, (p) => {
    if (page.value > p) {
        page.value = p;
    }
});

const selectedList = computed(() =>
    props.movements.filter((m) => selected.value.has(m.fingerprint)),
);
const hasSuggestion = (m: Movement) =>
    m.treatment === 'pendiente' && !!m.suggested_treatment;
const selectedWithSuggestion = computed(() =>
    selectedList.value.filter(hasSuggestion),
);
const visibleWithSuggestion = computed(() =>
    filtered.value.filter(hasSuggestion),
);

function toggle(fp: string): void {
    const next = new Set(selected.value);

    if (next.has(fp)) {
        next.delete(fp);
    } else {
        next.add(fp);
    }

    selected.value = next;
}

const allPageSelected = computed(
    () =>
        rows.value.length > 0 &&
        rows.value.every((m) => selected.value.has(m.fingerprint)),
);

function togglePage(): void {
    const next = new Set(selected.value);

    if (allPageSelected.value) {
        rows.value.forEach((m) => next.delete(m.fingerprint));
    } else {
        rows.value.forEach((m) => next.add(m.fingerprint));
    }

    selected.value = next;
}

function selectAllFiltered(): void {
    selected.value = new Set(filtered.value.map((m) => m.fingerprint));
}

function clearSelection(): void {
    selected.value = new Set();
}

function focusSearch(): void {
    searchInput.value?.focus();
}

defineExpose({ clearSelection, focusSearch });

// "/" enfoca la búsqueda (salvo que ya estés escribiendo en un campo).
useEventListener(window, 'keydown', (e: KeyboardEvent) => {
    const t = e.target as HTMLElement | null;

    if (
        e.key !== '/' ||
        e.ctrlKey ||
        e.metaKey ||
        (t && /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName)) ||
        t?.isContentEditable
    ) {
        return;
    }

    e.preventDefault();
    focusSearch();
});

async function copy(text: string, what: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(text);
        toast.success(`${what} copiado: ${text}`);
    } catch {
        toast.error('No se pudo copiar. Selecciónalo y copia con Ctrl+C.');
    }
}

function rowKey(e: KeyboardEvent, m: Movement): void {
    const row = e.currentTarget as HTMLElement;

    if (e.key === 'Enter') {
        e.preventDefault();
        emit('open', m);
    } else if (e.key === ' ' && canAct.value) {
        e.preventDefault();
        toggle(m.fingerprint);
    } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        const sib = (
            e.key === 'ArrowDown'
                ? row.nextElementSibling
                : row.previousElementSibling
        ) as HTMLElement | null;
        sib?.focus();
    }
}

function pickChip(key: string | null): void {
    filterKey.value = filterKey.value === key ? null : key;
}

async function goPage(p: number): Promise<void> {
    page.value = p;
    await nextTick();
    scroller.value?.scrollTo({ top: 0, behavior: 'smooth' });
}

function erpHint(m: Movement): string | null {
    if (m.source === 'erp') {
        return m.linked_bank.length
            ? `${m.linked_bank.length} pago(s) en banco`
            : null;
    }

    const e = m.erp[0];

    return e ? `ERP ${e.folio ?? ''} · ${mxn(e.amount)}` : null;
}
</script>

<template>
    <section class="rounded-2xl border bg-card shadow-soft-xs">
        <!-- Barra de búsqueda y filtros -->
        <div class="space-y-3 border-b p-3 md:p-4">
            <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
                <label class="relative flex-1">
                    <span class="sr-only">Buscar movimientos</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        ref="searchInput"
                        v-model="search"
                        type="search"
                        placeholder="Busca por concepto, beneficiario, folio, UUID, sucursal o importe"
                        class="h-11 w-full rounded-xl border bg-background pr-14 pl-9 text-sm transition outline-none focus:border-emerald-500 focus:ring-3 focus:ring-emerald-500/20"
                    />
                    <kbd
                        class="pointer-events-none absolute top-1/2 right-3 hidden -translate-y-1/2 rounded-md border bg-muted px-1.5 text-[10px] font-semibold text-muted-foreground sm:block"
                        >/</kbd
                    >
                </label>
                <select
                    v-model="origin"
                    aria-label="Origen"
                    class="h-11 rounded-xl border bg-background px-3 text-sm font-medium outline-none focus:border-emerald-500 focus:ring-3 focus:ring-emerald-500/20"
                >
                    <option value="todos">Todas las cuentas</option>
                    <option v-for="o in origins" :key="o.key" :value="o.key">
                        {{ o.label }}
                    </option>
                </select>
            </div>

            <div
                v-if="hasDimensions || hiddenNote"
                class="flex flex-wrap items-center gap-2"
                role="group"
                aria-label="Filtrar por dimensión"
            >
                <template v-if="hasDimensions">
                    <span class="text-xs font-semibold text-muted-foreground"
                        >Por dimensión:</span
                    >
                    <label v-for="f in dimFilters" :key="f.key">
                        <span class="sr-only">{{ f.label }}</span>
                        <select
                            :value="dimRefs[f.key].value"
                            class="h-9 max-w-64 rounded-xl border bg-background px-3 text-xs font-semibold outline-none focus:border-emerald-500 focus:ring-3 focus:ring-emerald-500/20"
                            :class="
                                dimRefs[f.key].value
                                    ? 'border-cyan-500 bg-cyan-50 text-cyan-900 dark:bg-cyan-950/40 dark:text-cyan-200'
                                    : ''
                            "
                            @change="
                                setDim(
                                    f.key,
                                    ($event.target as HTMLSelectElement).value,
                                )
                            "
                        >
                            <option value="">{{ f.label }}: todas</option>
                            <option
                                v-for="o in f.opts"
                                :key="o.key"
                                :value="o.key"
                            >
                                {{ o.label }} ({{ o.count }})
                            </option>
                        </select>
                    </label>
                    <button
                        v-if="dimActive"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold text-muted-foreground hover:bg-muted hover:text-foreground"
                        @click="clearDims"
                    >
                        <X class="size-3.5" /> Quitar
                    </button>
                </template>
                <span
                    v-if="hiddenNote"
                    class="ml-auto inline-flex items-center gap-1.5 rounded-lg bg-slate-100 px-2.5 py-1 text-xs text-slate-700 dark:bg-slate-800 dark:text-slate-300"
                    ><Lock class="size-3.5" /> {{ hiddenNote }}</span
                >
            </div>

            <div
                class="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1 [scrollbar-width:thin]"
                role="toolbar"
                aria-label="Filtros rápidos"
            >
                <button
                    type="button"
                    class="shrink-0 rounded-full border px-3 py-1.5 text-xs font-semibold transition"
                    :class="
                        !filterKey
                            ? 'border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-900'
                            : 'hover:bg-muted'
                    "
                    :aria-pressed="!filterKey"
                    @click="pickChip(null)"
                >
                    Todos
                    <span class="ml-1 tabular-nums opacity-70">{{
                        movements.length
                    }}</span>
                </button>
                <button
                    v-for="c in QUICK_FILTERS"
                    :key="c.key"
                    type="button"
                    class="shrink-0 rounded-full border px-3 py-1.5 text-xs font-semibold transition disabled:opacity-40"
                    :class="
                        filterKey === c.key
                            ? 'border-slate-900 bg-slate-900 text-white dark:border-white dark:bg-white dark:text-slate-900'
                            : c.key === 'pendientes' && counts[c.key]
                              ? 'border-amber-300 text-amber-900 hover:bg-amber-50 dark:border-amber-800 dark:text-amber-300 dark:hover:bg-amber-950/40'
                              : 'hover:bg-muted'
                    "
                    :title="c.hint"
                    :aria-pressed="filterKey === c.key"
                    :disabled="!counts[c.key] && filterKey !== c.key"
                    @click="pickChip(c.key)"
                >
                    {{ c.label }}
                    <span class="ml-1 tabular-nums opacity-70">{{
                        counts[c.key]
                    }}</span>
                </button>
            </div>

            <Transition name="er-fade">
                <div
                    v-if="externalFocus"
                    class="flex items-center gap-2 rounded-xl border border-cyan-300 bg-cyan-50 px-3 py-2 text-xs text-cyan-900 dark:border-cyan-800 dark:bg-cyan-950/40 dark:text-cyan-200"
                >
                    <span class="size-2 shrink-0 rounded-full bg-cyan-500" />
                    <span class="min-w-0 flex-1"
                        ><b>Mostrando: {{ externalFocus.label }}.</b>
                        {{ externalFocus.hint }}</span
                    >
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-lg px-2 py-1 font-semibold hover:bg-cyan-100 dark:hover:bg-cyan-900/50"
                        @click="filterKey = null"
                    >
                        <X class="size-3.5" /> Quitar filtro
                    </button>
                </div>
            </Transition>
        </div>

        <!-- Acciones en lote -->
        <Transition name="er-fade">
            <div
                v-if="
                    canAct &&
                    (selectedList.length || visibleWithSuggestion.length)
                "
                class="flex flex-wrap items-center gap-2 border-b bg-muted/40 px-3 py-2 text-xs md:px-4"
            >
                <template v-if="selectedList.length">
                    <span class="font-bold tabular-nums"
                        >{{ selectedList.length }} seleccionado(s)</span
                    >
                    <button
                        v-if="selectedList.length < filtered.length"
                        type="button"
                        class="font-semibold text-cyan-700 hover:underline dark:text-cyan-300"
                        @click="selectAllFiltered"
                    >
                        Seleccionar los {{ filtered.length }} del filtro
                    </button>
                    <span class="flex-1" />
                    <Button
                        size="sm"
                        variant="outline"
                        class="rounded-lg"
                        :disabled="!selectedWithSuggestion.length"
                        @click="
                            emit(
                                'acceptSuggestions',
                                selectedWithSuggestion.map(
                                    (m) => m.fingerprint,
                                ),
                                true,
                            )
                        "
                    >
                        <Sparkles class="size-3.5 text-amber-500" />
                        Aceptar sugerencias ({{
                            selectedWithSuggestion.length
                        }})
                    </Button>
                    <Button
                        size="sm"
                        class="rounded-lg bg-emerald-700 text-white hover:bg-emerald-800 dark:bg-emerald-500 dark:text-emerald-950"
                        @click="
                            emit(
                                'classify',
                                selectedList.map((m) => m.fingerprint),
                            )
                        "
                    >
                        <Tags class="size-3.5" /> Clasificar selección
                    </Button>
                    <button
                        type="button"
                        class="rounded-lg px-2 py-1 font-semibold text-muted-foreground hover:bg-muted hover:text-foreground"
                        @click="clearSelection"
                    >
                        Limpiar
                    </button>
                </template>
                <template v-else>
                    <Sparkles class="size-4 text-amber-500" />
                    <span
                        >{{ visibleWithSuggestion.length }} movimiento(s) de
                        esta vista tienen una sugerencia lista.</span
                    >
                    <span class="flex-1" />
                    <Button
                        size="sm"
                        variant="outline"
                        class="rounded-lg"
                        @click="
                            emit(
                                'acceptSuggestions',
                                visibleWithSuggestion.map((m) => m.fingerprint),
                                true,
                            )
                        "
                    >
                        Aceptar las {{ visibleWithSuggestion.length }}
                    </Button>
                </template>
            </div>
        </Transition>

        <!-- Tabla -->
        <div
            ref="scroller"
            class="max-h-[72vh] overflow-auto overscroll-contain"
        >
            <table
                class="w-full min-w-[1240px] border-separate border-spacing-0 text-xs"
            >
                <thead
                    class="sticky top-0 z-10 bg-card/95 text-left text-[11px] text-muted-foreground backdrop-blur"
                >
                    <tr
                        class="[&>th]:border-b [&>th]:px-2.5 [&>th]:py-2.5 [&>th]:font-semibold"
                    >
                        <th v-if="canAct" class="w-9 pl-4">
                            <input
                                type="checkbox"
                                class="size-4 accent-emerald-600"
                                :checked="allPageSelected"
                                aria-label="Seleccionar los movimientos de esta página"
                                @change="togglePage"
                            />
                        </th>
                        <th class="w-24">Fecha</th>
                        <th class="w-36">Cuenta</th>
                        <th class="min-w-[240px]">Concepto y folio</th>
                        <th class="w-32">Sucursal</th>
                        <th class="w-32 text-right">Importe</th>
                        <th>Conciliación</th>
                        <th>Clasificación</th>
                        <th class="whitespace-nowrap">¿Suma al resultado?</th>
                        <th class="w-28 pr-4 text-right">
                            <span class="sr-only">Acciones</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="m in rows"
                        :key="m.fingerprint"
                        tabindex="0"
                        class="group cursor-pointer transition-colors outline-none hover:bg-emerald-50/60 focus-visible:bg-cyan-50 dark:hover:bg-emerald-950/20 dark:focus-visible:bg-cyan-950/30 [&>td]:border-b [&>td]:border-border/60 [&>td]:px-2.5 [&>td]:py-2.5"
                        :class="[
                            selected.has(m.fingerprint)
                                ? 'bg-emerald-50 dark:bg-emerald-950/30'
                                : '',
                            !m.in_period ? 'opacity-60' : '',
                        ]"
                        :aria-label="`${m.concept ?? 'Movimiento'} del ${m.date}`"
                        @click="emit('open', m)"
                        @keydown="rowKey($event, m)"
                    >
                        <td v-if="canAct" class="pl-4" @click.stop>
                            <input
                                type="checkbox"
                                class="size-4 accent-emerald-600"
                                :checked="selected.has(m.fingerprint)"
                                :aria-label="`Seleccionar ${m.concept ?? 'movimiento'}`"
                                tabindex="-1"
                                @change="toggle(m.fingerprint)"
                            />
                        </td>
                        <td class="whitespace-nowrap tabular-nums">
                            {{ m.date }}
                        </td>
                        <td class="whitespace-nowrap">
                            <span class="block font-semibold">{{
                                m.source === 'erp'
                                    ? 'ERP'
                                    : (m.account_label ?? m.account_slot)
                            }}</span>
                            <span class="text-[10.5px] text-muted-foreground">{{
                                m.source === 'erp'
                                    ? `registro ${m.source_row}`
                                    : `fila ${m.source_row}`
                            }}</span>
                        </td>
                        <td class="max-w-[380px] min-w-[240px]">
                            <span
                                v-if="m.redacted"
                                class="flex items-center gap-1 text-muted-foreground italic"
                                ><Lock class="size-3" /> Detalle restringido por
                                permisos</span
                            >
                            <template v-else>
                                <span class="block truncate font-semibold">{{
                                    m.concept ?? '—'
                                }}</span>
                                <span
                                    class="flex items-center gap-1.5 text-[10.5px] text-muted-foreground"
                                >
                                    <button
                                        v-if="m.folio"
                                        type="button"
                                        class="inline-flex shrink-0 items-center gap-1 rounded-md bg-muted px-1.5 py-px font-semibold text-foreground tabular-nums transition hover:bg-cyan-100 hover:text-cyan-900 dark:hover:bg-cyan-900/50 dark:hover:text-cyan-100"
                                        :title="`Copiar folio ${m.folio}`"
                                        @click.stop="
                                            copy(m.folio ?? '', 'Folio')
                                        "
                                    >
                                        {{ m.folio }}
                                        <Copy class="size-2.5 opacity-60" />
                                    </button>
                                    <span class="truncate">{{
                                        m.beneficiary ??
                                        m.category ??
                                        m.reference ??
                                        ''
                                    }}</span>
                                </span>
                            </template>
                        </td>
                        <td class="truncate whitespace-nowrap">
                            {{ m.branch_name ?? m.sucursal_raw ?? '—' }}
                        </td>
                        <td class="text-right whitespace-nowrap tabular-nums">
                            <span
                                v-if="num(m.cargo)"
                                class="block font-semibold"
                                title="Cargo: salida de dinero"
                                >{{ mxn(m.cargo) }}</span
                            >
                            <span
                                v-if="num(m.abono)"
                                class="block font-semibold text-sky-700 dark:text-sky-400"
                                title="Abono: entrada de dinero"
                                >+{{ mxn(m.abono) }}</span
                            >
                        </td>
                        <td>
                            <span
                                class="inline-block max-w-[200px] truncate rounded-full border px-2 py-0.5 text-[10.5px] font-semibold"
                                :class="
                                    reconciliationTone(m.reconciliation_status)
                                "
                                :title="
                                    m.reconciliation_message ??
                                    m.reconciliation_label
                                "
                                >{{ m.reconciliation_label }}</span
                            >
                            <span
                                v-if="erpHint(m)"
                                class="mt-0.5 block truncate text-[10.5px] text-muted-foreground"
                                >{{ erpHint(m) }}</span
                            >
                        </td>
                        <td>
                            <span
                                class="inline-block max-w-[200px] truncate rounded-full border px-2 py-0.5 text-[10.5px] font-semibold"
                                :class="toneOf(m.treatment)"
                                >{{ m.treatment_label }}</span
                            >
                            <span
                                v-if="m.treatment_source === 'manual'"
                                class="mt-0.5 block text-[10.5px] font-semibold text-violet-600 dark:text-violet-400"
                                >Clasificado a mano</span
                            >
                            <span
                                v-else-if="hasSuggestion(m)"
                                class="mt-0.5 flex items-center gap-1 truncate text-[10.5px] text-amber-700 dark:text-amber-400"
                                ><Sparkles class="size-3 shrink-0" />
                                {{ m.suggested_label }}</span
                            >
                        </td>
                        <td @click.stop>
                            <Popover>
                                <PopoverTrigger as-child>
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-1 text-[11px] font-bold whitespace-nowrap transition hover:ring-2 focus-visible:ring-2 focus-visible:outline-none"
                                        :class="
                                            m.impact.included
                                                ? 'bg-emerald-100 text-emerald-800 hover:ring-emerald-300 dark:bg-emerald-950/60 dark:text-emerald-300 dark:hover:ring-emerald-800'
                                                : m.needs_review
                                                  ? 'bg-amber-100 text-amber-900 hover:ring-amber-300 dark:bg-amber-950/60 dark:text-amber-300 dark:hover:ring-amber-800'
                                                  : 'bg-muted text-muted-foreground hover:ring-border'
                                        "
                                        :aria-label="
                                            m.impact.included
                                                ? `Suma ${mxn(m.impact.amount)}. Ver por qué`
                                                : '¿Por qué no suma?'
                                        "
                                    >
                                        {{
                                            m.impact.included
                                                ? `Suma ${mxn(m.impact.amount)}`
                                                : m.needs_review
                                                  ? 'Aún no suma'
                                                  : 'No suma'
                                        }}
                                        <HelpCircle class="size-3 opacity-70" />
                                    </button>
                                </PopoverTrigger>
                                <PopoverContent
                                    class="w-80 rounded-2xl text-xs"
                                    align="end"
                                >
                                    <p class="text-sm font-bold">
                                        {{
                                            m.impact.included
                                                ? '¿Por qué suma?'
                                                : '¿Por qué no suma?'
                                        }}
                                    </p>
                                    <p class="mt-1 font-semibold">
                                        {{ m.impact.text }}
                                    </p>
                                    <p
                                        class="mt-2 leading-relaxed text-muted-foreground"
                                    >
                                        {{ m.treatment_explain }}
                                    </p>
                                    <p
                                        v-if="m.rule_reason"
                                        class="mt-2 leading-relaxed"
                                    >
                                        <span class="font-semibold"
                                            >Regla:</span
                                        >
                                        {{ m.rule_reason }}
                                    </p>
                                    <p
                                        v-if="m.reconciliation_message"
                                        class="mt-2 leading-relaxed"
                                    >
                                        <span class="font-semibold"
                                            >Conciliación:</span
                                        >
                                        {{ m.reconciliation_message }}
                                    </p>
                                    <p
                                        v-if="m.needs_review"
                                        class="mt-2 rounded-lg bg-amber-50 p-2 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200"
                                    >
                                        Sumará en cuanto alguien lo clasifique
                                        en una línea del Estado de Resultados.
                                    </p>
                                </PopoverContent>
                            </Popover>
                        </td>
                        <td
                            class="pr-4 text-right whitespace-nowrap"
                            @click.stop
                        >
                            <span
                                class="inline-flex gap-0.5 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100 group-focus-visible:opacity-100 max-md:opacity-100"
                            >
                                <button
                                    v-if="canAct && hasSuggestion(m)"
                                    type="button"
                                    class="rounded-lg p-1.5 text-amber-600 transition hover:bg-amber-100 dark:hover:bg-amber-950/50"
                                    :title="`Aceptar sugerencia: ${m.suggested_label}`"
                                    :aria-label="`Aceptar sugerencia: ${m.suggested_label}`"
                                    @click="
                                        emit(
                                            'acceptSuggestions',
                                            [m.fingerprint],
                                            false,
                                        )
                                    "
                                >
                                    <Sparkles class="size-4" />
                                </button>
                                <button
                                    v-if="canAct"
                                    type="button"
                                    class="rounded-lg p-1.5 text-emerald-700 transition hover:bg-emerald-100 dark:text-emerald-400 dark:hover:bg-emerald-950/50"
                                    title="Clasificar"
                                    aria-label="Clasificar este movimiento"
                                    @click="emit('classify', [m.fingerprint])"
                                >
                                    <Tags class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    class="rounded-lg p-1.5 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                                    title="Ver detalle"
                                    aria-label="Ver detalle del movimiento"
                                    @click="emit('open', m)"
                                >
                                    <PanelRightOpen class="size-4" />
                                </button>
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div
                v-if="!rows.length"
                class="flex flex-col items-center gap-2 px-4 py-16 text-center"
            >
                <Inbox class="size-9 text-muted-foreground/60" />
                <p class="text-sm font-semibold">
                    {{
                        movements.length
                            ? 'Ningún movimiento coincide'
                            : 'Todavía no hay movimientos'
                    }}
                </p>
                <p class="max-w-sm text-xs text-muted-foreground">
                    {{
                        movements.length
                            ? 'Prueba con otra búsqueda o quita el filtro activo.'
                            : 'Carga los estados de cuenta en Fuentes y procesa la conciliación para ver aquí cada movimiento.'
                    }}
                </p>
                <button
                    v-if="
                        movements.length &&
                        (filterKey || search || origin !== 'todos' || dimActive)
                    "
                    type="button"
                    class="mt-1 rounded-xl border px-3 py-1.5 text-xs font-semibold hover:bg-muted"
                    @click="clearAll"
                >
                    Quitar filtros
                </button>
            </div>
        </div>

        <div
            v-if="rows.length"
            class="flex flex-wrap items-center justify-between gap-2 border-t px-4 py-2.5 text-xs text-muted-foreground"
        >
            <span
                >{{ filtered.length.toLocaleString('es-MX') }} movimiento(s) ·
                página {{ page }} de {{ pages }}
                <span class="hidden lg:inline"
                    >· Enter abre el detalle, espacio selecciona, ↑ ↓ se
                    mueve</span
                ></span
            >
            <div class="flex gap-1">
                <Button
                    size="icon-sm"
                    variant="outline"
                    :disabled="page <= 1"
                    aria-label="Página anterior"
                    @click="goPage(page - 1)"
                >
                    <ChevronLeft />
                </Button>
                <Button
                    size="icon-sm"
                    variant="outline"
                    :disabled="page >= pages"
                    aria-label="Página siguiente"
                    @click="goPage(page + 1)"
                >
                    <ChevronRight />
                </Button>
            </div>
        </div>
    </section>
</template>
