<script setup lang="ts">
import { AlertTriangle } from 'lucide-vue-next'
import { computed } from 'vue'
import AppSectionCard from '@/components/app/AppSectionCard.vue'
import type { BranchHeadcount } from '@/types/asignaciones'

const props = defineProps<{
    distribution: BranchHeadcount[]
    /** 'plantilla' = personas del roster NOI; 'asignaciones' = registros de asignación (roster aún sin calcular). */
    basis: 'plantilla' | 'asignaciones'
    withoutBranch?: number
    pendingLeavers?: number
    massDropDetected?: boolean
    dropRatio?: number
}>()

const total = computed(() => props.distribution.reduce((sum, d) => sum + d.count, 0))
const max = computed(() => Math.max(1, ...props.distribution.map((d) => d.count)))
const subtitle = computed(() =>
    props.basis === 'plantilla'
        ? `${total.value} persona(s) de la plantilla NOI con sucursal${props.withoutBranch ? ` · ${props.withoutBranch} sin sucursal` : ''}. Cada persona cuenta una vez aunque tenga varios registros de asignación.`
        : `${total.value} registro(s) de asignación con sucursal. La plantilla del periodo aún no se calcula, así que estas cifras son registros, no personas.`,
)
</script>

<template>
    <AppSectionCard title="Colaboradores por sucursal" :subtitle="subtitle">
        <ul v-if="distribution.length" class="grid gap-x-8 gap-y-2 sm:grid-cols-2">
            <li v-for="d in distribution" :key="d.name" class="grid grid-cols-[minmax(0,10rem)_1fr_2.5rem] items-center gap-3 text-sm">
                <span class="truncate text-muted-foreground" :title="d.name">{{ d.name }}</span>
                <span class="h-2 overflow-hidden rounded-full bg-muted" aria-hidden="true">
                    <span class="block h-full rounded-full bg-primary/70" :style="{ width: `${(d.count / max) * 100}%` }" />
                </span>
                <span class="text-right font-semibold tabular-nums">{{ d.count }}</span>
            </li>
        </ul>
        <p v-else class="py-6 text-center text-sm text-muted-foreground">Sin colaboradores con sucursal en este periodo.</p>

        <div v-if="pendingLeavers || massDropDetected" class="mt-4 space-y-2">
            <p v-if="pendingLeavers" class="flex items-start gap-1.5 rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">
                <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                <span>
                    {{ pendingLeavers }} ausencia(s) de nómina están por conciliar y <strong>no</strong> cuentan como bajas.
                </span>
            </p>
            <p v-if="massDropDetected" class="flex items-start gap-1.5 rounded-xl bg-rose-50 px-3 py-2 text-xs text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">
                <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                <span>
                    La plantilla cayó {{ Math.round((dropRatio ?? 0) * 100) }}% respecto al periodo anterior: sugiere un archivo NOI incompleto, no bajas
                    reales simultáneas. Verifica la carga antes de tratarlas como definitivas.
                </span>
            </p>
        </div>
    </AppSectionCard>
</template>
