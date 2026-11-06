<script setup lang="ts">
import {
    CheckCheck,
    FolderInput,
    CornerDownRight,
    Loader2,
    PanelRightOpen,
    SkipForward,
    Sparkles,
    Tags,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { SEGMENT_FILTERS } from './movementFilters';
import { mxn, num } from './types';
import type { Dataset, Movement } from './types';

const props = defineProps<{
    movements: Movement[];
    c: Dataset['conciliation'];
    canClassify: boolean;
    accepting: boolean;
    lockReason?: string | null;
}>();

const emit = defineEmits<{
    accept: [fingerprints: string[], ask: boolean];
    classify: [fingerprints: string[]];
    open: [m: Movement];
    showAll: [];
    /** Sin estados de cuenta: llevar a la pestaña Fuentes. */
    goSources: [];
}>();

/** Pendientes, el de mayor importe primero: lo que más mueve el resultado va antes. */
const pending = computed(() =>
    props.movements
        .filter(SEGMENT_FILTERS.pendientes.test)
        .slice()
        .sort(
            (a, b) =>
                num(b.cargo) + num(b.abono) - (num(a.cargo) + num(a.abono)),
        ),
);

const skipped = ref<Set<string>>(new Set());
const current = computed(
    () =>
        pending.value.find((m) => !skipped.value.has(m.fingerprint)) ??
        pending.value[0] ??
        null,
);
const position = computed(() =>
    current.value ? pending.value.indexOf(current.value) + 1 : 0,
);
const upcoming = computed(() =>
    pending.value
        .filter((m) => m !== current.value && !skipped.value.has(m.fingerprint))
        .slice(0, 4),
);

/** % revisado; null cuando no hay movimientos en el periodo (no se presume 100 %). */
const reviewed = computed<number | null>(() => {
    const total = props.c.movimientos_en_periodo || 0;

    return total === 0
        ? null
        : Math.max(
              0,
              Math.round(((total - props.c.pendientes.count) / total) * 100),
          );
});
/** No llegó nada del banco: el estado vacío es neutral, no "todo listo". */
const noData = computed(
    () =>
        (props.c.movimientos_en_periodo || 0) === 0 &&
        props.c.pendientes.count === 0,
);

/** Agrupa por sugerencia para resolver en bloque lo que el sistema ya reconoce. */
const groups = computed(() => {
    const map = new Map<
        string,
        { key: string; label: string; items: Movement[] }
    >();

    for (const m of pending.value) {
        const key = m.suggested_treatment ?? '__none';
        const g = map.get(key) ?? {
            key,
            label: m.suggested_label ?? 'Sin sugerencia: requiere criterio',
            items: [],
        };
        g.items.push(m);
        map.set(key, g);
    }

    const list = [...map.values()].sort(
        (a, b) => b.items.length - a.items.length,
    );
    const max = Math.max(1, ...list.map((g) => g.items.length));

    return list.map((g) => ({ ...g, width: (g.items.length / max) * 100 }));
});

function skip(): void {
    if (!current.value) {
        return;
    }

    const next = new Set(skipped.value);
    next.add(current.value.fingerprint);

    // Si ya se saltaron todos, vuelve a empezar la vuelta.
    if (next.size >= pending.value.length) {
        next.clear();
    }

    skipped.value = next;
}

/** Con el atajo de teclado se pide confirmación (ask = true); el botón es explícito. */
function accept(ask = false): void {
    if (current.value?.suggested_treatment && !props.accepting) {
        emit('accept', [current.value.fingerprint], ask);
    }
}

function classify(): void {
    if (current.value) {
        emit('classify', [current.value.fingerprint]);
    }
}

// Atajos de la cola: sólo con el foco DENTRO de la tarjeta (no en toda la ventana).
// A acepta (con confirmación), C clasifica, S o → salta, D abre el detalle.
function onKey(e: KeyboardEvent): void {
    const t = e.target as HTMLElement | null;

    if (
        !current.value ||
        e.ctrlKey ||
        e.metaKey ||
        e.altKey ||
        (t &&
            (/^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName) ||
                t.closest('[role="tablist"]')))
    ) {
        return;
    }

    const k = e.key.toLowerCase();
    let handled = true;

    if (k === 'a' && props.canClassify) {
        accept(true);
    } else if (k === 'c' && props.canClassify) {
        classify();
    } else if (k === 's' || e.key === 'ArrowRight') {
        skip();
    } else if (k === 'd') {
        emit('open', current.value);
    } else {
        handled = false;
    }

    if (handled) {
        e.preventDefault();
        e.stopPropagation();
    }
}
</script>

<template>
    <div
        v-if="noData"
        class="flex flex-col items-center gap-3 rounded-2xl border border-dashed bg-card px-6 py-16 text-center"
    >
        <span
            class="flex size-14 items-center justify-center rounded-full bg-muted text-muted-foreground"
        >
            <FolderInput class="size-7" />
        </span>
        <p class="text-lg font-extrabold">
            Aún no hay estados de cuenta cargados
        </p>
        <p class="max-w-md text-sm text-muted-foreground">
            Sin datos todavía: cuando cargues los estados de cuenta del banco,
            aquí aparecerán los movimientos que necesitan una clasificación.
        </p>
        <button
            type="button"
            class="mt-1 inline-flex h-10 items-center gap-2 rounded-xl border px-4 text-sm font-semibold transition hover:border-emerald-400 hover:bg-emerald-50 focus-visible:ring-3 focus-visible:ring-emerald-500/30 focus-visible:outline-none dark:hover:bg-emerald-950/30"
            @click="emit('goSources')"
        >
            <FolderInput class="size-4" /> Ir a Fuentes
        </button>
    </div>

    <div
        v-else-if="!pending.length"
        class="flex flex-col items-center gap-3 rounded-2xl border bg-card px-6 py-16 text-center shadow-soft-xs"
    >
        <span
            class="flex size-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300"
        >
            <CheckCheck class="size-7" />
        </span>
        <p class="text-lg font-extrabold">No hay movimientos por revisar</p>
        <p class="max-w-md text-sm text-muted-foreground">
            Todo lo que llegó del banco tiene una clasificación. Sigue con los
            ajustes contables y las validaciones de la ruta de cierre.
        </p>
    </div>

    <div
        v-else
        class="grid gap-4 xl:grid-cols-[minmax(0,1.6fr)_minmax(320px,1fr)]"
    >
        <!-- Movimiento en turno -->
        <section
            class="relative overflow-hidden rounded-2xl border bg-card shadow-soft-xs outline-none focus-visible:ring-3 focus-visible:ring-emerald-500/30"
            aria-label="Movimiento en turno. Atajos con el foco aquí: A aceptar, C clasificar, S saltar, D ver detalle"
            tabindex="0"
            @keydown="onKey"
        >
            <div class="h-1.5 w-full bg-muted">
                <div
                    class="h-full bg-emerald-500 transition-[width] duration-700"
                    :style="{ width: `${reviewed ?? 0}%` }"
                />
            </div>
            <div class="p-5 md:p-6">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p class="text-xs text-muted-foreground">
                        <b class="text-foreground">{{
                            reviewed === null ? '—' : `${reviewed}% revisado`
                        }}</b>
                        · quedan
                        {{ c.pendientes.count.toLocaleString('es-MX') }} por
                        {{ mxn(c.pendientes.amount) }} que hoy no suman
                    </p>
                    <p class="text-xs text-muted-foreground tabular-nums">
                        {{ position }} de {{ pending.length }} · de mayor a
                        menor importe
                    </p>
                    <p class="w-full text-[11px] text-muted-foreground">
                        Atajos de teclado: haz clic dentro de esta tarjeta y usa
                        A, C, S o D.
                    </p>
                </div>

                <Transition name="er-slide" mode="out-in">
                    <div v-if="current" :key="current.fingerprint" class="mt-4">
                        <div
                            class="flex flex-wrap items-start justify-between gap-4"
                        >
                            <div class="min-w-0">
                                <p class="text-xs text-muted-foreground">
                                    {{ current.date }} ·
                                    {{
                                        current.source === 'erp'
                                            ? 'ERP'
                                            : (current.account_label ??
                                              current.account_slot)
                                    }}
                                    · fila {{ current.source_row }}
                                    <template
                                        v-if="
                                            current.branch_name ||
                                            current.sucursal_raw
                                        "
                                    >
                                        ·
                                        {{
                                            current.branch_name ??
                                            current.sucursal_raw
                                        }}</template
                                    >
                                </p>
                                <h3
                                    class="mt-1 text-xl leading-snug font-extrabold"
                                >
                                    {{
                                        current.concept ??
                                        'Movimiento sin concepto'
                                    }}
                                </h3>
                                <p
                                    v-if="
                                        current.beneficiary || current.reference
                                    "
                                    class="mt-1 line-clamp-2 text-sm text-muted-foreground"
                                >
                                    {{
                                        current.beneficiary ?? current.reference
                                    }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p
                                    v-if="num(current.cargo)"
                                    class="text-2xl font-extrabold tabular-nums"
                                >
                                    {{ mxn(current.cargo) }}
                                </p>
                                <p
                                    v-if="num(current.abono)"
                                    class="text-2xl font-extrabold text-sky-700 tabular-nums dark:text-sky-400"
                                >
                                    +{{ mxn(current.abono) }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ num(current.cargo) ? 'cargo' : 'abono' }}
                                </p>
                            </div>
                        </div>

                        <div
                            class="mt-5 rounded-xl border p-4"
                            :class="
                                current.suggested_treatment
                                    ? 'border-amber-300 bg-amber-50/70 dark:border-amber-800 dark:bg-amber-950/25'
                                    : 'border-dashed'
                            "
                        >
                            <p
                                class="flex items-center gap-1.5 text-sm font-bold"
                            >
                                <Sparkles
                                    class="size-4"
                                    :class="
                                        current.suggested_treatment
                                            ? 'text-amber-500'
                                            : 'text-muted-foreground'
                                    "
                                />
                                {{
                                    current.suggested_treatment
                                        ? `El sistema sugiere: ${current.suggested_label}`
                                        : 'No hay sugerencia: decide tú el tratamiento'
                                }}
                            </p>
                            <p
                                v-if="current.rule_reason"
                                class="mt-1 text-xs leading-relaxed text-muted-foreground"
                            >
                                {{ current.rule_reason }}
                            </p>
                            <p
                                v-if="current.reconciliation_message"
                                class="mt-1 text-xs leading-relaxed text-muted-foreground"
                            >
                                {{ current.reconciliation_message }}
                            </p>
                        </div>

                        <div class="mt-5 flex flex-wrap items-center gap-2">
                            <button
                                v-if="
                                    canClassify && current.suggested_treatment
                                "
                                type="button"
                                class="inline-flex h-11 items-center gap-2 rounded-xl bg-emerald-700 px-4 text-sm font-semibold text-white shadow-[0_6px_16px_-8px_rgb(4_120_87/0.8)] transition hover:-translate-y-px hover:bg-emerald-800 disabled:opacity-60 dark:bg-emerald-500 dark:text-emerald-950 dark:hover:bg-emerald-400"
                                :disabled="accepting"
                                @click="accept()"
                            >
                                <Loader2
                                    v-if="accepting"
                                    class="size-4 animate-spin"
                                />
                                <Sparkles v-else class="size-4" />
                                Aceptar sugerencia
                                <kbd
                                    class="rounded bg-white/20 px-1.5 text-[10px]"
                                    >A</kbd
                                >
                            </button>
                            <button
                                v-if="canClassify"
                                type="button"
                                class="inline-flex h-11 items-center gap-2 rounded-xl border px-4 text-sm font-semibold transition hover:border-emerald-400 hover:bg-emerald-50 dark:hover:bg-emerald-950/30"
                                :class="
                                    current.suggested_treatment
                                        ? ''
                                        : 'border-emerald-600 text-emerald-800 dark:text-emerald-300'
                                "
                                @click="classify"
                            >
                                <Tags class="size-4" />
                                {{
                                    current.suggested_treatment
                                        ? 'Clasificar distinto'
                                        : 'Clasificar'
                                }}
                                <kbd class="rounded bg-muted px-1.5 text-[10px]"
                                    >C</kbd
                                >
                            </button>
                            <button
                                type="button"
                                class="inline-flex h-11 items-center gap-2 rounded-xl px-3 text-sm font-semibold text-muted-foreground transition hover:bg-muted hover:text-foreground"
                                @click="current && emit('open', current)"
                            >
                                <PanelRightOpen class="size-4" /> Ver detalle
                                <kbd class="rounded bg-muted px-1.5 text-[10px]"
                                    >D</kbd
                                >
                            </button>
                            <button
                                type="button"
                                class="ml-auto inline-flex h-11 items-center gap-2 rounded-xl px-3 text-sm font-semibold text-muted-foreground transition hover:bg-muted hover:text-foreground"
                                @click="skip"
                            >
                                Saltar <SkipForward class="size-4" />
                            </button>
                        </div>
                        <p
                            v-if="!canClassify"
                            class="mt-3 text-xs text-muted-foreground"
                        >
                            {{
                                lockReason ??
                                'Tu rol puede consultar, pero no clasificar. Pide a alguien con permiso de clasificación que lo resuelva.'
                            }}
                        </p>
                    </div>
                </Transition>

                <div v-if="upcoming.length" class="mt-6 border-t pt-4">
                    <p class="mb-2 text-xs font-semibold text-muted-foreground">
                        Siguen
                    </p>
                    <ul class="space-y-1">
                        <li v-for="m in upcoming" :key="m.fingerprint">
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-xs transition hover:bg-muted"
                                @click="emit('open', m)"
                            >
                                <CornerDownRight
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                />
                                <span class="min-w-0 flex-1 truncate">{{
                                    m.concept ?? '—'
                                }}</span>
                                <span
                                    v-if="m.suggested_label"
                                    class="hidden truncate text-amber-700 sm:inline dark:text-amber-400"
                                    >{{ m.suggested_label }}</span
                                >
                                <span
                                    class="shrink-0 font-semibold tabular-nums"
                                    >{{
                                        mxn(num(m.cargo) ? m.cargo : m.abono)
                                    }}</span
                                >
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </section>

        <!-- Resolver en bloque -->
        <section
            class="rounded-2xl border bg-card p-5 shadow-soft-xs"
            aria-label="Pendientes agrupados por sugerencia"
        >
            <h3 class="text-base font-extrabold">Resolver en bloque</h3>
            <p class="mt-0.5 text-xs text-muted-foreground">
                Pendientes agrupados por lo que el sistema propone. Revisa un
                par y acepta el grupo completo.
            </p>
            <ul class="mt-4 space-y-2">
                <li
                    v-for="g in groups"
                    :key="g.key"
                    class="rounded-xl border p-3 transition hover:border-foreground/20"
                >
                    <div class="flex items-center justify-between gap-2">
                        <p class="min-w-0 truncate text-sm font-semibold">
                            {{ g.label }}
                        </p>
                        <span class="shrink-0 text-xs font-bold tabular-nums">{{
                            g.items.length
                        }}</span>
                    </div>
                    <div
                        class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            class="h-full rounded-full transition-[width] duration-500"
                            :class="
                                g.key === '__none'
                                    ? 'bg-slate-400'
                                    : 'bg-amber-500'
                            "
                            :style="{ width: `${g.width}%` }"
                        />
                    </div>
                    <div v-if="canClassify" class="mt-2 flex flex-wrap gap-1.5">
                        <button
                            v-if="g.key !== '__none'"
                            type="button"
                            class="rounded-lg bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-900 transition hover:bg-amber-200 dark:bg-amber-950/60 dark:text-amber-200 dark:hover:bg-amber-900/60"
                            :disabled="accepting"
                            @click="
                                emit(
                                    'accept',
                                    g.items.map((m) => m.fingerprint),
                                    true,
                                )
                            "
                        >
                            Aceptar los {{ g.items.length }}
                        </button>
                        <button
                            type="button"
                            class="rounded-lg px-2.5 py-1 text-xs font-semibold text-muted-foreground transition hover:bg-muted hover:text-foreground"
                            @click="
                                emit(
                                    'classify',
                                    g.items.map((m) => m.fingerprint),
                                )
                            "
                        >
                            Clasificar el grupo
                        </button>
                    </div>
                </li>
            </ul>
            <button
                type="button"
                class="mt-4 w-full rounded-xl border border-dashed px-3 py-2 text-xs font-semibold text-muted-foreground transition hover:border-foreground/30 hover:text-foreground"
                @click="emit('showAll')"
            >
                Ver los {{ pending.length }} pendientes en la tabla
            </button>
        </section>
    </div>
</template>
