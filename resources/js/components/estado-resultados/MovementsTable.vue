<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    Inbox,
    ListChecks,
    Search,
    Sparkles,
    Tags,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { isConciliated, mxn, reconciliationTone, TONE_CLS } from './types';
import type { Movement } from './types';

const props = defineProps<{
    movements: Movement[];
    canClassify: boolean;
    toneOf: (treatment: string) => string;
}>();

const emit = defineEmits<{
    open: [m: Movement];
    classify: [fingerprints: string[]];
    acceptSuggestions: [fingerprints: string[]];
}>();

type Chip = { key: string; label: string; test: (m: Movement) => boolean };
const CHIPS: Chip[] = [
    {
        key: 'conciliados',
        label: 'Duplicados / conciliados ERP',
        test: (m) =>
            isConciliated(m) || m.reconciliation_status === 'duplicado_uuid',
    },
    {
        key: 'nuevos',
        label: 'Sólo nuevos',
        test: (m) =>
            ['folio_sin_erp', 'sin_folio'].includes(m.reconciliation_status),
    },
    {
        key: 'ambiguos',
        label: 'Requieren revisión',
        test: (m) => m.needs_review,
    },
    {
        key: 'corporativo',
        label: 'Corporativo',
        test: (m) => m.branch_kind === 'corporativo',
    },
    {
        key: 'dg',
        label: 'DG',
        test: (m) =>
            (m.rule_code ?? '').includes('dg') || m.treatment.startsWith('dg_'),
    },
    {
        key: 'transferencias',
        label: 'Transferencias',
        test: (m) => m.treatment === 'transferencia_interna',
    },
    { key: 'excluidos', label: 'Excluidos', test: (m) => !m.impact.included },
    { key: 'incluidos', label: 'Incluidos', test: (m) => m.impact.included },
];

const chip = ref<string | null>(null);
const search = ref('');
const origin = ref('todos');
const page = ref(1);
const perPage = 25;
const selected = ref<Set<string>>(new Set());

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
        CHIPS.map((c) => [c.key, props.movements.filter(c.test).length]),
    ),
);

const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    const active = CHIPS.find((c) => c.key === chip.value);

    return props.movements.filter((m) => {
        if (active && !active.test(m)) {
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

watch([chip, search, origin], () => {
    page.value = 1;
});

const selectedList = computed(() =>
    props.movements.filter((m) => selected.value.has(m.fingerprint)),
);
const withSuggestion = computed(() =>
    filtered.value.filter(
        (m) => m.treatment === 'pendiente' && m.suggested_treatment,
    ),
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

function clearSelection(): void {
    selected.value = new Set();
}

defineExpose({ clearSelection });

function erpCell(m: Movement): string {
    if (m.source === 'erp') {
        return m.linked_bank.length
            ? `${m.linked_bank.length} pago(s) banco`
            : '—';
    }

    const e = m.erp[0];

    return e ? `${mxn(e.amount)} · ${e.date ?? '—'}` : '—';
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-col gap-2 lg:flex-row lg:items-center">
            <div class="relative flex-1">
                <Search
                    class="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    v-model="search"
                    type="search"
                    placeholder="Buscar por concepto, beneficiario, folio, UUID, sucursal, importe…"
                    class="h-10 w-full rounded-xl border bg-background pr-3 pl-9 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-500/20"
                />
            </div>
            <select
                v-model="origin"
                class="h-10 rounded-xl border bg-background px-3 text-sm"
            >
                <option value="todos">Todos los orígenes</option>
                <option v-for="o in origins" :key="o.key" :value="o.key">
                    {{ o.label }}
                </option>
            </select>
        </div>

        <div class="flex flex-wrap gap-1.5">
            <button
                type="button"
                class="rounded-full border px-3 py-1 text-xs font-semibold transition"
                :class="
                    chip === null
                        ? 'border-teal-600 bg-teal-600 text-white'
                        : 'hover:bg-muted'
                "
                @click="chip = null"
            >
                Todos · {{ movements.length }}
            </button>
            <button
                v-for="c in CHIPS"
                :key="c.key"
                type="button"
                class="rounded-full border px-3 py-1 text-xs font-semibold transition"
                :class="
                    chip === c.key
                        ? 'border-teal-600 bg-teal-600 text-white'
                        : 'hover:bg-muted'
                "
                @click="chip = chip === c.key ? null : c.key"
            >
                {{ c.label }} · {{ counts[c.key] }}
            </button>
        </div>

        <div
            v-if="canClassify"
            class="flex flex-wrap items-center gap-2 rounded-xl border border-dashed bg-muted/30 px-3 py-2 text-xs"
        >
            <ListChecks class="size-4 text-teal-700" />
            <span class="font-semibold"
                >{{ selectedList.length }} seleccionado(s)</span
            >
            <Button
                size="sm"
                :disabled="!selectedList.length"
                class="bg-teal-700 text-white hover:bg-teal-800"
                @click="
                    emit(
                        'classify',
                        selectedList.map((m) => m.fingerprint),
                    )
                "
            >
                <Tags class="size-3.5" /> Clasificar selección
            </Button>
            <Button
                size="sm"
                variant="outline"
                :disabled="!withSuggestion.length"
                @click="
                    emit(
                        'acceptSuggestions',
                        (selectedList.length
                            ? selectedList.filter(
                                  (m) =>
                                      m.treatment === 'pendiente' &&
                                      m.suggested_treatment,
                              )
                            : withSuggestion
                        ).map((m) => m.fingerprint),
                    )
                "
            >
                <Sparkles class="size-3.5" />
                Aceptar sugerencias ({{
                    selectedList.length
                        ? selectedList.filter(
                              (m) =>
                                  m.treatment === 'pendiente' &&
                                  m.suggested_treatment,
                          ).length
                        : withSuggestion.length
                }})
            </Button>
            <button
                v-if="selectedList.length"
                type="button"
                class="ml-auto text-muted-foreground underline-offset-2 hover:underline"
                @click="clearSelection"
            >
                Limpiar selección
            </button>
        </div>

        <div class="overflow-hidden rounded-2xl border bg-card shadow-soft-xs">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[1180px] text-xs">
                    <thead
                        class="bg-teal-700 text-left text-[10.5px] tracking-wide text-white uppercase"
                    >
                        <tr>
                            <th v-if="canClassify" class="w-8 px-2 py-2">
                                <input
                                    type="checkbox"
                                    class="accent-emerald-500"
                                    :checked="allPageSelected"
                                    aria-label="Seleccionar página"
                                    @change="togglePage"
                                />
                            </th>
                            <th class="px-2 py-2">Fecha</th>
                            <th class="px-2 py-2">Origen</th>
                            <th class="px-2 py-2">Folio / UUID</th>
                            <th class="px-2 py-2">Beneficiario · concepto</th>
                            <th class="px-2 py-2">Sucursal</th>
                            <th class="px-2 py-2 text-right">Cargo</th>
                            <th class="px-2 py-2 text-right">Abono</th>
                            <th class="px-2 py-2">ERP encontrado</th>
                            <th class="px-2 py-2">Conciliación</th>
                            <th class="px-2 py-2">Clasificación</th>
                            <th class="px-2 py-2">Impacto</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="m in rows"
                            :key="m.fingerprint"
                            class="cursor-pointer transition hover:bg-emerald-50/50 dark:hover:bg-emerald-950/20"
                            :class="[
                                selected.has(m.fingerprint)
                                    ? 'bg-teal-50/70 dark:bg-teal-950/30'
                                    : '',
                                !m.in_period ? 'opacity-60' : '',
                            ]"
                            @click="emit('open', m)"
                        >
                            <td
                                v-if="canClassify"
                                class="px-2 py-2"
                                @click.stop
                            >
                                <input
                                    type="checkbox"
                                    class="accent-emerald-500"
                                    :checked="selected.has(m.fingerprint)"
                                    :aria-label="`Seleccionar ${m.concept ?? ''}`"
                                    @change="toggle(m.fingerprint)"
                                />
                            </td>
                            <td
                                class="px-2 py-2 whitespace-nowrap tabular-nums"
                            >
                                {{ m.date }}
                            </td>
                            <td class="px-2 py-2 whitespace-nowrap">
                                <span class="font-medium">{{
                                    m.source === 'erp'
                                        ? 'ERP'
                                        : (m.account_label ?? m.account_slot)
                                }}</span>
                                <span
                                    class="block text-[10px] text-muted-foreground"
                                    >{{
                                        m.source === 'erp'
                                            ? `id ${m.source_row}`
                                            : `fila ${m.source_row}`
                                    }}</span
                                >
                            </td>
                            <td class="max-w-[140px] px-2 py-2">
                                <span
                                    class="block truncate font-mono text-[11px]"
                                    >{{ m.folio ?? '—' }}</span
                                >
                                <span
                                    v-if="m.uuid"
                                    class="block truncate font-mono text-[10px] text-muted-foreground"
                                    :title="m.uuid"
                                    >{{ m.uuid }}</span
                                >
                            </td>
                            <td class="max-w-[260px] px-2 py-2">
                                <span class="block truncate font-medium">{{
                                    m.concept ?? '—'
                                }}</span>
                                <span
                                    class="block truncate text-[10.5px] text-muted-foreground"
                                    >{{
                                        m.beneficiary ??
                                        m.category ??
                                        m.reference ??
                                        ''
                                    }}</span
                                >
                            </td>
                            <td class="px-2 py-2 whitespace-nowrap">
                                {{ m.branch_name ?? m.sucursal_raw ?? '—' }}
                            </td>
                            <td
                                class="px-2 py-2 text-right whitespace-nowrap tabular-nums"
                            >
                                {{ Number(m.cargo) ? mxn(m.cargo) : '' }}
                            </td>
                            <td
                                class="px-2 py-2 text-right whitespace-nowrap text-sky-700 tabular-nums dark:text-sky-400"
                            >
                                {{ Number(m.abono) ? mxn(m.abono) : '' }}
                            </td>
                            <td
                                class="px-2 py-2 whitespace-nowrap text-muted-foreground"
                            >
                                {{ erpCell(m) }}
                            </td>
                            <td class="px-2 py-2">
                                <span
                                    class="inline-block rounded-full border px-2 py-0.5 text-[10.5px] font-semibold whitespace-nowrap"
                                    :class="
                                        reconciliationTone(
                                            m.reconciliation_status,
                                        )
                                    "
                                    >{{ m.reconciliation_label }}</span
                                >
                            </td>
                            <td class="px-2 py-2">
                                <span
                                    class="inline-block rounded-full border px-2 py-0.5 text-[10.5px] font-semibold whitespace-nowrap"
                                    :class="toneOf(m.treatment)"
                                    >{{ m.treatment_label }}</span
                                >
                                <span
                                    v-if="m.treatment_source === 'manual'"
                                    class="ml-1 text-[10px] font-bold text-violet-600"
                                    >manual</span
                                >
                                <span
                                    v-else-if="
                                        m.suggested_label &&
                                        m.treatment === 'pendiente'
                                    "
                                    class="block text-[10px] text-muted-foreground"
                                    >Sugerido: {{ m.suggested_label }}</span
                                >
                            </td>
                            <td class="px-2 py-2 whitespace-nowrap">
                                <span
                                    class="font-bold"
                                    :class="
                                        m.impact.included
                                            ? 'text-emerald-700 dark:text-emerald-400'
                                            : m.needs_review
                                              ? 'text-amber-700'
                                              : 'text-muted-foreground'
                                    "
                                    >{{
                                        m.impact.included
                                            ? `Suma ${mxn(m.impact.amount)}`
                                            : 'No suma'
                                    }}</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div
                v-if="!rows.length"
                class="flex flex-col items-center gap-2 py-14 text-center text-sm text-muted-foreground"
            >
                <Inbox class="size-8 text-muted-foreground/60" />
                <p class="font-semibold">Sin movimientos para este filtro</p>
                <p class="text-xs">Ajusta la búsqueda o los filtros rápidos.</p>
            </div>
            <div
                v-else
                class="flex items-center justify-between border-t px-3 py-2 text-xs text-muted-foreground"
            >
                <span
                    >{{ filtered.length }} movimiento(s) · página {{ page }} de
                    {{ pages }}</span
                >
                <div class="flex gap-1">
                    <Button
                        size="icon-sm"
                        variant="outline"
                        :disabled="page <= 1"
                        aria-label="Página anterior"
                        @click="page--"
                    >
                        <ChevronLeft />
                    </Button>
                    <Button
                        size="icon-sm"
                        variant="outline"
                        :disabled="page >= pages"
                        aria-label="Página siguiente"
                        @click="page++"
                    >
                        <ChevronRight />
                    </Button>
                </div>
            </div>
        </div>
        <p class="text-[11px] text-muted-foreground">
            <span class="rounded-full border px-1.5" :class="TONE_CLS.success"
                >Verde</span
            >
            conciliado con ERP (se cuenta una sola vez) ·
            <span class="rounded-full border px-1.5" :class="TONE_CLS.warning"
                >Ámbar</span
            >
            requiere revisión (no suma hasta clasificarse). Haz clic en una fila
            para ver por qué suma o no.
        </p>
    </div>
</template>
