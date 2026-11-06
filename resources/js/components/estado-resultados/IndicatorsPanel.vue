<script setup lang="ts">
import { Info } from 'lucide-vue-next';
import { isNegative, mxn, pct } from './types';
import type { Indicator } from './types';

defineProps<{ financial: Indicator[]; informative: Indicator[] }>();

function show(i: Indicator): string {
    if (i.value === null) {
        return 'Sin información';
    }

    return i.unit === 'MXN'
        ? mxn(i.value)
        : i.unit === '%'
          ? pct(i.value)
          : i.unit === 'n'
            ? Number(i.value).toLocaleString('es-MX')
            : i.value;
}
</script>

<template>
    <div class="space-y-5">
        <div>
            <p class="mb-2 text-xs font-bold text-muted-foreground">
                Indicadores financieros
            </p>
            <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-5">
                <div
                    v-for="i in financial"
                    :key="i.key"
                    class="rounded-2xl border bg-card p-3 shadow-soft-xs"
                    :class="i.value === null ? 'opacity-60' : ''"
                >
                    <p class="text-xs font-semibold text-muted-foreground">
                        {{ i.label }}
                    </p>
                    <p
                        class="mt-1 text-xl font-extrabold tabular-nums"
                        :class="
                            i.value === null
                                ? 'text-sm text-muted-foreground'
                                : isNegative(i.value)
                                  ? 'text-rose-600'
                                  : 'text-emerald-700 dark:text-emerald-400'
                        "
                    >
                        {{
                            i.value === null ? 'Sólo consolidado' : pct(i.value)
                        }}
                    </p>
                    <div
                        v-if="i.value !== null"
                        class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted"
                    >
                        <div
                            class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-cyan-500 transition-all duration-700"
                            :style="{
                                width: `${Math.min(100, Math.abs(Number(i.value)))}%`,
                            }"
                        />
                    </div>
                </div>
            </div>
        </div>
        <div>
            <p
                class="mb-2 flex items-center gap-1.5 text-xs font-bold text-muted-foreground"
            >
                Indicadores operativos
                <span
                    class="rounded-full bg-muted px-2 py-px text-[10px] normal-case"
                    >informativos</span
                >
            </p>
            <div class="grid gap-2.5 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="i in informative"
                    :key="i.key"
                    class="rounded-2xl border bg-card p-3"
                >
                    <p class="text-xs font-semibold text-muted-foreground">
                        {{ i.label }}
                    </p>
                    <p
                        class="mt-1 text-lg font-bold tabular-nums"
                        :class="
                            i.value === null
                                ? 'text-sm text-muted-foreground'
                                : ''
                        "
                    >
                        {{ show(i) }}
                    </p>
                </div>
            </div>
            <p class="mt-2 flex gap-1.5 text-xs text-muted-foreground">
                <Info class="mt-0.5 size-3.5 shrink-0" /> Cartera, mora,
                colocación, recuperación, plantilla y rotación son estadísticos:
                no entran a la fórmula del Estado de Resultados.
            </p>
        </div>
    </div>
</template>
