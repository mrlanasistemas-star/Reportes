<script setup lang="ts">
import { ChevronDown } from 'lucide-vue-next'
import { ref } from 'vue'
import { statusClasses } from '@/components/asignaciones/rosterStatus'
import type { RosterMovementItem } from '@/types/asignaciones'

defineProps<{
    item: RosterMovementItem
    /** Texto antes de la sucursal (p. ej. "Última sucursal conocida"). */
    branchPrefix?: string
}>()

const showEvidence = ref(false)
</script>

<template>
    <article class="rounded-2xl border border-border/70 bg-background px-4 py-4">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="truncate font-semibold">{{ item.employee_name }}</p>
                <p class="mt-1 text-sm text-muted-foreground">
                    <template v-if="branchPrefix">{{ branchPrefix }}: </template>{{ item.branch_name || 'Sin sucursal asignada' }}
                    <template v-if="item.period_label"> · {{ item.period_label }}</template>
                </p>
            </div>
            <span v-if="item.status_label" class="shrink-0 rounded-full px-2 py-1 text-[10px] font-bold" :class="statusClasses(item.status)">
                {{ item.status_label }}
            </span>
        </div>

        <p v-if="item.reconciliation_note" class="mt-2 rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
            {{ item.reconciliation_note }}
        </p>

        <div v-if="item.evidence?.length" class="mt-2">
            <button
                type="button"
                class="inline-flex items-center gap-1 text-xs font-semibold text-muted-foreground transition hover:text-foreground"
                @click="showEvidence = !showEvidence"
            >
                <ChevronDown class="size-3.5 transition" :class="showEvidence ? 'rotate-180' : ''" />
                {{ showEvidence ? 'Ocultar fuentes' : `Ver fuentes (${item.evidence.length})` }}
            </button>
            <ul v-if="showEvidence" class="mt-2 space-y-1 text-xs text-muted-foreground">
                <li v-for="(e, i) in item.evidence" :key="i">
                    <span class="font-semibold text-foreground">{{ e.fuente }}:</span> {{ e.detalle }}
                </li>
            </ul>
        </div>

        <div v-if="$slots.actions" class="mt-3 flex flex-wrap gap-2">
            <slot name="actions" />
        </div>
    </article>
</template>
