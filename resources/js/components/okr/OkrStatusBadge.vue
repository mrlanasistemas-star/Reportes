<script setup lang="ts">
/**
 * OkrStatusBadge — insignia semántica del módulo OKR (sección AS): semáforo
 * de salud, estado del ciclo de vida o resultado final. Colores suaves; el
 * texto siempre acompaña al color (accesibilidad, nunca solo color). Si el
 * valor es nulo o desconocido muestra "—".
 *
 * Se usa en OkrRiskProjectionPanel, OkrWeeklyHistory, OkrGestorTable,
 * OkrKpiResultsTable, OkrObjectiveSummary y OkrTrackingHeader, entre otros.
 *
 * Props:
 * - kind: 'lifecycle' (borrador/activo/cerrado/cancelado), 'health'
 *   (semáforo) o 'final' (cumplido/parcial/no cumplido).
 * - value: clave del estado que llega del servidor (o null).
 */
import {
    AlertTriangle,
    CheckCircle2,
    Circle,
    TrendingDown,
    TrendingUp,
} from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    kind: 'lifecycle' | 'health' | 'final';
    value: string | null;
}>();

/** Etiqueta y colores por estado del ciclo de vida. */
const lifecycleMap: Record<string, { label: string; class: string }> = {
    draft: {
        label: 'Borrador',
        class: 'bg-muted text-muted-foreground border-border',
    },
    active: {
        label: 'Activo',
        class: 'bg-primary/10 text-primary border-primary/20',
    },
    closed: {
        label: 'Cerrado',
        class: 'bg-slate-500/10 text-slate-600 border-slate-500/20 dark:text-slate-300',
    },
    cancelled: {
        label: 'Cancelado',
        class: 'bg-destructive/10 text-destructive border-destructive/20',
    },
};

/** Etiqueta, colores e ícono por estado del semáforo de salud. */
const healthMap: Record<string, { label: string; class: string; icon: any }> = {
    ahead: {
        label: 'Adelantado',
        class: 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20 dark:text-emerald-300',
        icon: TrendingUp,
    },
    on_track: {
        label: 'En trayectoria',
        class: 'bg-sky-500/10 text-sky-700 border-sky-500/20 dark:text-sky-300',
        icon: CheckCircle2,
    },
    risk: {
        label: 'En riesgo',
        class: 'bg-amber-500/10 text-amber-700 border-amber-500/20 dark:text-amber-300',
        icon: AlertTriangle,
    },
    off_track: {
        label: 'Fuera de trayectoria',
        class: 'bg-rose-500/10 text-rose-700 border-rose-500/20 dark:text-rose-300',
        icon: TrendingDown,
    },
};

/** Etiqueta y colores por resultado final del OKR. */
const finalMap: Record<string, { label: string; class: string }> = {
    completed: {
        label: 'Cumplido',
        class: 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20 dark:text-emerald-300',
    },
    partially_completed: {
        label: 'Parcialmente cumplido',
        class: 'bg-amber-500/10 text-amber-700 border-amber-500/20 dark:text-amber-300',
    },
    not_completed: {
        label: 'No cumplido',
        class: 'bg-rose-500/10 text-rose-700 border-rose-500/20 dark:text-rose-300',
    },
};

/** Configuración de la insignia según kind y value; null si no aplica. */
const resolved = computed(() => {
    if (!props.value) {
        return null;
    }

    if (props.kind === 'lifecycle') {
        return lifecycleMap[props.value] ?? null;
    }

    if (props.kind === 'final') {
        return finalMap[props.value] ?? null;
    }

    return healthMap[props.value] ?? null;
});
</script>

<template>
    <span
        v-if="resolved"
        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition"
        :class="resolved.class"
    >
        <component
            :is="(resolved as any).icon"
            v-if="(resolved as any).icon"
            class="size-3.5"
        />
        {{ resolved.label }}
    </span>
    <span
        v-else
        class="inline-flex items-center gap-1 text-xs text-muted-foreground"
    >
        <Circle class="size-3" /> —
    </span>
</template>
