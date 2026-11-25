<script setup lang="ts">
/**
 * CuadrePanel — pestaña «Cuadre»: cuánto fue el gasto y cuánto el ingreso del periodo, sin
 * repetir ni omitir nada.
 *
 * Arriba: ingresos, gastos, fuera del ER (con desglose) y pendiente, y la ecuación de cuadre
 * «Σ por destino = universo importado» con su diferencia (verde en $0.00, roja si no).
 * Cuerpo: una tarjeta por categoría (tratamiento) con su total, conteo y movimientos agrupados
 * por concepto. Se puede seleccionar, mover (arrastrar a otra tarjeta o «Mover a…»), marcar
 * «Ya considerado», ordenar, plegar y elegir qué categorías ver y en qué orden (se recuerda en
 * este navegador). Sin permiso o con el periodo aprobado: sólo lectura.
 *
 * Props:
 * - cuadre: bloque dataset.cuadre (null en versiones anteriores).
 * - movements: movimientos visibles (ya redactados).
 * - treatments: catálogo de tratamientos.
 * - canAct: puede clasificar (permiso y periodo abierto).
 * - canCatalog: puede crear reglas del catálogo.
 * - lockReason: motivo de solo lectura.
 * - consolidated: alcance consolidado (si no, se avisa que el cuadre es del periodo completo).
 *
 * Emits:
 * - open(m): abrir el detalle de un movimiento.
 * - move(fps, treatment): reclasificar (abre «Clasificar» con el destino elegido).
 * - consider(fps): marcar «Ya considerado».
 * - createRule(m): crear una regla del catálogo desde el concepto.
 */
import {
    ArrowDown,
    ArrowDownLeft,
    ArrowUp,
    ArrowUpRight,
    BookMarked,
    CheckCircle2,
    ChevronDown,
    Clock,
    Crown,
    Eye,
    EyeOff,
    GripVertical,
    Layers,
    ListTree,
    Lock,
    LogOut,
    MoveRight,
    RotateCcw,
    Search,
    Settings2,
    Sigma,
    X,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import {
    arrange,
    buildCategories,
    DEFAULT_PREFS,
    loadPrefs,
    savePrefs,
} from './cuadre';
import type { CuadrePrefs, CuadreRow } from './cuadre';
import ErModal from './ErModal.vue';
import { mxn, num } from './types';
import type { Cuadre, Movement, TreatmentMeta } from './types';

const props = defineProps<{
    cuadre: Cuadre | null;
    movements: Movement[];
    treatments: TreatmentMeta[];
    canAct: boolean;
    canCatalog: boolean;
    lockReason?: string | null;
    consolidated: boolean;
}>();
const emit = defineEmits<{
    open: [m: Movement];
    move: [fingerprints: string[], treatment: string];
    consider: [fingerprints: string[]];
    createRule: [m: Movement];
}>();

// ── Estado (todo antes de cualquier watch) ─────────────────────────────

/** Preferencias del usuario (orden, ocultas, plegadas, orden de filas). */
const prefs = ref<CuadrePrefs>(loadPrefs());
/** Texto de búsqueda. */
const search = ref('');
/** Huellas seleccionadas. */
const selected = ref<Set<string>>(new Set());
/** Grupos de concepto desplegados («categoría|concepto»). */
const expanded = ref<Set<string>>(new Set());
/** Categoría sobre la que se arrastra. */
const dragOver = ref<string | null>(null);
/** Modal de configuración abierto. */
const configOpen = ref(false);
/** Destino elegido en «Mover a…». */
const moveTarget = ref('');

/** Todas las categorías (con filas), antes de aplicar preferencias. */
const all = computed(() =>
    props.cuadre
        ? buildCategories(
              props.cuadre,
              props.movements,
              props.treatments,
              prefs.value.sort,
          )
        : [],
);
/** Categorías visibles en el orden del usuario, con la búsqueda aplicada a sus filas. */
const shown = computed(() => {
    const q = search.value.trim().toLowerCase();
    const list = arrange(all.value, prefs.value);

    if (!q) {
        return list;
    }

    const hit = (r: CuadreRow) =>
        [
            r.movement.concept,
            r.movement.folio,
            r.movement.beneficiary,
            r.movement.date,
            r.movement.account_label,
        ]
            .filter(Boolean)
            .join(' ')
            .toLowerCase()
            .includes(q);

    return list.map((c) => ({
        ...c,
        bundles: c.bundles
            .map((b) => ({ ...b, rows: b.rows.filter(hit) }))
            .filter((b) => b.rows.length),
    }));
});
/** Movimientos seleccionados (en el orden del dataset). */
const selectedMovements = computed(() =>
    props.movements.filter((m) => selected.value.has(m.fingerprint)),
);
/** Importe bruto de la selección. */
const selectedAmount = computed(() =>
    selectedMovements.value.reduce(
        (s, m) => s + num(m.cargo) + num(m.abono),
        0,
    ),
);
/** Destinos posibles de «Mover a…». */
const targets = computed(() => props.treatments.filter((t) => t.assignable));
/** Ecuación de cuadre por tipo de destino. */
const equation = computed(() => {
    const c = props.cuadre;

    return c
        ? [
              { key: 'er', label: 'En el ER', amount: c.by_kind.er },
              {
                  key: 'retiros',
                  label: 'Retiros DG y socios',
                  amount: c.by_kind.retiros,
              },
              { key: 'fuera', label: 'Fuera del ER', amount: c.by_kind.fuera },
              {
                  key: 'pendiente',
                  label: 'Pendiente',
                  amount: c.by_kind.pendiente,
              },
          ]
        : [];
});

/** Orden actual de todas las categorías (para el modal de configuración). */
const configList = computed(() =>
    arrange(all.value, { ...prefs.value, hidden: [], showEmpty: true }),
);

/** Icono y color por tipo de categoría. */
const KIND = {
    er: {
        icon: ListTree,
        chip: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
        ring: 'ring-emerald-400',
    },
    retiros: {
        icon: Crown,
        chip: 'bg-violet-100 text-violet-800 dark:bg-violet-950/60 dark:text-violet-300',
        ring: 'ring-violet-400',
    },
    fuera: {
        icon: LogOut,
        chip: 'bg-slate-200 text-slate-800 dark:bg-slate-800 dark:text-slate-200',
        ring: 'ring-slate-400',
    },
    pendiente: {
        icon: Clock,
        chip: 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
        ring: 'ring-amber-400',
    },
} as const;

// Las preferencias se guardan en este navegador al cambiar.
watch(prefs, (p) => savePrefs(p), { deep: true });
// Al cambiar los datos (tras clasificar) se limpia la selección: nunca se actúa sobre algo viejo.
watch(
    () => props.movements,
    () => (selected.value = new Set()),
);

/**
 * Despliega o pliega un grupo de concepto.
 * @param key «categoría|concepto».
 */
function flip(key: string): void {
    const next = new Set(expanded.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    expanded.value = next;
}

/** Pliega o despliega una categoría (se recuerda). */
function toggleCollapsed(key: string): void {
    const list = prefs.value.collapsed;
    prefs.value.collapsed = list.includes(key)
        ? list.filter((k) => k !== key)
        : [...list, key];
}

/** Pliega todo o lo despliega todo. */
function collapseAll(): void {
    const keys = shown.value.map((c) => c.key);
    prefs.value.collapsed = keys.every((k) => prefs.value.collapsed.includes(k))
        ? []
        : keys;
}

/**
 * Marca o desmarca filas.
 * @param rows filas.
 */
function toggleRows(rows: CuadreRow[]): void {
    const fps = rows.map((r) => r.fingerprint);
    const on = !fps.every((f) => selected.value.has(f));
    const next = new Set(selected.value);
    fps.forEach((f) => (on ? next.add(f) : next.delete(f)));
    selected.value = next;
}

/** Estado de la casilla de un grupo de filas. */
function stateOf(rows: CuadreRow[]): 'all' | 'some' | 'none' {
    const on = rows.filter((r) => selected.value.has(r.fingerprint)).length;

    return on === 0 ? 'none' : on === rows.length ? 'all' : 'some';
}

/**
 * Inicia el arrastre: si la fila está seleccionada se arrastra toda la selección.
 * @param e evento.
 * @param rows filas arrastradas (una fila o un concepto).
 */
function onDragStart(e: DragEvent, rows: CuadreRow[]): void {
    if (!props.canAct) {
        return;
    }

    const fps = rows.some((r) => selected.value.has(r.fingerprint))
        ? [...selected.value]
        : [...new Set(rows.map((r) => r.fingerprint))];
    e.dataTransfer?.setData('application/x-er-fps', JSON.stringify(fps));
    e.dataTransfer?.setData('text/plain', `${fps.length} movimiento(s)`);

    if (e.dataTransfer) {
        e.dataTransfer.effectAllowed = 'move';
    }
}

/**
 * Suelta sobre una categoría: pide reclasificar a ese tratamiento.
 * @param e evento.
 * @param key tratamiento destino.
 */
function onDrop(e: DragEvent, key: string): void {
    dragOver.value = null;

    try {
        const fps = JSON.parse(
            e.dataTransfer?.getData('application/x-er-fps') || '[]',
        ) as string[];

        if (fps.length) {
            emit('move', fps, key);
        }
    } catch {
        // Arrastre ajeno a la lista: se ignora.
    }
}

/** «Mover a…» con la selección. */
function moveSelected(): void {
    if (moveTarget.value && selected.value.size) {
        emit('move', [...selected.value], moveTarget.value);
        moveTarget.value = '';
    }
}

/**
 * Mueve una categoría en el orden del usuario.
 * @param key categoría.
 * @param dir -1 arriba, +1 abajo.
 */
function shift(key: string, dir: -1 | 1): void {
    const order = all.value.map((c) => c.key);
    const current = [
        ...prefs.value.order.filter((k) => order.includes(k)),
        ...order.filter((k) => !prefs.value.order.includes(k)),
    ];
    const i = current.indexOf(key);
    const j = i + dir;

    if (i < 0 || j < 0 || j >= current.length) {
        return;
    }

    [current[i], current[j]] = [current[j], current[i]];
    prefs.value.order = current;
}

/** Muestra u oculta una categoría. */
function toggleHidden(key: string): void {
    const list = prefs.value.hidden;
    prefs.value.hidden = list.includes(key)
        ? list.filter((k) => k !== key)
        : [...list, key];
}

/** Vuelve a la configuración de fábrica. */
function resetPrefs(): void {
    prefs.value = { ...DEFAULT_PREFS, order: [], hidden: [], collapsed: [] };
}
</script>

<template>
    <div class="space-y-4 text-[15px]">
        <p
            v-if="!cuadre"
            class="rounded-2xl border border-dashed bg-card p-8 text-center text-sm text-muted-foreground"
        >
            Esta versión se aprobó antes de que existiera el cuadre de gastos e
            ingresos: no trae ese detalle.
        </p>

        <template v-else>
            <!-- KPIs -->
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    class="animate-in rounded-2xl border bg-card p-4 shadow-soft-xs duration-300 fade-in-0 slide-in-from-bottom-1"
                >
                    <p
                        class="flex items-center gap-1.5 text-sm font-semibold text-emerald-700 dark:text-emerald-400"
                    >
                        <ArrowDownLeft class="size-4" /> Ingresos
                    </p>
                    <p class="mt-1 text-2xl font-extrabold tabular-nums">
                        {{ mxn(cuadre.ingresos) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Abonos del periodo
                    </p>
                </div>
                <div
                    class="animate-in rounded-2xl border bg-card p-4 shadow-soft-xs duration-300 fade-in-0 slide-in-from-bottom-1"
                >
                    <p
                        class="flex items-center gap-1.5 text-sm font-semibold text-rose-700 dark:text-rose-400"
                    >
                        <ArrowUpRight class="size-4" /> Gastos
                    </p>
                    <p class="mt-1 text-2xl font-extrabold tabular-nums">
                        {{ mxn(cuadre.gastos) }}
                    </p>
                    <p
                        class="text-xs text-muted-foreground"
                        :title="
                            num(cuadre.overlap)
                                ? `Pagos del banco del mismo gasto ya registrado en el ERP: cuentan una vez (${mxn(cuadre.overlap)}).`
                                : undefined
                        "
                    >
                        Cargos y ERP, sin repetir
                    </p>
                </div>
                <div
                    class="animate-in rounded-2xl border bg-card p-4 shadow-soft-xs duration-300 fade-in-0 slide-in-from-bottom-1"
                >
                    <p
                        class="flex items-center gap-1.5 text-sm font-semibold text-slate-700 dark:text-slate-300"
                    >
                        <LogOut class="size-4" /> Fuera del ER
                    </p>
                    <p class="mt-1 text-2xl font-extrabold tabular-nums">
                        {{ mxn(cuadre.by_kind.fuera) }}
                    </p>
                    <ul class="mt-1.5 flex flex-wrap gap-1">
                        <li
                            v-for="o in cuadre.outside.filter((x) =>
                                num(x.amount),
                            )"
                            :key="o.key"
                            class="rounded-full bg-muted px-2 py-0.5 text-xs tabular-nums"
                        >
                            {{ o.label }} <b>{{ mxn(o.amount) }}</b>
                        </li>
                    </ul>
                </div>
                <div
                    class="animate-in rounded-2xl border bg-card p-4 shadow-soft-xs duration-300 fade-in-0 slide-in-from-bottom-1"
                    :class="
                        num(cuadre.by_kind.pendiente)
                            ? 'border-amber-300 dark:border-amber-800'
                            : ''
                    "
                >
                    <p
                        class="flex items-center gap-1.5 text-sm font-semibold text-amber-700 dark:text-amber-400"
                    >
                        <Clock class="size-4" /> Pendiente
                    </p>
                    <p class="mt-1 text-2xl font-extrabold tabular-nums">
                        {{ mxn(cuadre.by_kind.pendiente) }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Ni suma ni se excluye
                    </p>
                </div>
            </div>

            <!-- Ecuación de cuadre -->
            <div
                class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-2xl border px-4 py-3 transition-colors"
                :class="
                    cuadre.ok
                        ? 'border-emerald-300 bg-emerald-50/70 dark:border-emerald-900 dark:bg-emerald-950/20'
                        : 'border-rose-300 bg-rose-50 dark:border-rose-800 dark:bg-rose-950/30'
                "
                role="status"
            >
                <Sigma
                    class="size-5 shrink-0"
                    :class="cuadre.ok ? 'text-emerald-600' : 'text-rose-600'"
                />
                <template v-for="(t, i) in equation" :key="t.key">
                    <span v-if="i" class="text-muted-foreground">+</span>
                    <span class="text-sm"
                        >{{ t.label }}
                        <b class="tabular-nums">{{ mxn(t.amount) }}</b></span
                    >
                </template>
                <span class="text-muted-foreground">=</span>
                <span class="text-sm"
                    >Importado
                    <b class="tabular-nums">{{ mxn(cuadre.universe) }}</b></span
                >
                <span
                    class="ml-auto inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-bold"
                    :class="
                        cuadre.ok
                            ? 'bg-emerald-600 text-white'
                            : 'bg-rose-600 text-white'
                    "
                >
                    <CheckCircle2 v-if="cuadre.ok" class="size-4" />
                    Diferencia {{ mxn(cuadre.difference) }}
                </span>
                <p
                    v-if="num(cuadre.overlap) || !consolidated"
                    class="w-full text-xs text-muted-foreground"
                >
                    <template v-if="num(cuadre.overlap)"
                        >Importado {{ mxn(cuadre.imported) }} −
                        {{ mxn(cuadre.overlap) }} de pagos del banco que son el
                        mismo gasto del ERP (cuentan una vez).
                    </template>
                    <template v-if="!consolidated"
                        >El cuadre es del periodo completo (todas las
                        sucursales).</template
                    >
                </p>
            </div>

            <!-- Barra de herramientas -->
            <div class="flex flex-wrap items-center gap-2">
                <label class="relative min-w-52 flex-1">
                    <span class="sr-only">Buscar en el cuadre</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Buscar concepto, folio, fecha…"
                        class="h-10 w-full rounded-xl border bg-background pr-3 pl-9 text-sm shadow-xs outline-none focus-visible:border-emerald-500 focus-visible:ring-2 focus-visible:ring-emerald-500/30"
                    />
                </label>
                <select
                    v-model="prefs.sort"
                    class="h-10 rounded-xl border bg-background px-3 text-sm shadow-xs"
                    aria-label="Ordenar movimientos"
                >
                    <option value="importe">Mayor importe</option>
                    <option value="fecha">Fecha</option>
                    <option value="concepto">Concepto A-Z</option>
                </select>
                <button
                    type="button"
                    class="inline-flex h-10 items-center gap-1.5 rounded-xl border bg-background px-3 text-sm font-semibold transition hover:bg-muted"
                    @click="collapseAll"
                >
                    <Layers class="size-4" /> Plegar / desplegar
                </button>
                <button
                    type="button"
                    class="inline-flex h-10 items-center gap-1.5 rounded-xl border bg-background px-3 text-sm font-semibold transition hover:bg-muted"
                    @click="configOpen = true"
                >
                    <Settings2 class="size-4" /> Categorías
                </button>
                <span
                    v-if="!canAct"
                    class="inline-flex items-center gap-1.5 text-sm text-muted-foreground"
                    :title="lockReason ?? 'Sin permiso para clasificar'"
                >
                    <Lock class="size-4" /> Solo lectura
                </span>
            </div>

            <!-- Categorías -->
            <TransitionGroup
                tag="div"
                class="grid items-start gap-4 lg:grid-cols-2 2xl:grid-cols-3"
                move-class="transition-transform duration-300"
            >
                <section
                    v-for="cat in shown"
                    :key="cat.key"
                    class="overflow-hidden rounded-2xl border bg-card shadow-soft-xs transition"
                    :class="
                        dragOver === cat.key
                            ? `ring-2 ring-offset-2 ${KIND[cat.kind].ring}`
                            : ''
                    "
                    :aria-label="cat.label"
                    @dragover.prevent="
                        canAct && cat.assignable && (dragOver = cat.key)
                    "
                    @dragleave="dragOver === cat.key && (dragOver = null)"
                    @drop.prevent="
                        canAct && cat.assignable && onDrop($event, cat.key)
                    "
                >
                    <header
                        class="flex items-start gap-3 border-b bg-muted/30 px-4 py-3"
                    >
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-xl"
                            :class="KIND[cat.kind].chip"
                        >
                            <component
                                :is="KIND[cat.kind].icon"
                                class="size-4"
                            />
                        </span>
                        <button
                            type="button"
                            class="min-w-0 flex-1 text-left focus-visible:outline-none"
                            :aria-expanded="!prefs.collapsed.includes(cat.key)"
                            @click="toggleCollapsed(cat.key)"
                        >
                            <span
                                class="block truncate leading-snug font-bold"
                                :title="cat.label"
                                >{{ cat.label }}</span
                            >
                            <span class="text-sm text-muted-foreground">
                                {{ cat.count.toLocaleString('es-MX') }} mov.
                                <template
                                    v-if="num(cat.cargos) && num(cat.abonos)"
                                >
                                    · cargos {{ mxn(cat.cargos) }} · abonos
                                    {{ mxn(cat.abonos) }}</template
                                >
                            </span>
                        </button>
                        <span class="text-right">
                            <span
                                class="block text-lg font-extrabold whitespace-nowrap tabular-nums"
                                >{{ mxn(cat.total) }}</span
                            >
                        </span>
                        <button
                            type="button"
                            class="mt-1 grid size-7 shrink-0 place-items-center rounded-lg transition hover:bg-background"
                            :aria-label="
                                (prefs.collapsed.includes(cat.key)
                                    ? 'Desplegar '
                                    : 'Plegar ') + cat.label
                            "
                            @click="toggleCollapsed(cat.key)"
                        >
                            <ChevronDown
                                class="size-4 transition-transform duration-200"
                                :class="
                                    prefs.collapsed.includes(cat.key)
                                        ? '-rotate-90'
                                        : ''
                                "
                            />
                        </button>
                    </header>

                    <div
                        v-if="!prefs.collapsed.includes(cat.key)"
                        class="max-h-[30rem] animate-in divide-y divide-border/70 overflow-y-auto overscroll-contain duration-200 fade-in-0 slide-in-from-top-1 motion-reduce:animate-none"
                    >
                        <p
                            v-if="!cat.bundles.length"
                            class="px-4 py-6 text-center text-sm text-muted-foreground"
                        >
                            {{
                                canAct && cat.assignable
                                    ? 'Arrastra aquí movimientos para moverlos a esta categoría.'
                                    : 'Sin movimientos.'
                            }}
                        </p>
                        <div v-for="b in cat.bundles" :key="b.key">
                            <!-- Concepto -->
                            <div
                                class="group/b flex items-center gap-2 px-3 py-2.5"
                                :draggable="canAct"
                                @dragstart="onDragStart($event, b.rows)"
                            >
                                <GripVertical
                                    v-if="canAct"
                                    class="size-4 shrink-0 cursor-grab text-muted-foreground/50"
                                />
                                <input
                                    v-if="canAct"
                                    type="checkbox"
                                    class="size-4 shrink-0 cursor-pointer accent-emerald-600"
                                    :checked="stateOf(b.rows) === 'all'"
                                    :indeterminate="stateOf(b.rows) === 'some'"
                                    :aria-label="'Seleccionar ' + b.label"
                                    @change="toggleRows(b.rows)"
                                />
                                <button
                                    type="button"
                                    class="flex min-w-0 flex-1 items-center gap-2 text-left"
                                    @click="
                                        b.rows.length > 1
                                            ? flip(cat.key + '|' + b.key)
                                            : emit('open', b.rows[0].movement)
                                    "
                                >
                                    <span
                                        v-if="b.rows.length > 1"
                                        class="grid size-7 shrink-0 place-items-center rounded-lg bg-muted text-xs font-extrabold tabular-nums"
                                        >{{ b.rows.length }}</span
                                    >
                                    <span class="min-w-0">
                                        <span
                                            class="block truncate font-semibold"
                                            :title="b.label"
                                            >{{ b.label }}</span
                                        >
                                        <span
                                            v-if="b.rows.length === 1"
                                            class="block truncate text-xs text-muted-foreground tabular-nums"
                                            >{{ b.rows[0].movement.date }} ·
                                            {{
                                                b.rows[0].movement.source ===
                                                'erp'
                                                    ? 'ERP'
                                                    : (b.rows[0].movement
                                                          .account_label ??
                                                      b.rows[0].movement
                                                          .account_slot)
                                            }}
                                            <span
                                                v-if="b.rows[0].split"
                                                class="ml-1 rounded-full bg-teal-100 px-1.5 font-semibold text-teal-800 dark:bg-teal-950/60 dark:text-teal-300"
                                                >parcial</span
                                            ></span
                                        >
                                    </span>
                                    <ChevronDown
                                        v-if="b.rows.length > 1"
                                        class="size-4 shrink-0 text-muted-foreground transition-transform duration-200"
                                        :class="
                                            expanded.has(cat.key + '|' + b.key)
                                                ? 'rotate-180'
                                                : ''
                                        "
                                    />
                                </button>
                                <button
                                    v-if="
                                        canCatalog &&
                                        b.rows[0].movement.source === 'banco' &&
                                        !b.rows[0].movement.redacted
                                    "
                                    type="button"
                                    class="hidden size-8 shrink-0 place-items-center rounded-lg text-muted-foreground transition group-hover/b:grid hover:bg-muted hover:text-foreground"
                                    title="Crear regla del catálogo con este concepto"
                                    @click="
                                        emit('createRule', b.rows[0].movement)
                                    "
                                >
                                    <BookMarked class="size-4" />
                                </button>
                                <span
                                    class="font-bold whitespace-nowrap tabular-nums"
                                    :class="
                                        b.rows[0].side === 'abono'
                                            ? 'text-emerald-700 dark:text-emerald-400'
                                            : ''
                                    "
                                    >{{ mxn(b.total) }}</span
                                >
                            </div>
                            <!-- Movimientos del concepto -->
                            <div
                                v-if="
                                    b.rows.length > 1 &&
                                    expanded.has(cat.key + '|' + b.key)
                                "
                                class="mx-3 mb-2.5 animate-in divide-y divide-border/60 overflow-hidden rounded-xl border bg-muted/20 duration-200 fade-in-0 slide-in-from-top-1"
                            >
                                <div
                                    v-for="r in b.rows"
                                    :key="r.id"
                                    class="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm transition-colors hover:bg-emerald-50/60 dark:hover:bg-emerald-950/20"
                                    :draggable="canAct"
                                    role="button"
                                    tabindex="0"
                                    @dragstart="onDragStart($event, [r])"
                                    @click="emit('open', r.movement)"
                                    @keydown.enter.prevent="
                                        emit('open', r.movement)
                                    "
                                >
                                    <input
                                        v-if="canAct"
                                        type="checkbox"
                                        class="size-4 shrink-0 cursor-pointer accent-emerald-600"
                                        :checked="selected.has(r.fingerprint)"
                                        :aria-label="
                                            'Seleccionar ' +
                                            (r.movement.concept ?? 'movimiento')
                                        "
                                        @click.stop
                                        @change="toggleRows([r])"
                                    />
                                    <span
                                        class="w-20 shrink-0 text-muted-foreground tabular-nums"
                                        >{{ r.movement.date }}</span
                                    >
                                    <span
                                        class="min-w-0 flex-1 truncate"
                                        :title="r.movement.concept ?? ''"
                                        >{{ r.movement.concept ?? '—' }}</span
                                    >
                                    <span
                                        v-if="r.split"
                                        class="rounded-full bg-teal-100 px-1.5 text-xs font-semibold text-teal-800 dark:bg-teal-950/60 dark:text-teal-300"
                                        >parcial</span
                                    >
                                    <span
                                        class="font-semibold whitespace-nowrap tabular-nums"
                                        >{{ mxn(r.amount) }}</span
                                    >
                                </div>
                            </div>
                        </div>
                        <p
                            v-if="cat.hiddenAmount > 0.004"
                            class="flex items-center gap-1.5 px-4 py-2 text-xs text-muted-foreground"
                        >
                            <Lock class="size-3.5" />
                            {{ mxn(cat.hiddenAmount) }} restringido por permisos
                            (sí suma al total).
                        </p>
                    </div>
                </section>
            </TransitionGroup>
            <p
                v-if="!shown.length"
                class="rounded-2xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                No hay categorías visibles. Elige cuáles ver en «Categorías».
            </p>
        </template>

        <!-- Selección: mover y «Ya considerado» -->
        <Transition name="er-fade">
            <div
                v-if="canAct && selected.size"
                class="sticky bottom-3 z-10 flex flex-wrap items-center gap-2 rounded-2xl border bg-background/95 px-4 py-3 shadow-xl backdrop-blur"
            >
                <p class="min-w-0 flex-1 text-sm tabular-nums">
                    <b>{{ selected.size }}</b> seleccionado(s) ·
                    {{ mxn(selectedAmount) }}
                </p>
                <select
                    v-model="moveTarget"
                    class="h-10 max-w-60 rounded-xl border bg-background px-3 text-sm"
                    aria-label="Mover a"
                >
                    <option value="" disabled>Mover a…</option>
                    <option v-for="t in targets" :key="t.key" :value="t.key">
                        {{ t.label }}
                    </option>
                </select>
                <button
                    type="button"
                    class="inline-flex h-10 items-center gap-1.5 rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white transition hover:bg-emerald-800 disabled:opacity-40"
                    :disabled="!moveTarget"
                    @click="moveSelected"
                >
                    <MoveRight class="size-4" /> Mover
                </button>
                <button
                    type="button"
                    class="inline-flex h-10 items-center gap-1.5 rounded-xl border px-4 text-sm font-bold transition hover:border-teal-400 hover:bg-teal-50 dark:hover:bg-teal-950/30"
                    @click="emit('consider', [...selected])"
                >
                    <CheckCircle2 class="size-4 text-teal-600" /> Ya considerado
                </button>
                <button
                    type="button"
                    class="grid size-10 place-items-center rounded-xl text-muted-foreground transition hover:bg-muted hover:text-foreground"
                    aria-label="Quitar selección"
                    @click="selected = new Set()"
                >
                    <X class="size-4" />
                </button>
            </div>
        </Transition>

        <!-- Configurar categorías -->
        <ErModal
            :open="configOpen"
            title="Categorías del cuadre"
            description="Elige qué ver y en qué orden. Se guarda en este navegador."
            :icon="Settings2"
            size="md"
            @update:open="(v) => (configOpen = v)"
        >
            <ul class="space-y-1.5">
                <li
                    v-for="(c, i) in configList"
                    :key="c.key"
                    class="flex items-center gap-2 rounded-xl border px-3 py-2"
                    :class="prefs.hidden.includes(c.key) ? 'opacity-50' : ''"
                >
                    <span
                        class="grid size-7 shrink-0 place-items-center rounded-lg"
                        :class="KIND[c.kind].chip"
                    >
                        <component :is="KIND[c.kind].icon" class="size-3.5" />
                    </span>
                    <span class="min-w-0 flex-1 truncate">{{ c.label }}</span>
                    <span class="text-sm text-muted-foreground tabular-nums">{{
                        mxn(c.total)
                    }}</span>
                    <button
                        type="button"
                        class="grid size-8 place-items-center rounded-lg hover:bg-muted disabled:opacity-30"
                        :disabled="i === 0"
                        :aria-label="'Subir ' + c.label"
                        @click="shift(c.key, -1)"
                    >
                        <ArrowUp class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="grid size-8 place-items-center rounded-lg hover:bg-muted disabled:opacity-30"
                        :disabled="i === configList.length - 1"
                        :aria-label="'Bajar ' + c.label"
                        @click="shift(c.key, 1)"
                    >
                        <ArrowDown class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="grid size-8 place-items-center rounded-lg hover:bg-muted"
                        :aria-label="
                            (prefs.hidden.includes(c.key)
                                ? 'Mostrar '
                                : 'Ocultar ') + c.label
                        "
                        @click="toggleHidden(c.key)"
                    >
                        <EyeOff
                            v-if="prefs.hidden.includes(c.key)"
                            class="size-4"
                        />
                        <Eye v-else class="size-4" />
                    </button>
                </li>
            </ul>
            <template #footer>
                <label class="mr-auto inline-flex items-center gap-2 text-sm">
                    <input
                        v-model="prefs.showEmpty"
                        type="checkbox"
                        class="size-4 accent-emerald-600"
                    />
                    Mostrar categorías vacías (para mover a ellas)
                </label>
                <button
                    type="button"
                    class="inline-flex h-10 items-center gap-1.5 rounded-xl border px-3 text-sm font-semibold transition hover:bg-muted"
                    @click="resetPrefs"
                >
                    <RotateCcw class="size-4" /> Restablecer
                </button>
            </template>
        </ErModal>
    </div>
</template>
