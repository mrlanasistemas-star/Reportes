<script setup lang="ts">
/**
 * OkrWeightsDialog — redistribución de pesos de los KR EN BLOQUE (punto 2 de
 * la auditoría 09-sep-2026). Bug de diseño corregido: antes solo se podía
 * cambiar UN KR a la vez, lo que hacía IMPOSIBLE mover peso de un KR a otro
 * (60/40 → 70/30) porque cada paso intermedio, por sí solo, ya rompía el
 * 100 %. Aquí se editan TODOS juntos y se guardan en una sola llamada
 * (PUT /okr/{objective}/weights). Se usa en pages/Okr/Show.
 *
 * Props:
 * - objectiveId: id del OKR.
 * - keyResults: KR actuales (id, kpi.name y weight).
 *
 * v-model:
 * - open: abre/cierra el diálogo.
 */
import { useForm } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import OkrWeightSummary from '@/components/okr/OkrWeightSummary.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{ objectiveId: number; keyResults: any[] }>();
const open = defineModel<boolean>('open', { default: false });

/** Filas editables (un peso en texto por KR). */
const rows = reactive<
    { key_result_id: number; label: string; weight: string }[]
>([]);
/** Formulario Inertia que se envía: pesos numéricos y motivo del cambio. */
const form = useForm({
    weights: [] as { key_result_id: number; weight: number }[],
    reason: '',
});

/** Al abrir, reinicia el formulario y carga los pesos actuales de cada KR. */
watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.reset();
    form.clearErrors();
    rows.splice(
        0,
        rows.length,
        ...props.keyResults.map((kr) => ({
            key_result_id: kr.id,
            label: kr.kpi.name,
            weight: String(kr.weight),
        })),
    );
});

/** Suma de los pesos capturados (los vacíos o inválidos cuentan como 0). */
function total() {
    return rows.reduce((sum, r) => sum + (Number(r.weight) || 0), 0);
}

/** Envía todos los pesos y el motivo; cierra el diálogo al guardar. */
function submit() {
    form.weights = rows.map((r) => ({
        key_result_id: r.key_result_id,
        weight: Number(r.weight),
    }));
    form.put(`/okr/${props.objectiveId}/weights`, {
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-lg">
            <DialogHeader>
                <DialogTitle>Redistribuir ponderaciones</DialogTitle>
                <DialogDescription
                    >Ajusta todos los pesos a la vez — la suma debe quedar en
                    exactamente 100%.</DialogDescription
                >
            </DialogHeader>

            <div class="space-y-3">
                <!-- Un campo de peso por KR -->
                <div
                    v-for="row in rows"
                    :key="row.key_result_id"
                    class="flex items-center justify-between gap-3"
                >
                    <label
                        :for="`okr-weight-${row.key_result_id}`"
                        class="min-w-0 flex-1 truncate text-sm font-medium text-foreground"
                        :title="row.label"
                        >{{ row.label }}</label
                    >
                    <div class="relative w-28 shrink-0">
                        <input
                            :id="`okr-weight-${row.key_result_id}`"
                            v-model="row.weight"
                            type="number"
                            inputmode="decimal"
                            step="0.01"
                            min="0.01"
                            max="100"
                            class="app-input h-10 pr-7 text-right"
                            @wheel.passive="
                                ($event.target as HTMLInputElement).blur()
                            "
                        />
                        <span
                            class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-xs text-muted-foreground"
                            aria-hidden="true"
                            >%</span
                        >
                    </div>
                </div>

                <OkrWeightSummary :total="total()" />

                <!-- Motivo del cambio y errores de validación -->
                <div class="space-y-1.5">
                    <label
                        for="okr-weights-reason"
                        class="text-sm font-semibold text-foreground"
                        >Motivo del cambio</label
                    >
                    <textarea
                        id="okr-weights-reason"
                        v-model="form.reason"
                        rows="2"
                        placeholder="Ej. Reasignación por prioridad de recuperación."
                        class="app-textarea"
                    />
                    <p
                        v-if="form.errors.reason"
                        role="alert"
                        class="text-xs font-semibold text-destructive"
                    >
                        {{ form.errors.reason }}
                    </p>
                    <p
                        v-if="form.errors.weights"
                        role="alert"
                        class="text-xs font-semibold text-destructive"
                    >
                        {{ form.errors.weights }}
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
                        form.processing ? 'Guardando…' : 'Guardar distribución'
                    }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
