<script setup lang="ts">
/**
 * OkrEditGoalDialog — diálogo para editar la meta de UN Key Result (sección
 * 20 del pedido), nunca un formulario suelto debajo de la tabla. Muestra
 * valor anterior, nuevo y diferencia, y pide el motivo (queda en la
 * bitácora). Se usa en pages/Okr/Show.
 *
 * Props:
 * - objectiveId: id del OKR.
 * - keyResult: KR a editar (o null).
 *
 * v-model:
 * - open: abre/cierra el diálogo.
 */
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatByUnit } from '@/lib/okrFormat';

const props = defineProps<{ objectiveId: number; keyResult: any | null }>();
const open = defineModel<boolean>('open', { default: false });

/** Formulario Inertia: KR, nueva meta y motivo. */
const form = useForm({
    key_result_id: null as number | null,
    target_value: '',
    reason: '',
});

/**
 * Reinicia el formulario con la meta actual del KR al recibir otro KR y
 * también al reabrir el diálogo para el MISMO KR (antes quedaba la captura y
 * los errores de la vez anterior).
 */
watch([() => props.keyResult, open], ([kr, isOpen]) => {
    if (!kr || !isOpen) {
        return;
    }

    form.reset();
    form.clearErrors();
    form.key_result_id = kr.id;
    form.target_value = String(kr.target_value);
});

/** Diferencia entre la nueva meta y la actual (null si está vacía). */
const difference = computed(() => {
    if (!props.keyResult || form.target_value === '') {
        return null;
    }

    return Number(form.target_value) - Number(props.keyResult.target_value);
});

/** Guarda la nueva meta en PUT /okr/{id}/goal y cierra el diálogo. */
function submit() {
    form.put(`/okr/${props.objectiveId}/goal`, {
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="keyResult" class="max-w-md">
            <DialogHeader>
                <DialogTitle
                    >Editar meta — {{ keyResult.kpi.name }}</DialogTitle
                >
                <DialogDescription
                    >El cambio queda registrado en la bitácora con tu
                    motivo.</DialogDescription
                >
            </DialogHeader>

            <div class="space-y-3">
                <div
                    class="grid grid-cols-3 gap-2 rounded-xl border border-border bg-muted/20 p-3 text-center text-xs"
                >
                    <div>
                        <p class="text-muted-foreground">Anterior</p>
                        <p class="font-bold text-foreground tabular-nums">
                            {{
                                formatByUnit(
                                    keyResult.target_value,
                                    keyResult.kpi.unit,
                                )
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Nueva</p>
                        <p class="font-bold text-primary tabular-nums">
                            {{
                                form.target_value === ''
                                    ? '—'
                                    : formatByUnit(
                                          form.target_value,
                                          keyResult.kpi.unit,
                                      )
                            }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Diferencia</p>
                        <p
                            class="font-bold tabular-nums"
                            :class="
                                (difference ?? 0) >= 0
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : 'text-rose-600 dark:text-rose-400'
                            "
                        >
                            {{
                                difference === null
                                    ? '—'
                                    : (difference >= 0 ? '+' : '') +
                                      formatByUnit(
                                          difference,
                                          keyResult.kpi.unit,
                                      )
                            }}
                        </p>
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label
                        for="okr-edit-goal-target"
                        class="text-sm font-semibold text-foreground"
                        >Nueva meta</label
                    >
                    <input
                        id="okr-edit-goal-target"
                        v-model="form.target_value"
                        type="number"
                        inputmode="decimal"
                        step="0.01"
                        class="app-input"
                        :aria-invalid="
                            form.errors.target_value ? 'true' : undefined
                        "
                        @wheel.passive="
                            ($event.target as HTMLInputElement).blur()
                        "
                    />
                    <p
                        v-if="form.errors.target_value"
                        role="alert"
                        class="text-xs font-semibold text-destructive"
                    >
                        {{ form.errors.target_value }}
                    </p>
                </div>
                <div class="space-y-1.5">
                    <label
                        for="okr-edit-goal-reason"
                        class="text-sm font-semibold text-foreground"
                        >Motivo del cambio</label
                    >
                    <textarea
                        id="okr-edit-goal-reason"
                        v-model="form.reason"
                        rows="2"
                        placeholder="Obligatorio"
                        class="app-textarea"
                        :aria-invalid="form.errors.reason ? 'true' : undefined"
                    />
                    <p
                        v-if="form.errors.reason"
                        role="alert"
                        class="text-xs font-semibold text-destructive"
                    >
                        {{ form.errors.reason }}
                    </p>
                </div>
            </div>

            <DialogFooter>
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
                        form.processing ? 'Guardando…' : 'Guardar cambio'
                    }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
