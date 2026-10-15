<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { History } from 'lucide-vue-next'
import type { LaborHistoryRow } from '@/types/asignaciones'

defineProps<{
    rows: LaborHistoryRow[]
    selectedPeriodId: number | null
}>()

function open(periodId: number) {
    router.get('/asignaciones-empleado-sucursal', { period_id: periodId }, { preserveScroll: false })
}
</script>

<template>
    <section class="app-card overflow-hidden">
        <div class="border-b px-4 py-4 sm:px-6 sm:py-5">
            <div class="flex items-center gap-2">
                <History class="size-4 text-indigo-500" />
                <h2 class="text-lg font-bold tracking-tight">Histórico laboral</h2>
            </div>
            <p class="mt-1 text-sm text-muted-foreground">Plantilla y movimientos de cada periodo mensual, tal como se calcularon. Solo las bajas confirmadas cuentan en la rotación.</p>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full min-w-[560px] text-sm">
                <thead class="bg-muted/40 text-left text-xs text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold sm:px-6">Periodo</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Plantilla NOI</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Altas</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Bajas confirmadas</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Pendientes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    <tr
                        v-for="row in rows"
                        :key="row.period_id"
                        class="cursor-pointer transition hover:bg-muted/40"
                        :class="row.period_id === selectedPeriodId ? 'bg-indigo-50/60 dark:bg-indigo-500/10' : ''"
                        @click="open(row.period_id)"
                    >
                        <td class="px-4 py-2.5 font-semibold sm:px-6">{{ row.label }}</td>
                        <template v-if="row.calculado">
                            <td class="px-3 py-2.5 text-right">{{ row.plantilla }}</td>
                            <td class="px-3 py-2.5 text-right text-emerald-600 dark:text-emerald-400">{{ row.altas }}</td>
                            <td class="px-3 py-2.5 text-right text-rose-600 dark:text-rose-400">{{ row.bajas_confirmadas }}</td>
                            <td class="px-3 py-2.5 text-right" :class="row.pendientes ? 'font-bold text-amber-600 dark:text-amber-400' : ''">{{ row.pendientes }}</td>
                        </template>
                        <td v-else colspan="4" class="px-3 py-2.5 text-right text-xs text-muted-foreground">Sin plantilla calculada</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
