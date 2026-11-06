<script setup lang="ts">
import { computed } from 'vue';
import { Progress } from '@/components/ui/progress';
import { mxn } from './types';
import type { Dataset } from './types';

const props = defineProps<{ c: Dataset['conciliation'] }>();

const tiles = computed(() => [
    {
        label: 'Movimientos leídos',
        value: String(props.c.movimientos_leidos),
        sub: `${props.c.movimientos_en_periodo} en periodo · ${props.c.fuera_periodo} fuera`,
        tone: 'slate',
    },
    {
        label: 'Total cargos',
        value: mxn(props.c.total_cargos),
        sub: 'Dentro del periodo',
        tone: 'slate',
    },
    {
        label: 'Total abonos',
        value: mxn(props.c.total_abonos),
        sub: 'Nunca son ingreso automático',
        tone: 'slate',
    },
    {
        label: 'Candidatos ERP',
        value: String(props.c.candidatos),
        sub: 'Cargos a conciliar',
        tone: 'slate',
    },
    {
        label: 'Conciliados con ERP',
        value: String(props.c.conciliados_erp.count),
        sub: mxn(props.c.conciliados_erp.amount) + ' · no se vuelven a sumar',
        tone: 'emerald',
    },
    {
        label: 'Nuevos (sin ERP)',
        value: String(props.c.nuevos.count),
        sub: mxn(props.c.nuevos.amount),
        tone: 'cyan',
    },
    {
        label: 'Transferencias',
        value: String(props.c.transferencias.count),
        sub: mxn(props.c.transferencias.amount),
        tone: 'sky',
    },
    {
        label: 'Fuera del ER',
        value: String(props.c.fuera_er.count),
        sub: mxn(props.c.fuera_er.amount),
        tone: 'slate',
    },
    {
        label: 'Pendientes de revisión',
        value: String(props.c.pendientes.count),
        sub: mxn(props.c.pendientes.amount) + ' · no suman',
        tone: props.c.pendientes.count ? 'amber' : 'emerald',
    },
    {
        label: 'Incorporado desde bancos',
        value: mxn(props.c.incorporado_bancos),
        sub: 'Suma real al Estado de Resultados',
        tone: 'teal',
    },
    {
        label: 'Incorporado ERP fuera de Radiografía',
        value: mxn(props.c.incorporado_erp_fuera_radiografia),
        sub: `${props.c.erp_fuera_radiografia.count} registros · ${mxn(props.c.erp_fuera_radiografia.amount)}`,
        tone: 'teal',
    },
]);

const reviewed = computed(() => {
    const total = props.c.movimientos_en_periodo || 0;

    return total === 0
        ? 100
        : Math.round(((total - props.c.pendientes.count) / total) * 100);
});

const TONES: Record<string, string> = {
    slate: 'border-border',
    emerald:
        'border-emerald-200 bg-emerald-50/50 dark:border-emerald-900 dark:bg-emerald-950/20',
    cyan: 'border-cyan-200 bg-cyan-50/50 dark:border-cyan-900 dark:bg-cyan-950/20',
    sky: 'border-sky-200 bg-sky-50/50 dark:border-sky-900 dark:bg-sky-950/20',
    amber: 'border-amber-300 bg-amber-50/70 dark:border-amber-900 dark:bg-amber-950/20',
    teal: 'border-teal-300 bg-gradient-to-br from-teal-50 to-cyan-50 dark:border-teal-900 dark:from-teal-950/30 dark:to-cyan-950/20',
};
</script>

<template>
    <div class="space-y-3">
        <div class="rounded-2xl border bg-card p-4 shadow-soft-xs">
            <div class="mb-2 flex items-center justify-between text-xs">
                <span class="font-semibold">Avance de clasificación</span>
                <span
                    class="font-bold text-teal-700 tabular-nums dark:text-teal-400"
                    >{{ reviewed }}% revisado</span
                >
            </div>
            <Progress :model-value="reviewed" />
        </div>
        <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-4 2xl:grid-cols-6">
            <div
                v-for="t in tiles"
                :key="t.label"
                class="rounded-2xl border p-3"
                :class="TONES[t.tone]"
            >
                <p
                    class="text-[10.5px] font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ t.label }}
                </p>
                <p class="mt-1 truncate text-lg font-extrabold tabular-nums">
                    {{ t.value }}
                </p>
                <p class="truncate text-[11px] text-muted-foreground">
                    {{ t.sub }}
                </p>
            </div>
        </div>
    </div>
</template>
