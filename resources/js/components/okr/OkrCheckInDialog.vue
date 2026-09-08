<script setup lang="ts">
// "Check-in semanal" — Dialog (docs/imagenesOKR/11.png). Conserva el fix de
// resultados manuales (manual_results[]) — nunca muestra un input manual
// para un KPI automático (ese valor viene solo de Reportería).
import { reactive, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import SearchableSelect from '@/components/forms/SearchableSelect.vue'
import TextareaField from '@/components/forms/TextareaField.vue'
import DatePickerField from '@/components/forms/DatePickerField.vue'
import { formatByUnit } from '@/lib/okrFormat'

const props = defineProps<{
    objectiveId: number
    keyResults: any[]
    users: { id: number; name: string }[]
    alreadyCheckedInThisWeek: boolean
}>()
const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{ (e: 'saved'): void }>()

const form = useForm({
    main_blocker: '', corrective_action: '', action_responsible_user_id: null as number | null, action_due_date: null as string | null,
    manual_results: [] as { key_result_id: number; value: number }[],
})
const manualValues = reactive<Record<number, string>>({})

watch(open, (isOpen) => {
    if (!isOpen) return
    form.reset()
    form.clearErrors()
    for (const kr of props.keyResults.filter((k) => k.kpi.automation !== 'automatic')) {
        manualValues[kr.id] = ''
    }
})

function submit() {
    form.manual_results = props.keyResults
        .filter((kr) => kr.kpi.automation !== 'automatic' && manualValues[kr.id] !== '')
        .map((kr) => ({ key_result_id: kr.id, value: Number(manualValues[kr.id]) }))

    form.post(`/okr/${props.objectiveId}/check-ins`, {
        onSuccess: () => { open.value = false; emit('saved') },
    })
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-lg">
            <DialogHeader>
                <DialogTitle>Check-in semanal</DialogTitle>
                <DialogDescription>Registra el bloqueo principal y la acción correctiva de esta semana.</DialogDescription>
            </DialogHeader>

            <div v-if="alreadyCheckedInThisWeek" class="rounded-xl bg-muted/40 p-3 text-sm text-muted-foreground">
                Ya registraste tu check-in de esta semana. Podrás registrar uno nuevo cuando inicie la siguiente semana.
            </div>
            <div v-else class="space-y-4">
                <div v-if="keyResults.some((kr) => kr.kpi.automation !== 'automatic')" class="space-y-3 rounded-xl border border-primary/20 bg-primary/5 p-3">
                    <p class="text-xs font-bold uppercase tracking-wide text-primary">Resultados manuales de esta semana</p>
                    <div v-for="kr in keyResults.filter((k) => k.kpi.automation !== 'automatic')" :key="kr.id" class="space-y-1">
                        <div class="flex items-center justify-between text-xs">
                            <span class="font-semibold text-foreground">{{ kr.kpi.name }}</span>
                            <span class="text-muted-foreground">Anterior: {{ formatByUnit(kr.current_value, kr.kpi.unit) }}</span>
                        </div>
                        <input v-model="manualValues[kr.id]" type="number" step="0.01" placeholder="Resultado actual" class="app-input h-9 text-sm">
                    </div>
                </div>

                <TextareaField v-model="form.main_blocker" label="Bloqueo principal" :rows="2" />
                <TextareaField v-model="form.corrective_action" label="Acción correctiva" :rows="2" />
                <div class="grid gap-3 sm:grid-cols-2">
                    <SearchableSelect v-model="form.action_responsible_user_id as any" label="Responsable" placeholder="Selecciona" :options="users" label-key="name" secondary-key="__none" allow-null null-label="Sin asignar" />
                    <DatePickerField v-model="form.action_due_date" label="Fecha de revisión" clearable />
                </div>
                <p v-if="Object.keys(form.errors).length" class="text-xs font-semibold text-destructive">
                    <span v-for="(err, key) in form.errors" :key="key">{{ err }}<br></span>
                </p>
            </div>

            <DialogFooter v-if="!alreadyCheckedInThisWeek">
                <Button variant="outline" @click="open = false">Cancelar</Button>
                <Button :disabled="form.processing" @click="submit">Registrar check-in</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
