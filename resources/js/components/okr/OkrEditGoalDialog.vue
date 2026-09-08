<script setup lang="ts">
// Editar meta de UN Key Result (sección 20 del pedido) — en Dialog, nunca un
// form suelto debajo de la tabla. Muestra valor anterior/nuevo/diferencia.
import { computed, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { formatByUnit } from '@/lib/okrFormat'

const props = defineProps<{ objectiveId: number; keyResult: any | null }>()
const open = defineModel<boolean>('open', { default: false })

const form = useForm({ key_result_id: null as number | null, target_value: '', reason: '' })

watch(() => props.keyResult, (kr) => {
    if (!kr) return
    form.reset()
    form.clearErrors()
    form.key_result_id = kr.id
    form.target_value = String(kr.target_value)
})

const difference = computed(() => {
    if (!props.keyResult || form.target_value === '') return null

    return Number(form.target_value) - Number(props.keyResult.target_value)
})

function submit() {
    form.put(`/okr/${props.objectiveId}/goal`, { onSuccess: () => { open.value = false } })
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="keyResult" class="max-w-md">
            <DialogHeader>
                <DialogTitle>Editar meta — {{ keyResult.kpi.name }}</DialogTitle>
                <DialogDescription>El cambio queda registrado en la bitácora con tu motivo.</DialogDescription>
            </DialogHeader>

            <div class="space-y-3">
                <div class="grid grid-cols-3 gap-2 rounded-xl border border-border bg-muted/20 p-3 text-center text-xs">
                    <div>
                        <p class="text-muted-foreground">Anterior</p>
                        <p class="font-bold tabular-nums text-foreground">{{ formatByUnit(keyResult.target_value, keyResult.kpi.unit) }}</p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Nueva</p>
                        <p class="font-bold tabular-nums text-primary">{{ form.target_value === '' ? '—' : formatByUnit(form.target_value, keyResult.kpi.unit) }}</p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Diferencia</p>
                        <p class="font-bold tabular-nums" :class="(difference ?? 0) >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'">
                            {{ difference === null ? '—' : (difference >= 0 ? '+' : '') + formatByUnit(difference, keyResult.kpi.unit) }}
                        </p>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label class="text-sm font-semibold text-foreground">Nueva meta</label>
                    <input v-model="form.target_value" type="number" step="0.01" class="app-input">
                </div>
                <div class="space-y-1.5">
                    <label class="text-sm font-semibold text-foreground">Motivo del cambio</label>
                    <textarea v-model="form.reason" rows="2" placeholder="Obligatorio" class="app-textarea" />
                    <p v-if="form.errors.reason" class="text-xs font-semibold text-destructive">{{ form.errors.reason }}</p>
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancelar</Button>
                <Button :disabled="form.processing" @click="submit">Guardar cambio</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
