<script setup lang="ts">
/**
 * OkrWeightSummary — resumen visual de la ponderación de los KR (sección V
 * del pedido): barra + estado (lista, excede o falta por asignar).
 * Se usa dentro de OkrWeightsDialog.
 *
 * Props:
 * - total: suma de los pesos asignados (en %); debe llegar a 100.
 */
import { AlertTriangle, CheckCircle2 } from 'lucide-vue-next';
import { computed } from 'vue';
import OkrProgressBar from '@/components/okr/OkrProgressBar.vue';

const props = defineProps<{ total: number }>();

/** Total redondeado a 2 decimales. */
const rounded = computed(() => Math.round(props.total * 100) / 100);
/** true cuando la ponderación suma 100 % (tolerancia de 0.01). */
const isValid = computed(() => Math.abs(rounded.value - 100) < 0.01);
/** true cuando la ponderación excede el 100 %. */
const isOver = computed(() => rounded.value > 100);
</script>

<template>
    <div
        class="rounded-2xl border p-4 transition-colors"
        :class="
            isValid
                ? 'border-emerald-500/20 bg-emerald-500/5'
                : 'border-amber-500/20 bg-amber-500/5'
        "
    >
        <div class="flex items-center justify-between gap-3">
            <p
                class="flex items-center gap-2 text-sm font-bold"
                :class="
                    isValid
                        ? 'text-emerald-700 dark:text-emerald-300'
                        : 'text-amber-700 dark:text-amber-300'
                "
            >
                <CheckCircle2 v-if="isValid" class="size-4" />
                <AlertTriangle v-else class="size-4" />
                Ponderación total
            </p>
            <p
                class="text-sm font-bold tabular-nums"
                :class="
                    isValid
                        ? 'text-emerald-700 dark:text-emerald-300'
                        : 'text-amber-700 dark:text-amber-300'
                "
            >
                {{ rounded.toFixed(2) }} / 100 %
            </p>
        </div>

        <div class="mt-2">
            <OkrProgressBar
                :value="Math.min(rounded, 100)"
                :tone="isValid ? 'success' : 'warning'"
            />
        </div>

        <p
            class="mt-2 text-xs"
            :class="
                isValid
                    ? 'text-emerald-700/80 dark:text-emerald-300/80'
                    : 'text-amber-700/80 dark:text-amber-300/80'
            "
        >
            <template v-if="isValid">Ponderación lista para activar.</template>
            <template v-else-if="isOver"
                >Excede por {{ (rounded - 100).toFixed(2) }}%.</template
            >
            <template v-else
                >Faltan {{ (100 - rounded).toFixed(2) }}% por asignar.</template
            >
        </p>
    </div>
</template>
