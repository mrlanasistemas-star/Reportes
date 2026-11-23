<script setup lang="ts">
/**
 * OkrCheckInDialog — diálogo "Check-in semanal" (docs/imagenesOKR/11.png):
 * bloqueo principal, acción correctiva, responsable, fecha de revisión y
 * resultados manuales (manual_results[]). Nunca muestra un input manual para
 * un KPI automático (ese valor viene solo de Reportería). Se usa en
 * pages/Okr/Show.
 *
 * Props:
 * - objectiveId: id del OKR.
 * - keyResults: KR del OKR (los no automáticos piden su resultado).
 * - users: usuarios elegibles como responsables de la acción.
 * - alreadyCheckedInThisWeek: si ya hay check-in esta semana (solo muestra aviso).
 *
 * v-model:
 * - open: abre/cierra el diálogo.
 *
 * Emits:
 * - saved: el check-in se registró (el diálogo ya se cerró).
 */
import { useForm } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import DatePickerField from '@/components/forms/DatePickerField.vue';
import SearchableSelect from '@/components/forms/SearchableSelect.vue';
import TextareaField from '@/components/forms/TextareaField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatByUnit, toLocalIsoDate } from '@/lib/okrFormat';

const props = defineProps<{
    objectiveId: number;
    keyResults: any[];
    users: { id: number; name: string }[];
    alreadyCheckedInThisWeek: boolean;
}>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ (e: 'saved'): void }>();

// El backend exige que la revisión sea hoy o después (after_or_equal:today):
// el calendario ya no deja elegir días pasados. Se recalcula cada vez que se
// abre el diálogo: si la página quedó abierta de un día para otro, el mínimo
// no debe seguir siendo "ayer".
const todayIso = ref(toLocalIsoDate());

/** Formulario Inertia del check-in. */
const form = useForm({
    main_blocker: '',
    corrective_action: '',
    action_responsible_user_id: null as number | null,
    action_due_date: null as string | null,
    manual_results: [] as { key_result_id: number; value: number }[],
});
/** Resultado manual capturado por KR (texto; '' = sin capturar). */
const manualValues = reactive<Record<number, string>>({});

/** Al abrir, reinicia el formulario y vacía los resultados manuales. */
watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    todayIso.value = toLocalIsoDate();
    form.reset();
    form.clearErrors();

    for (const kr of props.keyResults.filter(
        (k) => k.kpi.automation !== 'automatic',
    )) {
        manualValues[kr.id] = '';
    }
});

/** Arma manual_results con los valores capturados y envía el check-in. */
function submit() {
    form.manual_results = props.keyResults
        .filter(
            (kr) =>
                kr.kpi.automation !== 'automatic' && manualValues[kr.id] !== '',
        )
        .map((kr) => ({
            key_result_id: kr.id,
            value: Number(manualValues[kr.id]),
        }));

    form.post(`/okr/${props.objectiveId}/check-ins`, {
        onSuccess: () => {
            open.value = false;
            emit('saved');
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="max-w-lg"
            @pointer-down-outside="(e) => e.preventDefault()"
        >
            <DialogHeader>
                <DialogTitle>Check-in semanal</DialogTitle>
                <DialogDescription
                    >Registra el bloqueo principal y la acción correctiva de
                    esta semana.</DialogDescription
                >
            </DialogHeader>

            <div
                v-if="alreadyCheckedInThisWeek"
                class="rounded-xl bg-muted/40 p-3 text-sm text-muted-foreground"
            >
                Ya registraste tu check-in de esta semana. Podrás registrar uno
                nuevo cuando inicie la siguiente semana.
            </div>
            <div v-else class="space-y-4">
                <!-- Resultados manuales (solo KPI no automáticos) -->
                <div
                    v-if="
                        keyResults.some(
                            (kr) => kr.kpi.automation !== 'automatic',
                        )
                    "
                    class="space-y-3 rounded-xl border border-primary/20 bg-primary/5 p-3"
                >
                    <p
                        class="text-xs font-bold tracking-wide text-primary uppercase"
                    >
                        Resultados manuales de esta semana
                    </p>
                    <div
                        v-for="kr in keyResults.filter(
                            (k) => k.kpi.automation !== 'automatic',
                        )"
                        :key="kr.id"
                        class="space-y-1"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-x-2 text-xs"
                        >
                            <label
                                :for="`checkin-kr-${kr.id}`"
                                class="font-semibold text-foreground"
                                >{{ kr.kpi.name }}</label
                            >
                            <span class="text-muted-foreground"
                                >Anterior:
                                {{
                                    formatByUnit(kr.current_value, kr.kpi.unit)
                                }}</span
                            >
                        </div>
                        <input
                            :id="`checkin-kr-${kr.id}`"
                            v-model="manualValues[kr.id]"
                            type="number"
                            inputmode="decimal"
                            step="0.01"
                            placeholder="Resultado actual"
                            class="app-input h-9 text-sm"
                            @wheel.passive="
                                ($event.target as HTMLInputElement).blur()
                            "
                        />
                    </div>
                </div>

                <TextareaField
                    v-model="form.main_blocker"
                    label="Bloqueo principal"
                    :rows="2"
                />
                <TextareaField
                    v-model="form.corrective_action"
                    label="Acción correctiva"
                    :rows="2"
                />
                <div class="grid gap-3 sm:grid-cols-2">
                    <SearchableSelect
                        v-model="form.action_responsible_user_id as any"
                        label="Responsable"
                        placeholder="Selecciona"
                        :options="users"
                        label-key="name"
                        secondary-key="__none"
                        allow-null
                        null-label="Sin asignar"
                    />
                    <DatePickerField
                        v-model="form.action_due_date"
                        label="Fecha de revisión"
                        :min="todayIso"
                        clearable
                        :error="form.errors.action_due_date"
                    />
                </div>
                <p
                    v-if="Object.keys(form.errors).length"
                    role="alert"
                    class="text-xs font-semibold text-destructive"
                >
                    <span v-for="(err, key) in form.errors" :key="key"
                        >{{ err }}<br
                    /></span>
                </p>
            </div>

            <DialogFooter v-if="!alreadyCheckedInThisWeek">
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="open = false"
                    >Cancelar</Button
                >
                <Button
                    type="button"
                    :disabled="form.processing"
                    @click="submit"
                    >{{
                        form.processing ? 'Registrando…' : 'Registrar check-in'
                    }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
