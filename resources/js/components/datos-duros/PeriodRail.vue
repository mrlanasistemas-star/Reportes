<script setup lang="ts">
/**
 * PeriodRail — carril horizontal de periodos (sin calendarios ni selects):
 * un toque cambia de periodo; flechas ← → recorren los periodos con teclado.
 */
import { LoaderCircle } from 'lucide-vue-next';
import { nextTick, ref, watch } from 'vue';
import type { HardDataPeriod } from '@/types/hardData';

const props = defineProps<{
    periods: HardDataPeriod[];
    modelValue: number | null;
    loading?: boolean;
}>();
const emit = defineEmits<{ 'update:modelValue': [id: number] }>();

const TYPE_LABELS: Record<string, string> = {
    weekly: 'Semana',
    bimonthly: 'Bimestre',
    quarterly: 'Trimestre',
};
const rail = ref<HTMLElement | null>(null);

function select(id: number): void {
    if (id !== props.modelValue) {
        emit('update:modelValue', id);
    }
}

function onKey(e: KeyboardEvent): void {
    const idx = props.periods.findIndex((p) => p.id === props.modelValue);

    if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
        e.preventDefault();
        // El carril va del más reciente (izquierda) al más antiguo (derecha).
        const next = props.periods[idx + (e.key === 'ArrowRight' ? 1 : -1)];

        if (next) {
            select(next.id);
            nextTick(() =>
                rail.value
                    ?.querySelector<HTMLElement>(`[data-period="${next.id}"]`)
                    ?.focus(),
            );
        }
    }
}

watch(
    () => props.modelValue,
    (id) =>
        nextTick(() =>
            rail.value
                ?.querySelector<HTMLElement>(`[data-period="${id}"]`)
                ?.scrollIntoView({
                    block: 'nearest',
                    inline: 'center',
                    behavior: 'smooth',
                }),
        ),
);
</script>

<template>
    <div class="relative">
        <div
            ref="rail"
            role="radiogroup"
            aria-label="Periodo"
            class="-mx-1 flex snap-x gap-2 overflow-x-auto px-1 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
            @keydown="onKey"
        >
            <button
                v-for="p in periods"
                :key="p.id"
                type="button"
                role="radio"
                :data-period="p.id"
                :aria-checked="p.id === modelValue"
                :tabindex="p.id === modelValue ? 0 : -1"
                :class="[
                    'group relative flex min-w-[9.5rem] shrink-0 snap-start flex-col items-start rounded-2xl border px-4 py-2.5 text-left transition-all duration-200 outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
                    p.id === modelValue
                        ? 'border-transparent bg-gradient-to-br from-primary to-primary/80 text-primary-foreground shadow-soft-md'
                        : 'border-border bg-card text-foreground hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-soft-sm',
                ]"
                @click="select(p.id)"
            >
                <span class="flex w-full items-center justify-between gap-2">
                    <span class="text-sm font-bold tracking-tight">{{
                        p.name
                    }}</span>
                    <LoaderCircle
                        v-if="loading && p.id === modelValue"
                        class="size-3.5 animate-spin opacity-80"
                        aria-hidden="true"
                    />
                    <span
                        v-else-if="TYPE_LABELS[p.type]"
                        :class="[
                            'rounded-full px-1.5 py-px text-[10px] font-semibold',
                            p.id === modelValue
                                ? 'bg-white/20'
                                : 'bg-muted text-muted-foreground',
                        ]"
                        >{{ TYPE_LABELS[p.type] }}</span
                    >
                </span>
                <span
                    :class="[
                        'mt-0.5 text-[11px]',
                        p.id === modelValue
                            ? 'text-primary-foreground/80'
                            : 'text-muted-foreground',
                    ]"
                    >{{ p.range ?? '—' }}</span
                >
            </button>
        </div>
    </div>
</template>
