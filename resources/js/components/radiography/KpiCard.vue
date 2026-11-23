<script setup lang="ts">
/**
 * KpiCard — tarjeta compacta de indicador para la radiografía.
 *
 * Se usa en ReportesMensuales/Preview y en FondeosTab.
 *
 * Props:
 * - label: nombre del indicador.
 * - value: valor ya formateado.
 * - icon: icono opcional.
 * - tone: color del icono y del valor (teal por defecto).
 * - hint: nota bajo el valor.
 * - active: resalta la tarjeta (p. ej. cuando corresponde al filtro activo).
 */
import type { Component } from 'vue';

defineProps<{
    label: string;
    value: string;
    icon?: Component;
    tone?: 'teal' | 'red' | 'amber' | 'green' | 'blue' | 'neutral';
    hint?: string;
    active?: boolean;
}>();

/** Clases de la placa del icono por tono. */
const toneIconClasses: Record<string, string> = {
    teal: 'bg-teal-50 text-teal-700 border-teal-100 dark:bg-teal-500/10 dark:text-teal-300 dark:border-teal-500/20',
    red: 'bg-red-50 text-red-700 border-red-100 dark:bg-red-500/10 dark:text-red-300 dark:border-red-500/20',
    amber: 'bg-amber-50 text-amber-700 border-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:border-amber-500/20',
    green: 'bg-emerald-50 text-emerald-700 border-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:border-emerald-500/20',
    blue: 'bg-sky-50 text-sky-700 border-sky-100 dark:bg-sky-500/10 dark:text-sky-300 dark:border-sky-500/20',
    neutral: 'bg-muted/50 text-foreground/80 border-border   ',
};

/** Clases del valor por tono. */
const toneValueClasses: Record<string, string> = {
    teal: 'text-foreground ',
    red: 'text-red-700 dark:text-red-300',
    amber: 'text-amber-600 dark:text-amber-300',
    green: 'text-emerald-700 dark:text-emerald-300',
    blue: 'text-foreground ',
    neutral: 'text-foreground ',
};
</script>

<template>
    <div
        class="rounded-2xl border bg-card p-4 shadow-sm transition hover:shadow-md"
        :class="active ? 'border-primary/30 ring-2 ring-primary/20' : ''"
    >
        <div class="flex items-center justify-between gap-2">
            <p
                class="truncate text-[10.5px] font-bold tracking-wider text-muted-foreground uppercase"
            >
                {{ label }}
            </p>
            <span
                v-if="icon"
                class="flex size-7 shrink-0 items-center justify-center rounded-lg border"
                :class="toneIconClasses[tone ?? 'teal']"
            >
                <component :is="icon" class="size-3.5" />
            </span>
        </div>
        <p
            class="mt-1.5 text-base font-bold break-words tabular-nums sm:text-lg"
            :class="toneValueClasses[tone ?? 'teal']"
            :title="value"
        >
            {{ value }}
        </p>
        <p
            v-if="hint"
            class="mt-0.5 truncate text-[10.5px] text-muted-foreground"
        >
            {{ hint }}
        </p>
    </div>
</template>
