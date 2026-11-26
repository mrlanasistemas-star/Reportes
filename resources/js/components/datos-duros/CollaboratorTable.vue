<script setup lang="ts">
/** CollaboratorTable — vista densa (tipo hoja) con encabezados ordenables; clic en fila abre la ficha. */
import { ArrowDown, ArrowUp, ArrowUpDown } from 'lucide-vue-next';
import { moneyCompact, money, num, percent } from '@/lib/format';
import { moraTone } from '@/lib/hardData';
import type { HardDataRow, HardDataSortKey } from '@/types/hardData';
import CategoryBadge from './CategoryBadge.vue';
import HighlightText from './HighlightText.vue';

defineProps<{
    rows: HardDataRow[];
    tokens: string[];
    sortKey: HardDataSortKey;
    sortDir: 'asc' | 'desc';
}>();
const emit = defineEmits<{
    open: [row: HardDataRow];
    sort: [key: HardDataSortKey];
}>();

const columns: {
    key: HardDataSortKey | null;
    label: string;
    align: 'left' | 'right';
}[] = [
    { key: 'name', label: 'Colaborador', align: 'left' },
    { key: null, label: 'Categoría', align: 'left' },
    { key: 'placement', label: 'Colocación', align: 'right' },
    { key: 'recovery', label: 'Recuperación', align: 'right' },
    { key: 'portfolio', label: 'Cartera', align: 'right' },
    { key: 'mora_pct', label: 'Mora', align: 'right' },
    { key: 'payroll', label: 'Nómina', align: 'right' },
    { key: 'ebitda', label: 'EBITDA', align: 'right' },
];

const TONE_TEXT = {
    success: 'text-success',
    warning: 'text-warning',
    danger: 'text-danger',
} as const;
</script>

<template>
    <div
        class="overflow-hidden rounded-2xl border border-border bg-card shadow-soft-xs"
    >
        <div class="max-h-[70vh] overflow-auto">
            <table class="w-full min-w-[920px] text-sm">
                <thead class="sticky top-0 z-10 bg-muted/90 backdrop-blur">
                    <tr>
                        <th
                            v-for="c in columns"
                            :key="c.label"
                            scope="col"
                            :aria-sort="
                                c.key && c.key === sortKey
                                    ? sortDir === 'asc'
                                        ? 'ascending'
                                        : 'descending'
                                    : undefined
                            "
                            :class="[
                                'px-4 py-3 text-[11px] font-bold tracking-wide text-muted-foreground uppercase',
                                c.align === 'right'
                                    ? 'text-right'
                                    : 'text-left',
                            ]"
                        >
                            <button
                                v-if="c.key"
                                type="button"
                                :class="[
                                    'inline-flex items-center gap-1 tracking-wide uppercase transition hover:text-foreground',
                                    c.key === sortKey && 'text-foreground',
                                ]"
                                @click="emit('sort', c.key)"
                            >
                                {{ c.label }}
                                <ArrowUp
                                    v-if="
                                        c.key === sortKey && sortDir === 'asc'
                                    "
                                    class="size-3"
                                />
                                <ArrowDown
                                    v-else-if="c.key === sortKey"
                                    class="size-3"
                                />
                                <ArrowUpDown v-else class="size-3 opacity-40" />
                            </button>
                            <template v-else>{{ c.label }}</template>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr
                        v-for="r in rows"
                        :key="r.id"
                        tabindex="0"
                        class="cursor-pointer transition-colors hover:bg-primary/5 focus-visible:bg-primary/5 focus-visible:outline-none"
                        @click="emit('open', r)"
                        @keydown.enter="emit('open', r)"
                    >
                        <td class="px-4 py-2.5">
                            <p class="font-semibold text-foreground">
                                <HighlightText
                                    :text="r.name"
                                    :tokens="tokens"
                                />
                                <span
                                    v-if="
                                        (r.status ?? '').toUpperCase() ===
                                        'BAJA'
                                    "
                                    class="ml-1.5 rounded-full bg-danger-soft px-1.5 py-px text-[10px] font-bold text-danger"
                                    >BAJA</span
                                >
                            </p>
                            <p class="text-xs text-muted-foreground">
                                <HighlightText
                                    :text="r.branch ?? 'Sin sucursal'"
                                    :tokens="tokens"
                                />
                            </p>
                        </td>
                        <td class="px-4 py-2.5">
                            <CategoryBadge :category="r.category" />
                        </td>
                        <td
                            class="px-4 py-2.5 text-right tabular-nums"
                            :title="money(r.placement)"
                        >
                            {{ moneyCompact(r.placement)
                            }}<span
                                class="block text-[11px] text-muted-foreground"
                                >{{ num(r.operations) }} ops</span
                            >
                        </td>
                        <td
                            class="px-4 py-2.5 text-right tabular-nums"
                            :title="money(r.recovery)"
                        >
                            {{ moneyCompact(r.recovery) }}
                        </td>
                        <td
                            class="px-4 py-2.5 text-right tabular-nums"
                            :title="money(r.portfolio)"
                        >
                            {{ moneyCompact(r.portfolio) }}
                        </td>
                        <td
                            :class="[
                                'px-4 py-2.5 text-right font-semibold tabular-nums',
                                TONE_TEXT[moraTone(r.mora_pct)],
                            ]"
                        >
                            {{ percent(r.mora_pct) }}
                        </td>
                        <td
                            class="px-4 py-2.5 text-right tabular-nums"
                            :title="money(r.payroll)"
                        >
                            {{ moneyCompact(r.payroll) }}
                        </td>
                        <td
                            :class="[
                                'px-4 py-2.5 text-right font-bold tabular-nums',
                                r.ebitda < 0
                                    ? 'text-danger'
                                    : 'text-foreground',
                            ]"
                            :title="money(r.ebitda)"
                        >
                            {{ moneyCompact(r.ebitda) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
