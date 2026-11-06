<script setup lang="ts">
import { Lock } from 'lucide-vue-next';
import { computed } from 'vue';
import { mxn, num, pct } from './types';
import type { Dataset } from './types';

const props = defineProps<{ dataset: Dataset }>();
defineEmits<{ open: [blockKey: string] }>();

const COLORS = [
    'bg-emerald-600',
    'bg-teal-500',
    'bg-cyan-500',
    'bg-sky-600',
    'bg-slate-500',
];

/** Categorías del OPEX tal como las trae el estado de resultados (sin inventar). */
const opex = computed(() => {
    const s = props.dataset.statement.find(
        (b) => b.type === 'section' && b.key === 'opex',
    );

    return s && s.type === 'section' ? s : null;
});

const items = computed(() => {
    const cats = opex.value?.categories ?? [];
    const max = Math.max(1, ...cats.map((c) => Math.abs(num(c.total.amount))));

    return cats.map((c, i) => ({
        ...c,
        color: COLORS[i % COLORS.length],
        width: (Math.abs(num(c.total.amount)) / max) * 100,
    }));
});
</script>

<template>
    <section
        class="rounded-2xl border bg-card p-5 shadow-soft-xs"
        aria-label="Composición del OPEX"
    >
        <div class="flex items-baseline justify-between gap-2">
            <h3 class="text-base font-extrabold">Composición del OPEX</h3>
            <p v-if="opex" class="text-sm font-extrabold tabular-nums">
                {{
                    opex.total.amount === null
                        ? 'Pendiente'
                        : mxn(opex.total.amount)
                }}
            </p>
        </div>
        <p class="mt-0.5 text-xs text-muted-foreground">
            Corporativo y Dirección General son categorías del OPEX, no niveles
            de resultado. Todo se resta antes de llegar al EBITDA.
        </p>
        <p
            v-if="!items.length"
            class="mt-4 rounded-xl border border-dashed p-4 text-xs text-muted-foreground"
        >
            Esta versión no trae el OPEX por categorías (modelo anterior).
        </p>
        <ul v-else class="mt-4 space-y-2.5">
            <li v-for="c in items" :key="c.key">
                <button
                    type="button"
                    class="group w-full rounded-lg text-left focus-visible:ring-3 focus-visible:ring-emerald-500/30 focus-visible:outline-none"
                    @click="$emit('open', `opex:${c.key}`)"
                >
                    <span
                        class="flex flex-wrap items-center justify-between gap-x-2 gap-y-0.5 text-xs"
                    >
                        <span class="flex min-w-0 items-center gap-2">
                            <span
                                class="size-2.5 shrink-0 rounded-sm"
                                :class="c.color"
                            />
                            <span
                                class="truncate font-semibold group-hover:underline"
                                >{{ c.label }}</span
                            >
                            <Lock
                                v-if="c.restricted"
                                class="size-3 shrink-0 text-muted-foreground"
                            />
                        </span>
                        <span class="shrink-0 tabular-nums">
                            <b>{{ mxn(c.total.amount) }}</b>
                            <span class="ml-1.5 text-muted-foreground"
                                >{{ pct(c.total.pct) }} ingresos</span
                            >
                        </span>
                    </span>
                    <span
                        class="mt-1 block h-2 overflow-hidden rounded-full bg-muted"
                    >
                        <span
                            class="block h-full rounded-full transition-[width] duration-700"
                            :class="c.color"
                            :style="{ width: `${c.width}%` }"
                        />
                    </span>
                </button>
            </li>
        </ul>
    </section>
</template>
