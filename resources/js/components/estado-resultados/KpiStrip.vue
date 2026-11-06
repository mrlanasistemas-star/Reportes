<script setup lang="ts">
import { computed } from 'vue';
import type { CompPair } from './comparativas';
import KpiTile from './KpiTile.vue';
import type { Dataset } from './types';

const props = defineProps<{
    dataset: Dataset;
    activeStep: string | null;
    /** Comparativa contra el periodo anterior (carga aparte; puede no estar lista). */
    comparison?: CompPair | null;
    comparisonLoading?: boolean;
}>();
defineEmits<{ select: [stepKey: string] }>();

/** Valor del modelo vigente; las versiones con el modelo anterior usan sus claves equivalentes. */
function value(key: string, legacy?: string): string | null {
    const r = props.dataset.results;

    if (key in r) {
        return r[key] ?? null;
    }

    return legacy && legacy in r ? (r[legacy] ?? null) : null;
}

const tiles = computed(() => {
    const d = props.dataset;
    const m = d.margins;
    const c = d.completeness;
    const firstOpex =
        d.waterfall.find((w) => w.group === 'opex')?.key ?? 'opex_total';

    return [
        {
            key: 'ingresos_operativos',
            step: 'ingresos_operativos',
            label: 'Ingresos operativos',
            unit: 'MXN' as const,
            value: value('ingresos_operativos', 'ingresos'),
        },
        {
            key: 'opex_total',
            step: firstOpex,
            label: 'OPEX',
            unit: 'MXN' as const,
            value: value('opex_total'),
        },
        {
            key: 'ebitda',
            step: 'ebitda',
            label: 'EBITDA',
            unit: 'MXN' as const,
            value: value('ebitda', 'ebitda_final'),
            emphasis: true,
        },
        {
            key: 'ebit',
            step: 'ebit',
            label: 'EBIT',
            unit: 'MXN' as const,
            value: value('ebit'),
            missing: c.ebit?.missing,
        },
        {
            key: 'ebt',
            step: 'ebt',
            label: 'EBT',
            unit: 'MXN' as const,
            value: value('ebt'),
            missing: c.ebt?.missing,
        },
        {
            key: 'utilidad_neta',
            step: 'utilidad_neta',
            label: 'Utilidad neta',
            unit: 'MXN' as const,
            value: value('utilidad_neta'),
            emphasis: true,
            missing: c.utilidad_neta?.missing,
            pendingText: 'Pendiente de ISR',
        },
        {
            key: 'margen_ebitda',
            step: 'ebitda',
            label: 'Margen EBITDA',
            unit: '%' as const,
            value: m.ebitda ?? null,
            pendingText: 'Sin margen',
        },
        {
            key: 'margen_ebit',
            step: 'ebit',
            label: 'Margen EBIT',
            unit: '%' as const,
            value: m.ebit ?? null,
            pendingText: 'Sin margen',
        },
        {
            key: 'margen_neto',
            step: 'utilidad_neta',
            label: 'Margen neto',
            unit: '%' as const,
            value: m.neto ?? null,
            pendingText: 'Sin margen',
        },
    ];
});

const rows = computed(
    () => new Map((props.comparison?.rows ?? []).map((r) => [r.key, r])),
);
</script>

<template>
    <section
        aria-label="Resultados del periodo"
        class="overflow-hidden rounded-2xl border bg-card shadow-soft-xs"
    >
        <div
            class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-2 text-xs text-muted-foreground"
        >
            <span class="font-semibold text-foreground"
                >Resultados del periodo</span
            >
            <span v-if="comparison">
                Variación contra
                <b class="text-foreground">{{
                    comparison.comparison.period.label
                }}</b>
                <template v-if="!comparison.comparability.comparable">
                    · no comparable</template
                >
            </span>
            <span v-else-if="comparisonLoading"
                >Buscando el periodo anterior…</span
            >
        </div>
        <div class="-mr-px -mb-px flex flex-wrap">
            <KpiTile
                v-for="t in tiles"
                :key="t.key"
                class="flex-[1_1_118px] border-r border-b border-border/70"
                :label="t.label"
                :value="t.value"
                :unit="t.unit"
                :missing="t.missing"
                :pending-text="t.pendingText"
                :emphasis="t.emphasis"
                :active="activeStep === t.step"
                :delta="rows.get(t.key) ?? null"
                :comparable="comparison?.comparability.comparable ?? false"
                :delta-loading="comparisonLoading && !comparison"
                @select="$emit('select', t.step)"
            />
        </div>
    </section>
</template>
