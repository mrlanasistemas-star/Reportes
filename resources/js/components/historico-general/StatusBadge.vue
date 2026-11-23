<script setup lang="ts">
/**
 * Insignia de estatus reutilizada en todo el flujo Histórico general. Traduce un código
 * de estado (pending, ready, blocked, running, success, failed, queued, consolidated…)
 * a una etiqueta en español y un color. Sin emits ni rutas.
 */
import { computed } from 'vue';

/** Props: `status` código de estado (default 'pending'); `label` texto que sobrescribe la etiqueta por defecto. */
const props = withDefaults(
    defineProps<{ status?: string | null; label?: string | null }>(),
    {
        status: 'pending',
        label: null,
    },
);

/** Estado efectivo: el recibido o 'pending' si viene null. */
const normalized = computed(() => props.status ?? 'pending');

/** Etiqueta visible: la prop `label` o la traducción del estado (si no hay traducción, el código crudo). */
const label = computed(
    () =>
        props.label ??
        (
            {
                pending: 'Pendiente',
                ready: 'Listo para generar',
                blocked: 'Bloqueado',
                running: 'En proceso',
                completed: 'Completo',
                generated: 'Generado',
                success: 'Terminado',
                error: 'Con error',
                processed: 'Procesado',
                failed: 'Con error',
                queued: 'En cola',
                warning: 'Advertencia',
                automatic: 'Automático',
                waiting: 'Esperando meses',
                ready_to_consolidate: 'Listo para consolidar',
                consolidated: 'Consolidado',
                resolved: 'Resuelto',
            } as Record<string, string>
        )[normalized.value] ??
        normalized.value,
);

/** Clases Tailwind de color según el estado (gris neutro si es desconocido). */
const badgeClass = computed(
    () =>
        (
            ({
                pending:
                    'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
                ready: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300',
                blocked:
                    'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300',
                running: 'border-info-border bg-info-soft text-info',
                completed:
                    'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
                generated:
                    'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
                success:
                    'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
                processed:
                    'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
                error: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300',
                failed: 'border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300',
                queued: 'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-300',
                warning:
                    'border-orange-200 bg-orange-50 text-orange-700 dark:border-orange-500/20 dark:bg-orange-500/10 dark:text-orange-300',
                automatic:
                    'border-violet-200 bg-violet-50 text-violet-700 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-300',
                waiting:
                    'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300',
                ready_to_consolidate:
                    'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300',
                consolidated:
                    'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
                resolved:
                    'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
            }) as Record<string, string>
        )[normalized.value] ??
        'border-slate-200 bg-slate-50 text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300',
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full border px-2.5 py-1 text-xs font-bold"
        :class="badgeClass"
    >
        <span class="size-1.5 rounded-full bg-current" />
        {{ label }}
    </span>
</template>
