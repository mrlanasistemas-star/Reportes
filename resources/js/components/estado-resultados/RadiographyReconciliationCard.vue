<script setup lang="ts">
import { CheckCircle2, Equal, Info, TriangleAlert } from 'lucide-vue-next';
import { isZero, mxn } from './types';
import type { Dataset } from './types';

defineProps<{ rec: Dataset['radiography_reconciliation'] }>();
</script>

<template>
    <section class="rounded-2xl border bg-card p-5 shadow-soft-xs">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h3 class="text-base font-extrabold">
                    ¿Cuadra con Radiografía?
                </h3>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    El EBITDA de sucursales (nivel 1) debe ser igual al de la
                    Radiografía del mismo periodo.
                </p>
            </div>
            <span
                class="inline-flex shrink-0 items-center gap-1 rounded-full px-2.5 py-1 text-xs font-bold"
                :class="
                    rec.ok
                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                        : 'bg-amber-100 text-amber-900 dark:bg-amber-950/60 dark:text-amber-300'
                "
            >
                <CheckCircle2 v-if="rec.ok" class="size-3.5" />
                <TriangleAlert v-else class="size-3.5" />
                {{ rec.ok ? 'Cuadra en $0.00' : 'No cuadra' }}
            </span>
        </div>

        <div
            class="mt-4 grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-2"
        >
            <div class="rounded-xl bg-muted/50 p-3">
                <p class="text-xs text-muted-foreground">
                    Estado de Resultados
                </p>
                <p class="truncate text-lg font-extrabold tabular-nums">
                    {{ mxn(rec.er_ebitda_sucursales) }}
                </p>
            </div>
            <span
                class="flex size-8 items-center justify-center rounded-full"
                :class="
                    isZero(rec.difference)
                        ? 'bg-emerald-600 text-white dark:bg-emerald-500 dark:text-emerald-950'
                        : 'bg-amber-500 text-white'
                "
                :aria-label="isZero(rec.difference) ? 'Iguales' : 'Diferentes'"
            >
                <Equal v-if="isZero(rec.difference)" class="size-4" />
                <span v-else class="text-sm font-extrabold">≠</span>
            </span>
            <div class="rounded-xl bg-muted/50 p-3">
                <p class="text-xs text-muted-foreground">Radiografía</p>
                <p class="truncate text-lg font-extrabold tabular-nums">
                    {{ mxn(rec.radiografia_ebitda) }}
                </p>
            </div>
        </div>
        <p
            v-if="!isZero(rec.difference)"
            class="mt-2 text-center text-xs font-semibold text-amber-800 dark:text-amber-300"
        >
            Diferencia {{ mxn(rec.difference) }}
        </p>

        <table class="mt-4 w-full text-xs">
            <thead>
                <tr class="text-left text-muted-foreground">
                    <th class="pb-1.5 font-semibold">Componente</th>
                    <th class="pb-1.5 text-right font-semibold">ER</th>
                    <th class="pb-1.5 text-right font-semibold">Radiografía</th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="c in rec.components"
                    :key="c.label"
                    class="border-t transition-colors hover:bg-muted/40"
                >
                    <td class="py-2 pr-2">{{ c.label }}</td>
                    <td class="py-2 text-right tabular-nums">
                        {{ mxn(c.er) }}
                    </td>
                    <td
                        class="py-2 text-right tabular-nums"
                        :class="
                            c.er === c.radiografia
                                ? ''
                                : 'font-bold text-amber-700 dark:text-amber-400'
                        "
                    >
                        {{ mxn(c.radiografia) }}
                    </td>
                </tr>
            </tbody>
        </table>

        <ul
            v-if="rec.explanations.length"
            class="mt-3 space-y-1 rounded-xl border border-amber-200 bg-amber-50/70 p-3 text-xs text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
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
            class="mt-2 flex gap-1.5 text-xs leading-relaxed text-muted-foreground"
        >
            <Info class="mt-0.5 size-3.5 shrink-0" /> {{ t }}
        </p>
    </section>
</template>
