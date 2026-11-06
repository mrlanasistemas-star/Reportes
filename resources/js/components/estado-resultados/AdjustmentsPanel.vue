<script lang="ts">
interface DraftValues {
    /** AdjustmentState ('sin_informacion' | 'confirmado_cero' | 'capturado'). */
    state: string;
    amount: string;
    notes: string;
}
/** Borrador de un concepto + el valor guardado del que partió. */
interface Draft extends DraftValues {
    base: DraftValues;
}

/**
 * Borradores a nivel módulo: sobreviven a cambiar de pestaña (el panel se desmonta)
 * y a recargas del dataset. Se separan por `draftKey` (periodo/alcance). Es un objeto
 * plano: el panel lo envuelve con reactive() y escribe directo en él.
 */
const STORE: Record<string, Record<string, Draft>> = {};
</script>

<script setup lang="ts">
import { AlertTriangle, History, Loader2, Save } from 'lucide-vue-next';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    watch,
} from 'vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { mxn } from './types';
import type { Adjustment, AdjustmentState } from './types';

const props = defineProps<{
    adjustments: Adjustment[];
    canEdit: boolean;
    /** Motivo de solo lectura (versión aprobada / histórica). */
    lockReason?: string | null;
    savingKey: string | null;
    logs: Array<{
        label: string;
        old_state: string | null;
        old_amount: string | null;
        new_state: string;
        new_amount: string | null;
        notes: string | null;
        user: string | null;
        at: string;
    }>;
    logsLoading: boolean;
    /** Separa los borradores por periodo/alcance (p. ej. "21:CONSOLIDADO"). */
    draftKey?: string;
}>();

const emit = defineEmits<{
    save: [
        payload: {
            concept: string;
            state: AdjustmentState;
            amount: string | null;
            notes: string | null;
        },
    ];
    loadLogs: [];
}>();

const drafts = reactive<Record<string, Draft>>(
    (STORE[props.draftKey ?? 'default'] ??= {}),
);
const showLogs = ref(false);
/** Concepto cuyo importe se intentó guardar vacío (muestra el error en línea). */
const touched = reactive<Record<string, boolean>>({});

const serverOf = (a: Adjustment): Draft['base'] => ({
    state: a.state,
    amount: a.state === 'capturado' ? (a.amount ?? '') : '',
    notes: a.notes ?? '',
});
const sameBase = (x: Draft['base'], y: Draft['base']) =>
    x.state === y.state &&
    Number(x.amount || 0) === Number(y.amount || 0) &&
    x.notes === y.notes;

/**
 * Al recargar el dataset sólo se reinicia el borrador de un concepto cuyo valor guardado
 * cambió (p. ej. el que se acaba de guardar) o que no tenía cambios; los borradores
 * pendientes de los demás conceptos se conservan.
 */
function sync(): void {
    for (const a of props.adjustments) {
        const server = serverOf(a);
        const d = drafts[a.key];

        if (!d || !sameBase(d.base, server) || !dirty(a)) {
            drafts[a.key] = { ...server, base: server };
        }
    }
}
watch(() => props.adjustments, sync, { immediate: true });
watch(showLogs, (v) => {
    if (v) {
        emit('loadLogs');
    }
});
// Después de guardar, el historial abierto se vuelve a leer (no se queda en "Sin cambios").
watch(
    () => props.savingKey,
    (now, before) => {
        if (before && !now && showLogs.value) {
            emit('loadLogs');
        }
    },
);

const pendingCount = computed(
    () => props.adjustments.filter((a) => dirty(a)).length,
);

/** Aviso del navegador si se cierra o recarga la página con borradores sin guardar. */
function beforeUnload(e: BeforeUnloadEvent): void {
    if (pendingCount.value) {
        e.preventDefault();
    }
}
onMounted(() => window.addEventListener('beforeunload', beforeUnload));
onBeforeUnmount(() => window.removeEventListener('beforeunload', beforeUnload));

function amountError(a: Adjustment): string | null {
    const d = drafts[a.key];

    if (!d || d.state !== 'capturado') {
        return null;
    }

    const n = Number(d.amount);

    return d.amount === '' || !Number.isFinite(n) || n <= 0
        ? 'Escribe un importe mayor a $0. Si no hubo importe, elige “Confirmado $0”.'
        : null;
}

const STATES: Array<{ key: AdjustmentState; label: string }> = [
    { key: 'sin_informacion', label: 'Sin información' },
    { key: 'confirmado_cero', label: 'Confirmado $0' },
    { key: 'capturado', label: 'Capturado' },
];
const STATE_LABEL: Record<string, string> = {
    sin_informacion: 'Sin información',
    confirmado_cero: 'Confirmado $0',
    capturado: 'Capturado',
};

function dirty(a: Adjustment): boolean {
    const d = drafts[a.key];

    if (!d) {
        return false;
    }

    return (
        d.state !== a.state ||
        (d.state === 'capturado' &&
            Number(d.amount) !== Number(a.amount ?? 0)) ||
        (d.notes ?? '') !== (a.notes ?? '')
    );
}

function save(a: Adjustment): void {
    const d = drafts[a.key];
    touched[a.key] = true;

    if (amountError(a)) {
        document.getElementById(`er-adj-amount-${a.key}`)?.focus();

        return;
    }

    emit('save', {
        concept: a.key,
        state: d.state as AdjustmentState,
        amount: d.state === 'capturado' ? d.amount : null,
        notes: d.notes || null,
    });
}

const SECTION_ORDER = ['da', 'financiero', 'isr', 'flujo'];
const sections = computed(() =>
    SECTION_ORDER.concat(
        props.adjustments
            .map((a) => a.section)
            .filter(
                (s, i, arr) =>
                    !SECTION_ORDER.includes(s) && arr.indexOf(s) === i,
            ),
    )
        .map((key) => ({
            key,
            label: SECTION_LABEL[key] ?? key,
            items: props.adjustments.filter((a) => a.section === key),
        }))
        .filter((s) => s.items.length),
);

const SECTION_LABEL: Record<string, string> = {
    da: 'Afecta EBIT',
    financiero: 'Afecta EBT',
    isr: 'Afecta utilidad neta',
    flujo: 'Flujo (fuera del ER)',
};
</script>

<template>
    <div class="space-y-3">
        <p
            v-if="lockReason"
            class="rounded-2xl border bg-muted/50 p-3 text-xs font-medium"
        >
            {{ lockReason }}
        </p>
        <p
            v-if="pendingCount && canEdit"
            role="status"
            class="flex items-center gap-2 rounded-2xl border border-sky-300 bg-sky-50 p-3 text-xs font-semibold text-sky-900 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-200"
        >
            <AlertTriangle class="size-4 shrink-0" />
            Tienes {{ pendingCount }}
            {{
                pendingCount === 1
                    ? 'ajuste sin guardar'
                    : 'ajustes sin guardar'
            }}. Se conservan si cambias de pestaña; usa “Guardar” en cada
            concepto para que cuenten en el reporte.
        </p>
        <div
            class="rounded-2xl border border-amber-200 bg-amber-50/70 p-3 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
        >
            Un concepto en <b>“Sin información”</b> no se asume en $0: el
            EBIT/EBT/Utilidad que dependa de él se marca como <b>preliminar</b>.
            Si confirmaste que no hubo importe, elige <b>“Confirmado $0”</b>.
            Captura importes en positivo; la sección define si suma o resta.
        </div>

        <section v-for="sec in sections" :key="sec.key" class="space-y-2">
            <h3 class="flex items-center gap-2 text-sm font-extrabold">
                {{ sec.label }}
                <span class="text-xs font-medium text-muted-foreground"
                    >{{
                        sec.items.filter((a) => a.state === 'sin_informacion')
                            .length
                    }}
                    sin información de {{ sec.items.length }}</span
                >
            </h3>
            <div class="grid gap-3 lg:grid-cols-2 2xl:grid-cols-3">
                <div
                    v-for="a in sec.items"
                    :key="a.key"
                    class="space-y-2.5 rounded-2xl border bg-card p-4 shadow-soft-xs"
                    :class="
                        a.state === 'sin_informacion'
                            ? 'border-amber-200 dark:border-amber-900'
                            : ''
                    "
                >
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="text-sm font-bold">
                                {{ a.label }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ SECTION_LABEL[a.section] ?? a.section }} ·
                                {{ a.effect }}
                            </p>
                        </div>
                        <p
                            class="text-right text-sm font-extrabold tabular-nums"
                        >
                            {{
                                a.state === 'sin_informacion'
                                    ? '—'
                                    : mxn(a.amount)
                            }}
                        </p>
                    </div>

                    <template v-if="drafts[a.key]">
                        <div
                            class="inline-flex rounded-xl border bg-muted/40 p-0.5"
                            role="radiogroup"
                            :aria-label="a.label"
                        >
                            <button
                                v-for="s in STATES"
                                :key="s.key"
                                type="button"
                                role="radio"
                                :aria-checked="drafts[a.key].state === s.key"
                                :disabled="!canEdit"
                                class="rounded-lg px-2.5 py-1 text-xs font-semibold transition disabled:cursor-not-allowed"
                                :class="
                                    drafts[a.key].state === s.key
                                        ? 'bg-emerald-700 text-white shadow'
                                        : 'text-muted-foreground hover:text-foreground'
                                "
                                @click="drafts[a.key].state = s.key"
                            >
                                {{ s.label }}
                            </button>
                        </div>
                        <div
                            class="flex flex-col gap-2 sm:flex-row sm:items-end"
                        >
                            <label
                                v-if="drafts[a.key].state === 'capturado'"
                                class="flex w-full flex-col gap-1 sm:w-40"
                            >
                                <span
                                    class="text-[11px] font-semibold text-muted-foreground"
                                    >Importe (MXN)</span
                                >
                                <input
                                    :id="`er-adj-amount-${a.key}`"
                                    v-model="drafts[a.key].amount"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                    inputmode="decimal"
                                    placeholder="0.00"
                                    required
                                    :disabled="!canEdit"
                                    :aria-invalid="
                                        touched[a.key] && !!amountError(a)
                                    "
                                    :aria-describedby="
                                        touched[a.key] && amountError(a)
                                            ? `er-adj-err-${a.key}`
                                            : undefined
                                    "
                                    class="h-9 w-full rounded-xl border bg-background px-3 text-sm tabular-nums focus-visible:ring-3 focus-visible:ring-emerald-500/25 focus-visible:outline-none aria-invalid:border-rose-500"
                                    @blur="touched[a.key] = true"
                                />
                            </label>
                            <label class="flex flex-1 flex-col gap-1">
                                <span
                                    class="text-[11px] font-semibold text-muted-foreground"
                                    >Notas o soporte contable</span
                                >
                                <input
                                    v-model="drafts[a.key].notes"
                                    type="text"
                                    maxlength="2000"
                                    placeholder="Opcional"
                                    :disabled="!canEdit"
                                    class="h-9 w-full rounded-xl border bg-background px-3 text-sm focus-visible:ring-3 focus-visible:ring-emerald-500/25 focus-visible:outline-none"
                                />
                            </label>
                            <Button
                                v-if="canEdit"
                                size="sm"
                                class="h-9 bg-emerald-700 text-white hover:bg-emerald-800"
                                :disabled="!dirty(a) || savingKey === a.key"
                                @click="save(a)"
                            >
                                <Loader2
                                    v-if="savingKey === a.key"
                                    class="size-3.5 animate-spin"
                                />
                                <Save v-else class="size-3.5" />
                                Guardar
                            </Button>
                        </div>
                        <p
                            v-if="touched[a.key] && amountError(a)"
                            :id="`er-adj-err-${a.key}`"
                            class="text-xs font-medium text-rose-700 dark:text-rose-400"
                        >
                            {{ amountError(a) }}
                        </p>
                        <p
                            v-else-if="dirty(a) && canEdit"
                            class="text-[11px] font-semibold text-sky-700 dark:text-sky-300"
                        >
                            Cambio sin guardar
                        </p>
                    </template>
                    <p
                        v-if="a.updated_by"
                        class="text-[11px] text-muted-foreground"
                    >
                        Último cambio: {{ a.updated_by }} · {{ a.updated_at }}
                    </p>
                </div>
            </div>
        </section>

        <Collapsible v-model:open="showLogs">
            <CollapsibleTrigger
                class="flex items-center gap-1.5 text-sm font-semibold text-emerald-700 hover:underline dark:text-emerald-400"
            >
                <History class="size-4" /> Historial de ajustes
            </CollapsibleTrigger>
            <CollapsibleContent>
                <div class="mt-2 overflow-x-auto rounded-2xl border bg-card">
                    <Loader2
                        v-if="logsLoading"
                        class="m-4 size-4 animate-spin text-emerald-600"
                    />
                    <p
                        v-else-if="!logs.length"
                        class="p-4 text-xs text-muted-foreground"
                    >
                        Sin cambios registrados.
                    </p>
                    <table v-else class="w-full min-w-[640px] text-xs">
                        <thead class="bg-muted/60 text-left">
                            <tr>
                                <th class="px-3 py-1.5">Fecha</th>
                                <th class="px-3 py-1.5">Concepto</th>
                                <th class="px-3 py-1.5">Antes</th>
                                <th class="px-3 py-1.5">Después</th>
                                <th class="px-3 py-1.5">Usuario</th>
                                <th class="px-3 py-1.5">Notas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(l, i) in logs"
                                :key="i"
                                class="border-t"
                            >
                                <td class="px-3 py-1.5 whitespace-nowrap">
                                    {{ l.at }}
                                </td>
                                <td class="px-3 py-1.5">
                                    {{ l.label }}
                                </td>
                                <td class="px-3 py-1.5">
                                    {{
                                        l.old_state
                                            ? STATE_LABEL[l.old_state]
                                            : '—'
                                    }}
                                    {{ l.old_amount ? mxn(l.old_amount) : '' }}
                                </td>
                                <td class="px-3 py-1.5 font-semibold">
                                    {{ STATE_LABEL[l.new_state] }}
                                    {{ l.new_amount ? mxn(l.new_amount) : '' }}
                                </td>
                                <td class="px-3 py-1.5">
                                    {{ l.user ?? '—' }}
                                </td>
                                <td class="px-3 py-1.5">
                                    {{ l.notes ?? '' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </CollapsibleContent>
        </Collapsible>
    </div>
</template>
