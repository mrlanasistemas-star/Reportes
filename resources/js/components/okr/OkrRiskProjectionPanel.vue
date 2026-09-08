<script setup lang="ts">
// "Riesgo y proyección" — reproduce docs/imagenesOKR/1.png y 2.png.
import OkrStatusBadge from '@/components/okr/OkrStatusBadge.vue'

defineProps<{
    rows: { id: number; label: string; health_status: string | null; projected_compliance: number | null }[]
}>()
</script>

<template>
    <div class="app-card p-4">
        <p class="mb-3 text-sm font-bold text-foreground">Riesgo y proyección</p>
        <div v-if="rows.length" class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead class="text-muted-foreground">
                    <tr>
                        <th class="pb-2 text-left font-semibold">#</th>
                        <th class="pb-2 text-left font-semibold">Sucursal</th>
                        <th class="pb-2 text-left font-semibold">Estado</th>
                        <th class="pb-2 text-right font-semibold">Proyección de cierre</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in rows" :key="row.id" class="border-t border-border">
                        <td class="py-2 text-muted-foreground">{{ i + 1 }}</td>
                        <td class="max-w-[140px] truncate py-2 font-medium text-foreground">{{ row.label }}</td>
                        <td class="py-2"><OkrStatusBadge kind="health" :value="row.health_status" /></td>
                        <td class="py-2 text-right font-semibold tabular-nums text-foreground">{{ row.projected_compliance === null ? '—' : row.projected_compliance + '%' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p v-else class="py-6 text-center text-xs text-muted-foreground">Sin OKR activos en este alcance.</p>
    </div>
</template>
