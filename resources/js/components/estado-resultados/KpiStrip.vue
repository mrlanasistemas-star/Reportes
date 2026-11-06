<script setup lang="ts">
import { computed } from 'vue';
import KpiTile from './KpiTile.vue';
import { mxn } from './types';
import type { Dataset } from './types';

const props = defineProps<{ dataset: Dataset; activeStep: string | null }>();
defineEmits<{ select: [stepKey: string] }>();

/** Resultados de la cadena ingresos → utilidad neta, tal como los entrega el dataset. */
const tiles = computed(() => {
    const d = props.dataset;
    const r = d.results;
    const m = d.margins;
    const c = d.completeness;
    const rec = d.radiography_reconciliation;
    const consolidated = d.meta.scope.consolidated;

    const list = [
        {
            key: 'ingresos',
            label: 'Ingresos operativos',
            tier: null as string | null,
            value: r.ingresos,
            margin: null as string | null,
            missing: [] as string[],
            note: null as null | {
                tone: 'ok' | 'warn';
                text: string;
                title: string;
            },
        },
        {
            key: 'ebitda_sucursales',
            label: 'EBITDA sucursales',
            tier: consolidated ? '1' : null,
            value: r.ebitda_sucursales,
            margin: m.ebitda_sucursales,
            missing: [],
            note: rec.ok
                ? {
                      tone: 'ok' as const,
                      text: '= Radiografía',
                      title: 'Coincide en $0.00 con el EBITDA de la Radiografía del periodo.',
                  }
                : {
                      tone: 'warn' as const,
                      text: `Dif. ${mxn(rec.difference)}`,
                      title: `Radiografía reporta ${mxn(rec.radiografia_ebitda)}. Revisa la conciliación con Radiografía en el resumen.`,
                  },
        },
    ];

    if (consolidated) {
        list.push(
            {
                key: 'ebitda_corporativo',
                label: 'EBITDA corporativo',
                tier: '2',
                value: r.ebitda_corporativo,
                margin: m.ebitda_corporativo,
                missing: [],
                note: null,
            },
            {
                key: 'ebitda_final',
                label: 'EBITDA final',
                tier: '3',
                value: r.ebitda_final,
                margin: m.ebitda_final,
                missing: [],
                note: null,
            },
        );
    }

    list.push(
        {
            key: 'ebit',
            label: 'EBIT',
            tier: null,
            value: r.ebit,
            margin: m.ebit,
            missing: c.ebit?.missing ?? [],
            note: null,
        },
        {
            key: 'ebt',
            label: 'EBT',
            tier: null,
            value: r.ebt,
            margin: m.ebt,
            missing: c.ebt?.missing ?? [],
            note: null,
        },
        {
            key: 'utilidad_neta',
            label: 'Utilidad neta',
            tier: null,
            value: r.utilidad_neta,
            margin: m.neto,
            missing: c.utilidad_neta?.missing ?? [],
            note: null,
        },
    );

    return list;
});
</script>

<template>
    <section
        aria-label="Resultados del periodo"
        class="overflow-hidden rounded-2xl border bg-card shadow-soft-xs"
    >
        <div class="-mr-px -mb-px flex flex-wrap">
            <KpiTile
                v-for="t in tiles"
                :key="t.key"
                class="flex-[1_1_150px] border-r border-b border-border/70"
                :label="t.label"
                :tier="t.tier"
                :value="t.value"
                :margin="t.margin"
                :missing="t.missing"
                :note="t.note"
                :emphasis="!!t.tier || t.key === 'utilidad_neta'"
                :active="activeStep === t.key"
                @select="$emit('select', t.key)"
            />
        </div>
    </section>
</template>
