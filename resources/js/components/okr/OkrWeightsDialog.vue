<script setup lang="ts">
// Redistribución de pesos EN BLOQUE (punto 2 de la auditoría 09-sep-2026) —
// bug de diseño corregido: antes solo se podía cambiar UN KR a la vez, lo que
// hacía IMPOSIBLE mover peso de un KR a otro (60/40 → 70/30) porque cada paso
// intermedio, por sí solo, ya rompía el 100%. Aquí se editan TODOS juntos y
// se guardan en una sola llamada — ver PUT /okr/{objective}/weights.
import { reactive, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import OkrWeightSummary from '@/components/okr/OkrWeightSummary.vue'

const props = defineProps<{ objectiveId: number; keyResults: any[] }>()
const open = defineModel<boolean>('open', { default: false })

const rows = reactive<{ key_result_id: number; label: string; weight: string }[]>([])
const form = useForm({ weights: [] as { key_result_id: number; weight: number }[], reason: '' })

watch(open, (isOpen) => {
    if (!isOpen) return
    form.reset()
    form.clearErrors()
    rows.splice(0, rows.length, ...props.keyResults.map((kr) => ({ key_result_id: kr.id, label: kr.kpi.name, weight: String(kr.weight) })))
})

function total() {
    return rows.reduce((sum, r) => sum + (Number(r.weight) || 0), 0)
}

function submit() {
    form.weights = rows.map((r) => ({ key_result_id: r.key_result_id, weight: Number(r.weight) }))
    form.put(`/okr/${props.objectiveId}/weights`, { onSuccess: () => { open.value = false } })
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-lg">
            <DialogHeader>
                <DialogTitle>Redistribuir ponderaciones</DialogTitle>
                <DialogDescription>Ajusta todos los pesos a la vez — la suma debe quedar en exactamente 100%.</DialogDescription>
            </DialogHeader>

            <div class="space-y-3">
                <div v-for="row in rows" :key="row.key_result_id" class="flex items-center justify-between gap-3">
                    <span class="min-w-0 flex-1 truncate text-sm font-medium text-foreground">{{ row.label }}</span>
                    <div class="relative w-28 shrink-0">
                        <input v-model="row.weight" type="number" step="0.01" min="0.01" max="100" class="app-input h-10 pr-7 text-right">
                        <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">%</span>
                    </div>
                </div>

                <OkrWeightSummary :total="total()" />

                <div class="space-y-1.5">
                    <label class="text-sm font-semibold text-foreground">Motivo del cambio</label>
                    <textarea v-model="form.reason" rows="2" placeholder="Ej. Reasignación por prioridad de recuperación." class="app-textarea" />
                    <p v-if="form.errors.reason" class="text-xs font-semibold text-destructive">{{ form.errors.reason }}</p>
                    <p v-if="form.errors.weights" class="text-xs font-semibold text-destructive">{{ form.errors.weights }}</p>
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancelar</Button>
                <Button :disabled="form.processing" @click="submit">Guardar distribución</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
