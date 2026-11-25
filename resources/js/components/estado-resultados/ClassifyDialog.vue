<script setup lang="ts">
/**
 * ClassifyDialog — diálogo para clasificar uno o varios movimientos
 * bancarios con un tratamiento financiero.
 *
 * Lo usa la página EstadoResultados/Index. Muestra el importe involucrado,
 * advierte si algún movimiento ya está conciliado con el ERP (y si el
 * tratamiento elegido duplicaría el gasto), ofrece los tratamientos agrupados
 * (afectan el ER / fuera del ER / pendiente) con su explicación y exige un
 * motivo de al menos 3 caracteres. El dato original nunca se modifica.
 *
 * Props:
 * - open: si el diálogo está abierto (v-model:open).
 * - items: movimientos seleccionados.
 * - treatments: catálogo de tratamientos (sólo se ofrecen los asignables).
 * - saving: si se está guardando (bloquea cerrar).
 * - initialTreatment: destino ya elegido (p. ej. al arrastrar en el Cuadre).
 *
 * Emits:
 * - update:open(v): abrir o cerrar el diálogo.
 * - submit(payload): guardar con el tratamiento y el motivo.
 */
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
    initialTreatment?: string | null;
}>();

const emit = defineEmits<{
    'update:open': [v: boolean];
    submit: [payload: { treatment: string; notes: string }];
}>();

/** Tratamiento elegido. */
const treatment = ref('');
/** Motivo capturado. */
const notes = ref('');

/**
 * Al abrir, propone el tratamiento sugerido (sólo con un movimiento) y
 * limpia el motivo.
 */
watch(
    () => props.open,
    (open) => {
        if (open) {
            const suggested =
                props.items.length === 1
                    ? props.items[0].suggested_treatment
                    : null;
            const initial = props.initialTreatment ?? null;
            treatment.value = initial ?? suggested ?? '';
            notes.value = initial
                ? `Movido en el cuadre a «${props.treatments.find((t) => t.key === initial)?.label ?? initial}».`
                : '';
        }
    },
);

/** Tratamientos que el usuario puede asignar. */
const assignable = computed(() => props.treatments.filter((t) => t.assignable));
/** Tratamientos agrupados para el selector. */
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
/** Metadatos del tratamiento elegido, o null. */
const chosen = computed(
    () => assignable.value.find((t) => t.key === treatment.value) ?? null,
);
/** Importe involucrado (suma de cargos y abonos de los movimientos). */
const total = computed(() =>
    props.items.reduce((s, m) => s + Number(m.cargo) + Number(m.abono), 0),
);
/** Movimientos que ya están conciliados con el ERP. */
const riskyDup = computed(() =>
    props.items.filter(
        (m) => m.treatment === 'conciliado_erp' || isConciliated(m),
    ),
);
/** Si el tratamiento elegido sumaría al ER movimientos ya conciliados (duplicaría). */
const riskyAddsToEr = computed(
    () => riskyDup.value.length > 0 && chosen.value?.group === 'er',
);
/** Se puede guardar: hay tratamiento y motivo de al menos 3 caracteres. */
const valid = computed(
    () => !!treatment.value && notes.value.trim().length >= 3,
);
</script>

<template>
    <Dialog
        :open="open"
        @update:open="(v: boolean) => !saving && emit('update:open', v)"
    >
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
                        class="w-full rounded-xl border bg-background p-2.5 text-sm outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20"
                    />
                </label>
            </div>

            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="saving"
                    @click="emit('update:open', false)"
                >
                    Cancelar
                </Button>
                <Button
                    :disabled="!valid || saving"
                    class="bg-emerald-700 text-white hover:bg-emerald-800"
                    @click="emit('submit', { treatment, notes: notes.trim() })"
                >
                    <Loader2 v-if="saving" class="size-4 animate-spin" />
                    Guardar clasificación
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
