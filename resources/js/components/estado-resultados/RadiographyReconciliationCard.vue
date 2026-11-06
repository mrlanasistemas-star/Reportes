<script setup lang="ts">
import { AlertTriangle, CheckCircle2, Info } from 'lucide-vue-next';
import { isZero, mxn } from './types';
import type { Dataset } from './types';

defineProps<{ rec: Dataset['radiography_reconciliation'] }>();
</script>

<template>
    <div class="rounded-2xl border bg-card p-4 shadow-soft-xs">
        <div class="flex items-start justify-between gap-3">
            <div>
                <p
                    class="text-[11px] font-bold tracking-wider text-muted-foreground uppercase"
                >
                    Conciliación con Radiografía
                </p>
                <p class="text-sm font-semibold">
                    EBITDA sucursales: Estado de Resultados vs Radiografía
                </p>
            </div>
            <span
                class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold"
                :class="
                    rec.ok
                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300'
                        : 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-300'
                "
            >
                <CheckCircle2 v-if="rec.ok" class="size-3.5" />
                <AlertTriangle v-else class="size-3.5" />
                {{
                    rec.ok
                        ? 'Cuadra en $0.00'
                        : 'Diferencia ' + mxn(rec.difference)
                }}
            </span>
        </div>

        <div class="mt-3 grid grid-cols-3 gap-2 text-center">
            <div
                class="rounded-xl bg-emerald-50/70 p-2.5 dark:bg-emerald-950/20"
            >
                <p class="text-[10px] text-muted-foreground uppercase">
                    Estado de Resultados
                </p>
                <p class="text-sm font-extrabold tabular-nums">
                    {{ mxn(rec.er_ebitda_sucursales) }}
                </p>
            </div>
            <div class="rounded-xl bg-cyan-50/70 p-2.5 dark:bg-cyan-950/20">
                <p class="text-[10px] text-muted-foreground uppercase">
                    Radiografía
                </p>
                <p class="text-sm font-extrabold tabular-nums">
                    {{ mxn(rec.radiografia_ebitda) }}
                </p>
            </div>
            <div
                class="rounded-xl p-2.5"
                :class="
                    isZero(rec.difference)
                        ? 'bg-emerald-600 text-white'
                        : 'bg-amber-500 text-white'
                "
            >
                <p class="text-[10px] uppercase opacity-80">Diferencia</p>
                <p class="text-sm font-extrabold tabular-nums">
                    {{ mxn(rec.difference) }}
                </p>
            </div>
        </div>

        <table class="mt-3 w-full text-xs">
            <thead>
                <tr
                    class="text-left text-[10px] text-muted-foreground uppercase"
                >
                    <th class="py-1 font-semibold">Componente</th>
                    <th class="py-1 text-right font-semibold">ER</th>
                    <th class="py-1 text-right font-semibold">Radiografía</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="c in rec.components" :key="c.label" class="border-t">
                    <td class="py-1.5">
                        {{ c.label }}
                    </td>
                    <td class="py-1.5 text-right tabular-nums">
                        {{ mxn(c.er) }}
                    </td>
                    <td
                        class="py-1.5 text-right tabular-nums"
                        :class="
                            c.er === c.radiografia
                                ? ''
                                : 'font-bold text-amber-700'
                        "
                    >
                        {{ mxn(c.radiografia) }}
                    </td>
                </tr>
            </tbody>
        </table>

        <ul
            v-if="rec.explanations.length"
            class="mt-3 space-y-1 rounded-xl border border-amber-200 bg-amber-50/70 p-2.5 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
        >
            <li
                v-for="e in rec.explanations"
                :key="e.label"
                class="flex justify-between gap-2"
            >
                <span>{{ e.label }}</span
                ><span class="font-bold tabular-nums">{{ mxn(e.amount) }}</span>
            </li>
        </ul>
        <p
            v-for="t in rec.informative"
            :key="t"
            class="mt-2 flex gap-1.5 text-xs text-muted-foreground"
        >
            <Info class="mt-0.5 size-3.5 shrink-0" /> {{ t }}
        </p>
    </div>
</template>
