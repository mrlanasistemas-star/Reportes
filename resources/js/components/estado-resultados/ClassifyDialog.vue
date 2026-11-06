<script setup lang="ts">
import { AlertOctagon, Loader2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { isConciliated, mxn } from './types';
import type { Movement, TreatmentMeta } from './types';

const props = defineProps<{
    open: boolean;
    items: Movement[];
    treatments: TreatmentMeta[];
    saving: boolean;
}>();

const emit = defineEmits<{
    'update:open': [v: boolean];
    submit: [payload: { treatment: string; notes: string }];
}>();

const treatment = ref('');
const notes = ref('');

watch(
    () => props.open,
    (open) => {
        if (open) {
            const suggested =
                props.items.length === 1
                    ? props.items[0].suggested_treatment
                    : null;
            treatment.value = suggested ?? '';
            notes.value = '';
        }
    },
);

const assignable = computed(() => props.treatments.filter((t) => t.assignable));
const groups = computed(() => [
    {
        label: 'Afectan el Estado de Resultados',
        items: assignable.value.filter((t) => t.group === 'er'),
    },
    {
        label: 'Fuera del Estado de Resultados',
        items: assignable.value.filter((t) => t.group === 'fuera'),
    },
    {
        label: 'Pendiente',
        items: assignable.value.filter((t) => t.group === 'pendiente'),
    },
]);
const chosen = computed(
    () => assignable.value.find((t) => t.key === treatment.value) ?? null,
);
const total = computed(() =>
    props.items.reduce((s, m) => s + Number(m.cargo) + Number(m.abono), 0),
);
const riskyDup = computed(() =>
    props.items.filter(
        (m) => m.treatment === 'conciliado_erp' || isConciliated(m),
    ),
);
const riskyAddsToEr = computed(
    () => riskyDup.value.length > 0 && chosen.value?.group === 'er',
);
const valid = computed(
    () => !!treatment.value && notes.value.trim().length >= 3,
);
</script>

<template>
    <Dialog :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle
                    >Clasificar {{ items.length }} movimiento(s)</DialogTitle
                >
                <DialogDescription>
                    Importe involucrado {{ mxn(total) }}. El dato original nunca
                    se modifica: se guarda una clasificación nueva con tu
                    usuario, fecha y motivo.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-3">
                <div
                    v-if="riskyDup.length"
                    class="flex gap-2 rounded-xl border p-3 text-xs"
                    :class="
                        riskyAddsToEr
                            ? 'border-rose-300 bg-rose-50 text-rose-900 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200'
                            : 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200'
                    "
                >
                    <AlertOctagon class="size-4 shrink-0" />
                    <p>
                        <b
                            >{{ riskyDup.length }} movimiento(s) ya están
                            conciliados con el ERP.</b
                        >
                        <template v-if="riskyAddsToEr">
                            Clasificarlos en una línea del Estado de Resultados
                            <b>duplicaría el gasto</b> (ya se cuenta desde el
                            ERP). Hazlo sólo si confirmaste que la conciliación
                            es incorrecta.
                        </template>
                        <template v-else>
                            Cambiar su tratamiento no debería ser necesario.
                        </template>
                    </p>
                </div>

                <label class="block space-y-1 text-sm">
                    <span class="font-semibold">Tratamiento financiero</span>
                    <select
                        v-model="treatment"
                        class="h-10 w-full rounded-xl border bg-background px-3 text-sm"
                    >
                        <option value="" disabled>Selecciona…</option>
                        <optgroup
                            v-for="g in groups"
                            :key="g.label"
                            :label="g.label"
                        >
                            <option
                                v-for="t in g.items"
                                :key="t.key"
                                :value="t.key"
                            >
                                {{ t.label }}
                            </option>
                        </optgroup>
                    </select>
                </label>
                <p
                    v-if="chosen"
                    class="rounded-xl bg-muted/50 p-2.5 text-xs leading-relaxed"
                >
                    {{ chosen.explain }}
                </p>

                <label class="block space-y-1 text-sm">
                    <span class="font-semibold">Motivo (obligatorio)</span>
                    <textarea
                        v-model="notes"
                        rows="3"
                        maxlength="2000"
                        placeholder="Ej. Factura de servicio de internet de Corporativo, verificada contra CFDI."
                        class="w-full rounded-xl border bg-background p-2.5 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-500/20"
                    />
                </label>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="emit('update:open', false)">
                    Cancelar
                </Button>
                <Button
                    :disabled="!valid || saving"
                    class="bg-teal-700 text-white hover:bg-teal-800"
                    @click="emit('submit', { treatment, notes: notes.trim() })"
                >
                    <Loader2 v-if="saving" class="size-4 animate-spin" />
                    Guardar clasificación
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
