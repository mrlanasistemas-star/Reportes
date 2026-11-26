<script setup lang="ts">
/** CollaboratorCard — tarjeta de un colaborador con sus datos duros del periodo; abre la ficha completa. */
import { ChevronRight } from 'lucide-vue-next';
import { computed } from 'vue';
import { useInitials } from '@/composables/useInitials';
import { moneyCompact, money, percent } from '@/lib/format';
import { categoryMeta, moraTone } from '@/lib/hardData';
import type { HardDataRow } from '@/types/hardData';
import CategoryBadge from './CategoryBadge.vue';
import HighlightText from './HighlightText.vue';

const props = defineProps<{
    row: HardDataRow;
    tokens: string[];
    rank?: number;
}>();
const emit = defineEmits<{ open: [row: HardDataRow] }>();
const { getInitials } = useInitials();

const isBaja = computed(
    () => (props.row.status ?? '').toUpperCase() === 'BAJA',
);
const tone = computed(() => moraTone(props.row.mora_pct));
const moraBar = computed(() =>
    Math.min(100, Math.max(0, Number(props.row.mora_pct ?? 0))),
);
const TONE_TEXT = {
    success: 'text-success',
    warning: 'text-warning',
    danger: 'text-danger',
} as const;
const TONE_BAR = {
    success: 'bg-success',
    warning: 'bg-warning',
    danger: 'bg-danger',
} as const;

const metrics = computed(() => [
    {
        label: 'Colocación',
        value: props.row.placement,
        hint: `${props.row.operations} ops`,
    },
    { label: 'Recuperación', value: props.row.recovery, hint: null },
    { label: 'Cartera', value: props.row.portfolio, hint: null },
    { label: 'Nómina', value: props.row.payroll, hint: null },
]);
</script>

<template>
    <button
        type="button"
        class="group relative flex w-full flex-col overflow-hidden rounded-2xl border border-border bg-card p-4 text-left shadow-soft-xs transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-soft-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        @click="emit('open', row)"
    >
        <span
            :class="[
                'absolute inset-x-0 top-0 h-1 opacity-80',
                categoryMeta(row.category).dot,
            ]"
            aria-hidden="true"
        />

        <div class="flex items-start gap-3">
            <span
                v-if="rank"
                class="absolute top-2.5 left-2.5 z-10 flex h-5 min-w-5 items-center justify-center rounded-full bg-foreground px-1.5 text-[10px] font-extrabold text-background tabular-nums shadow-soft-xs"
                :title="`Lugar ${rank} en el orden actual`"
                >{{ rank }}</span
            >
            <span
                :class="[
                    'flex size-11 shrink-0 items-center justify-center rounded-2xl text-sm font-extrabold',
                    isBaja
                        ? 'bg-muted text-muted-foreground'
                        : 'bg-gradient-to-br from-primary/15 to-primary/5 text-primary',
                ]"
                >{{ getInitials(row.name) }}</span
            >
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-bold text-foreground">
                    <HighlightText :text="row.name" :tokens="tokens" />
                </p>
                <p class="mt-0.5 truncate text-xs text-muted-foreground">
                    <HighlightText
                        :text="row.branch ?? 'Sin sucursal'"
                        :tokens="tokens"
                    />
                    <template v-if="row.routes.length">
                        ·
                        <HighlightText
                            :text="row.routes.join(', ')"
                            :tokens="tokens"
                        />
                    </template>
                </p>
            </div>
            <div class="flex flex-col items-end gap-1">
                <CategoryBadge :category="row.category" />
                <span
                    v-if="isBaja"
                    class="rounded-full bg-danger-soft px-2 py-px text-[10px] font-bold text-danger uppercase"
                    >Baja</span
                >
            </div>
        </div>

        <div
            class="mt-4 flex items-end justify-between gap-3 rounded-xl bg-muted/50 px-3 py-2.5"
        >
            <div>
                <p
                    class="text-[10px] font-bold tracking-wide text-muted-foreground uppercase"
                >
                    EBITDA
                </p>
                <p
                    :class="[
                        'text-lg font-extrabold tracking-tight tabular-nums',
                        row.ebitda < 0 ? 'text-danger' : 'text-foreground',
                    ]"
                    :title="money(row.ebitda)"
                >
                    {{ moneyCompact(row.ebitda) }}
                </p>
            </div>
            <div class="text-right">
                <p
                    class="text-[10px] font-bold tracking-wide text-muted-foreground uppercase"
                >
                    Margen
                </p>
                <p class="text-sm font-bold text-foreground tabular-nums">
                    {{
                        row.margin_pct === null ? '—' : percent(row.margin_pct)
                    }}
                </p>
            </div>
        </div>

        <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2.5">
            <div v-for="m in metrics" :key="m.label" :title="money(m.value)">
                <dt class="text-[11px] text-muted-foreground">{{ m.label }}</dt>
                <dd class="text-sm font-bold text-foreground tabular-nums">
                    {{ moneyCompact(m.value) }}
                    <span
                        v-if="m.hint"
                        class="text-[11px] font-medium text-muted-foreground"
                        >· {{ m.hint }}</span
                    >
                </dd>
            </div>
        </dl>

        <div class="mt-3">
            <div class="flex items-center justify-between text-[11px]">
                <span class="text-muted-foreground"
                    >Mora · vencida {{ moneyCompact(row.overdue) }}</span
                >
                <span :class="['font-bold tabular-nums', TONE_TEXT[tone]]">{{
                    percent(row.mora_pct)
                }}</span>
            </div>
            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    :class="[
                        'h-full rounded-full transition-all duration-500',
                        TONE_BAR[tone],
                    ]"
                    :style="{ width: `${Math.max(moraBar, 1.5)}%` }"
                />
            </div>
        </div>

        <ChevronRight
            class="absolute right-3 bottom-3 size-4 text-muted-foreground opacity-0 transition-all group-hover:translate-x-0.5 group-hover:opacity-100"
            aria-hidden="true"
        />
    </button>
</template>
