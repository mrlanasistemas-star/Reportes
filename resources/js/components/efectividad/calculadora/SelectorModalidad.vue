<script setup lang="ts">
import {
    CalendarCheck,
    CalendarDays,
    CalendarRange,
    Sun,
} from 'lucide-vue-next';
import type { Modo } from './types';

defineProps<{ disabled?: boolean }>();

const modo = defineModel<Modo>({ required: true });

const MODOS: Array<{
    valor: Modo;
    titulo: string;
    texto: string;
    icono: typeof Sun;
}> = [
    {
        valor: 'DIARIO',
        titulo: 'Diario',
        texto: 'Cobranza de un solo día. Usa la cartera del día anterior como base y la cartera de ese día.',
        icono: Sun,
    },
    {
        valor: 'ACUMULADO',
        titulo: 'Acumulado',
        texto: 'Del lunes de la semana hasta el día elegido.',
        icono: CalendarCheck,
    },
    {
        valor: 'SEMANAL',
        titulo: 'Semanal',
        texto: 'Lunes a domingo. Requiere las 8 carteras (domingo base a domingo de corte).',
        icono: CalendarDays,
    },
    {
        valor: 'MENSUAL',
        titulo: 'Mensual',
        texto: 'Suma las semanas completas del mes. Los saldos de atraso/vencido son los del último corte.',
        icono: CalendarRange,
    },
];
</script>

<template>
    <div
        class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
        role="radiogroup"
        aria-label="Modalidad"
    >
        <button
            v-for="m in MODOS"
            :key="m.valor"
            type="button"
            role="radio"
            :aria-checked="modo === m.valor"
            :disabled="disabled"
            class="group flex gap-3 rounded-2xl border p-4 text-left transition-all duration-200 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
            :class="
                modo === m.valor
                    ? 'border-primary bg-primary/5 shadow-sm ring-1 ring-primary'
                    : 'hover:-translate-y-0.5 hover:border-primary/40 hover:bg-muted/40 hover:shadow-sm'
            "
            @click="modo = m.valor"
        >
            <span
                class="grid size-9 shrink-0 place-items-center rounded-xl transition-colors"
                :class="
                    modo === m.valor
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-muted text-muted-foreground'
                "
                aria-hidden="true"
            >
                <component :is="m.icono" class="size-4.5" />
            </span>
            <span>
                <span class="block font-medium">{{ m.titulo }}</span>
                <span class="mt-0.5 block text-xs text-muted-foreground">{{
                    m.texto
                }}</span>
            </span>
        </button>
    </div>
</template>
