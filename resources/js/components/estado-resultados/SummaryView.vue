<script setup lang="ts">
import { computed, ref } from 'vue';
import ConciliationSummary from './ConciliationSummary.vue';
import OpexComposition from './OpexComposition.vue';
import ResultWaterfall from './ResultWaterfall.vue';
import StepInspector from './StepInspector.vue';
import { buildStepMeta } from './storyline';
import type { Dataset } from './types';

const props = defineProps<{ dataset: Dataset }>();
const selected = defineModel<string | null>('selected', { default: null });

const emit = defineEmits<{
    openStatement: [key: string];
    openMovements: [filterKey: string];
}>();

const preview = ref<string | null>(null);
const meta = computed(() => buildStepMeta(props.dataset));
const shownKey = computed(
    () =>
        preview.value ??
        selected.value ??
        // Por defecto se explica el EBITDA: la utilidad neta ya está en la cabecera.
        props.dataset.waterfall.find((w) => w.key === 'ebitda')?.key ??
        props.dataset.waterfall.at(-1)?.key ??
        null,
);
</script>

<template>
    <div class="space-y-4">
        <section
            class="rounded-2xl border bg-card p-4 shadow-soft-xs md:p-5"
            aria-labelledby="er-story-title"
        >
            <div class="mb-4 flex flex-wrap items-end justify-between gap-2">
                <div>
                    <h2 id="er-story-title" class="text-lg font-extrabold">
                        Cómo se llega a la utilidad neta
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Pasa el cursor por cada paso para ver su fórmula y de
                        dónde sale; haz clic para fijarlo y abrir su detalle.
                    </p>
                </div>
                <button
                    v-if="selected"
                    type="button"
                    class="rounded-lg px-2.5 py-1 text-xs font-semibold text-muted-foreground transition hover:bg-muted hover:text-foreground"
                    @click="selected = null"
                >
                    Soltar selección
                </button>
            </div>
            <div
                class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_320px] 2xl:grid-cols-[minmax(0,1fr)_400px]"
            >
                <div class="min-w-0">
                    <ResultWaterfall
                        :steps="dataset.waterfall"
                        :meta="meta"
                        :selected="selected"
                        :preview="preview"
                        @select="(k) => (selected = selected === k ? null : k)"
                        @preview="(k) => (preview = k)"
                    />
                </div>
                <StepInspector
                    :meta="shownKey ? (meta[shownKey] ?? null) : null"
                    :steps="dataset.waterfall"
                    :pinned="!preview || preview === selected"
                    @open-statement="(k) => emit('openStatement', k)"
                    @open-movements="(k) => emit('openMovements', k)"
                />
            </div>
        </section>

        <div
            class="grid gap-4 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)] [&>*]:min-w-0"
        >
            <ConciliationSummary
                :c="dataset.conciliation"
                :movements="dataset.movements"
                :active-filter="null"
                compact
                @filter="(k) => emit('openMovements', k)"
            />
            <OpexComposition
                :dataset="dataset"
                @open="(k) => emit('openStatement', k)"
            />
        </div>
    </div>
</template>
