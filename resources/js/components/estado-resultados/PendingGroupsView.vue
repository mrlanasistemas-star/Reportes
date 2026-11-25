<script setup lang="ts">
/**
 * PendingGroupsView — pendientes agrupados por concepto (vista «Agrupados» de Control de
 * movimientos). Cada tarjeta: concepto, nº de movimientos, suma, importe típico, chips de
 * sucursales, sugerencia del sistema y botones «Ver movimientos» / «Clasificar grupo».
 *
 * Los grupos los calcula el servidor (GET /control-movimientos/grupos) con el mismo dataset
 * de la lista; se recargan cuando cambian los movimientos (tras clasificar o procesar).
 *
 * Props: movements (para expandir y para recargar), url, scopeQuery, treatments, canClassify,
 * canCatalog, lockReason, periodId, canProcess, process.
 * Emits: open (detalle de un movimiento), reload (recargar dataset), open-catalog (prefill).
 */
import {
    ArrowDownLeft,
    ArrowUpRight,
    ChevronDown,
    Landmark,
    Layers,
    Loader2,
    Sparkles,
    Tags,
} from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { usePendingGroups } from '@/composables/estado-resultados/usePendingGroups';
import type { RuleDraft } from './conceptCatalog';
import GroupClassifyDialog from './GroupClassifyDialog.vue';
import type { ControlledMovement, PendingGroup } from './movementControl';
import { mxn } from './types';
import type { TreatmentMeta } from './types';

const props = defineProps<{
    movements: ControlledMovement[];
    url: (path: string) => string;
    scopeQuery: () => string;
    treatments: TreatmentMeta[];
    canClassify: boolean;
    canCatalog: boolean;
    lockReason?: string | null;
    periodId: number;
    canProcess: boolean;
    process: () => Promise<void>;
}>();
const emit = defineEmits<{
    open: [fingerprint: string];
    reload: [];
    'open-catalog': [draft: Partial<RuleDraft>];
}>();

// ── Estado (antes de cualquier watch) ─────────────────────────────────
const pending = usePendingGroups({
    url: props.url,
    scopeQuery: props.scopeQuery,
});
/** Grupo expandido («Ver movimientos»). */
const expanded = ref<string | null>(null);
/** Grupo que se clasifica. */
const active = ref<PendingGroup | null>(null);
const dialogOpen = ref(false);
const groups = computed(() => pending.data.value?.groups ?? []);
/** Mayor conteo (escala de la barra de cada tarjeta). */
const maxCount = computed(() =>
    Math.max(1, ...groups.value.map((g) => g.count)),
);
/** Movimientos por huella (para expandir un grupo sin pedir nada al servidor). */
const byFp = computed(
    () => new Map(props.movements.map((m) => [m.fingerprint, m])),
);
const canAct = computed(
    () => (props.canClassify || props.canCatalog) && !props.lockReason,
);

/** Movimientos del grupo expandido (máx. 50 en pantalla). */
function rowsOf(g: PendingGroup): ControlledMovement[] {
    return g.fingerprints
        .slice(0, 50)
        .map((fp) => byFp.value.get(fp))
        .filter((m): m is ControlledMovement => !!m);
}

function classify(g: PendingGroup): void {
    active.value = g;
    dialogOpen.value = true;
}

onMounted(() => void pending.load());
// Tras clasificar/procesar el dataset cambia: los grupos se recalculan.
watch(
    () => props.movements,
    () => void pending.load(),
);
</script>

<template>
    <div class="space-y-3 p-4">
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span
                class="inline-flex items-center gap-1.5 rounded-full bg-amber-100 px-2.5 py-1 font-bold text-amber-800 dark:bg-amber-950/60 dark:text-amber-300"
            >
                <Layers class="size-3.5" />
                {{ pending.data.value?.total_groups ?? 0 }} grupos ·
                {{
                    (pending.data.value?.total_pending ?? 0).toLocaleString(
                        'es-MX',
                    )
                }}
                pendientes ·
                {{ mxn(pending.data.value?.pending_amount ?? 0) }}
            </span>
            <span
                v-if="pending.data.value?.redacted"
                class="text-muted-foreground"
            >
                {{ pending.data.value.redacted }} restringidos sin agrupar
            </span>
            <Loader2
                v-if="pending.loading.value"
                class="size-3.5 animate-spin text-muted-foreground"
            />
        </div>

        <p
            v-if="pending.error.value"
            class="rounded-lg bg-rose-50 p-2 text-sm text-rose-800 dark:bg-rose-950/30 dark:text-rose-300"
        >
            {{ pending.error.value }}
        </p>
        <p
            v-else-if="!pending.loading.value && !groups.length"
            class="p-6 text-center text-sm text-muted-foreground"
        >
            Sin pendientes por agrupar.
        </p>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
            <article
                v-for="g in groups"
                :key="g.key"
                class="flex flex-col gap-2.5 rounded-2xl border bg-card p-3.5 shadow-soft-xs"
                :class="expanded === g.key ? 'md:col-span-2 xl:col-span-3' : ''"
            >
                <header class="flex items-start gap-3">
                    <span
                        class="grid size-12 shrink-0 place-items-center rounded-2xl text-lg font-extrabold tabular-nums"
                        :class="
                            g.direction === 'abono'
                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                                : 'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300'
                        "
                        :title="g.count + ' movimientos'"
                        >{{ g.count }}</span
                    >
                    <div class="min-w-0 flex-1">
                        <p
                            class="truncate text-sm font-extrabold"
                            :title="g.sample_concept"
                        >
                            {{ g.label }}
                        </p>
                        <p
                            class="flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground"
                        >
                            <span class="inline-flex items-center gap-0.5">
                                <component
                                    :is="
                                        g.direction === 'abono'
                                            ? ArrowDownLeft
                                            : ArrowUpRight
                                    "
                                    class="size-3"
                                />
                                {{
                                    g.direction === 'abono'
                                        ? 'Abonos'
                                        : 'Cargos'
                                }}
                            </span>
                            <span
                                class="font-bold text-foreground tabular-nums"
                                >{{ mxn(g.total) }}</span
                            >
                            <span
                                v-if="g.typical_count > 1"
                                class="tabular-nums"
                                >típico {{ mxn(g.typical_amount) }} ×{{
                                    g.typical_count
                                }}</span
                            >
                        </p>
                    </div>
                    <span
                        v-if="g.credit_contribution"
                        class="shrink-0 rounded-full bg-sky-100 p-1 text-sky-700 dark:bg-sky-950 dark:text-sky-300"
                        title="Aportación a crédito"
                    >
                        <Landmark class="size-3.5" />
                    </span>
                </header>

                <div
                    class="h-1.5 overflow-hidden rounded-full bg-muted"
                    aria-hidden="true"
                >
                    <div
                        class="h-full rounded-full bg-amber-500"
                        :style="{ width: (g.count / maxCount) * 100 + '%' }"
                    />
                </div>

                <div class="flex flex-wrap gap-1">
                    <span
                        v-for="b in g.branches.slice(0, 5)"
                        :key="b.name"
                        class="rounded-full border px-2 py-0.5 text-xs font-semibold"
                    >
                        {{ b.name }}
                        <span class="tabular-nums opacity-70">{{
                            b.count
                        }}</span>
                    </span>
                    <span
                        v-if="g.branches.length > 5"
                        class="rounded-full px-2 py-0.5 text-xs text-muted-foreground"
                        >+{{ g.branches.length - 5 }}</span
                    >
                </div>

                <p
                    v-if="g.suggested"
                    class="inline-flex items-center gap-1 text-xs font-semibold text-violet-700 dark:text-violet-300"
                >
                    <Sparkles class="size-3" /> {{ g.suggested.label }}
                    <span class="opacity-70">({{ g.suggested.count }})</span>
                </p>

                <div class="mt-auto flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-xl border px-2.5 py-1.5 text-sm font-semibold hover:bg-muted"
                        :aria-expanded="expanded === g.key"
                        @click="expanded = expanded === g.key ? null : g.key"
                    >
                        <ChevronDown
                            class="size-3.5 transition"
                            :class="expanded === g.key ? 'rotate-180' : ''"
                        />
                        Ver movimientos
                    </button>
                    <button
                        v-if="canAct"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-xl bg-foreground px-2.5 py-1.5 text-sm font-semibold text-background"
                        @click="classify(g)"
                    >
                        <Tags class="size-3.5" /> Clasificar grupo
                    </button>
                </div>

                <ul
                    v-if="expanded === g.key"
                    class="divide-y rounded-xl border text-sm"
                >
                    <li
                        v-for="m in rowsOf(g)"
                        :key="m.fingerprint"
                        class="flex cursor-pointer flex-wrap items-center gap-x-3 gap-y-0.5 px-3 py-1.5 hover:bg-muted/50"
                        @click="emit('open', m.fingerprint)"
                    >
                        <span class="text-muted-foreground tabular-nums">{{
                            m.date ?? '—'
                        }}</span>
                        <span class="min-w-0 flex-1 truncate">{{
                            m.concept
                        }}</span>
                        <span class="text-muted-foreground">{{
                            m.branch_name || m.sucursal_raw || '—'
                        }}</span>
                        <span class="font-semibold tabular-nums">{{
                            mxn(Number(m.cargo) || m.abono)
                        }}</span>
                    </li>
                    <li
                        v-if="g.count > 50"
                        class="px-3 py-1.5 text-muted-foreground"
                    >
                        …y {{ g.count - 50 }} más
                    </li>
                </ul>
            </article>
        </div>

        <GroupClassifyDialog
            v-model:open="dialogOpen"
            :group="active"
            :treatments="treatments"
            :can-classify="canClassify"
            :can-catalog="canCatalog"
            :lock-reason="lockReason"
            :period-id="periodId"
            :pending="pending"
            :can-process="canProcess"
            :process="process"
            @done="emit('reload')"
            @open-catalog="(d) => emit('open-catalog', d)"
        />
    </div>
</template>
