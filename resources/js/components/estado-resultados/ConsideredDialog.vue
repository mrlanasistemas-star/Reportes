<script setup lang="ts">
/**
 * ConsideredDialog — «Ya considerado»: el movimiento sale en el estado de cuenta pero ya está
 * tomado en cuenta (Radiografía de sucursales, otro periodo u otra cuenta/fuente).
 *
 * Dos modos:
 * - Total: todo el importe queda fuera del ER («Ya incluido en Radiografía» o «Ya considerado»).
 * - Parcial (un solo movimiento): se captura cuánto ya estaba considerado; esa parte queda fuera y
 *   el RESTO se clasifica con el tratamiento elegido. Validación al centavo: la parte nunca llega
 *   al importe clasificable (bruto − conciliado con ERP); el servidor vuelve a validar.
 *
 * Lo usan Index (detalle, cola de revisión, lista de control y Cuadre).
 *
 * Props:
 * - open: abierto (v-model:open).
 * - items: movimientos.
 * - treatments: catálogo (para el tratamiento del resto).
 * - saving: guardando (bloquea cerrar).
 *
 * Emits:
 * - update:open(v).
 * - submit(payload): tratamiento, motivo y reparto (`considered_reason`, `considered_amount`).
 */
import { CheckCircle2, Loader2, PieChart, Scale } from 'lucide-vue-next';
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
import type { ClassifyPayload } from '@/composables/estado-resultados/useErActions';
import { CONSIDERED_REASONS, consideredTreatment, mxn, num } from './types';
import type { Movement, TreatmentMeta } from './types';

const props = defineProps<{
    open: boolean;
    items: Movement[];
    treatments: TreatmentMeta[];
    saving: boolean;
}>();
const emit = defineEmits<{
    'update:open': [v: boolean];
    submit: [payload: ClassifyPayload];
}>();

// ── Estado (todo antes de cualquier watch) ─────────────────────────────

/** Total (todo el importe) o parcial (una parte). */
const mode = ref<'total' | 'parcial'>('total');
/** Motivo elegido. */
const reason = ref('radiografia');
/** Importe ya considerado (texto con hasta 2 decimales). */
const amount = ref('');
/** Tratamiento del resto (modo parcial). */
const rest = ref('');
/** Motivo libre (se propone el del motivo elegido). */
const notes = ref('');

/** Único movimiento (el parcial sólo aplica a uno). */
const single = computed(() =>
    props.items.length === 1 ? props.items[0] : null,
);
/** Centavos de un importe. */
const cents = (v: string | number | null | undefined) =>
    Math.round(num(v) * 100);
/** Importe clasificable en centavos (bruto − conciliado con ERP). */
const maxCents = computed(() => {
    const m = single.value;

    if (!m) {
        return 0;
    }

    const gross = m.amounts
        ? cents(m.amounts.gross)
        : cents(m.cargo) + cents(m.abono);

    return gross - cents(m.amounts?.conciliated ?? 0);
});
/** El parcial es posible: un movimiento del periodo con algo clasificable. */
const canPartial = computed(
    () =>
        !!single.value &&
        single.value.in_period &&
        single.value.source !== 'noi_corp' &&
        maxCents.value > 1,
);
/** Importe bruto de los movimientos. */
const total = computed(() =>
    props.items.reduce((s, m) => s + num(m.cargo) + num(m.abono), 0),
);
/** Tratamiento de la parte ya considerada. */
const target = computed(() => consideredTreatment(reason.value));
/** Etiqueta de un tratamiento. */
const labelOf = (key: string) =>
    props.treatments.find((t) => t.key === key)?.label ?? key;
/** Tratamientos posibles para el resto (asignables, sin el de «ya considerado»). */
const restOptions = computed(() =>
    props.treatments.filter(
        (t) => t.assignable && t.key !== target.value && t.key !== 'pendiente',
    ),
);
/** Importe capturado válido (formato, > 0 y menor que lo clasificable), en centavos o null. */
const amountCents = computed(() => {
    const v = amount.value.trim().replace(/,/g, '');

    if (!/^\d{1,13}(\.\d{1,2})?$/.test(v)) {
        return null;
    }

    const c = cents(v);

    return c > 0 && c < maxCents.value ? c : null;
});
/** Mensaje del importe (vacío si está bien o no se ha escrito). */
const amountError = computed(() => {
    if (mode.value !== 'parcial' || !amount.value.trim()) {
        return '';
    }

    return amountCents.value === null
        ? `Escribe un importe mayor a $0.00 y menor que ${mxn(maxCents.value / 100)}, con hasta 2 decimales.`
        : '';
});
/** Resto que se clasifica con el tratamiento elegido (centavos). */
const restCents = computed(() =>
    amountCents.value === null ? null : maxCents.value - amountCents.value,
);
/** Se puede guardar. */
const valid = computed(
    () =>
        notes.value.trim().length >= 3 &&
        (mode.value === 'total' ||
            (amountCents.value !== null && !!rest.value)),
);

/** Al abrir: modo total, motivo Radiografía y resto propuesto (tratamiento actual o sugerido). */
watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        mode.value = 'total';
        reason.value = 'radiografia';
        amount.value = '';
        const m = single.value;
        const current = m?.treatment ?? '';
        rest.value =
            current &&
            !['pendiente', 'cubierto_radiografia', 'ya_considerado'].includes(
                current,
            )
                ? current
                : (m?.suggested_treatment ?? '');
        notes.value =
            CONSIDERED_REASONS.find((r) => r.key === reason.value)?.label ?? '';
    },
);
/** Cambiar el motivo propone su texto si el usuario no escribió otro. */
watch(reason, (r, old) => {
    const prev = CONSIDERED_REASONS.find((x) => x.key === old)?.label;

    if (!notes.value.trim() || notes.value === prev) {
        notes.value = CONSIDERED_REASONS.find((x) => x.key === r)?.label ?? '';
    }
});

/** Envía el reparto. */
function submit(): void {
    if (!valid.value) {
        return;
    }

    emit(
        'submit',
        mode.value === 'total'
            ? {
                  treatment: target.value,
                  notes: notes.value.trim(),
                  considered_reason: reason.value,
              }
            : {
                  treatment: rest.value,
                  notes: notes.value.trim(),
                  considered_reason: reason.value,
                  considered_amount: ((amountCents.value ?? 0) / 100).toFixed(
                      2,
                  ),
              },
    );
}
</script>

<template>
    <Dialog
        :open="open"
        @update:open="(v: boolean) => !saving && emit('update:open', v)"
    >
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <CheckCircle2 class="size-5 text-teal-600" />
                    Ya considerado
                </DialogTitle>
                <DialogDescription>
                    {{ items.length }} movimiento(s) · {{ mxn(total) }}. Ya se
                    tomó en cuenta: no se vuelve a sumar.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4 text-[15px]">
                <!-- Modo -->
                <div
                    class="grid grid-cols-2 gap-1 rounded-xl border bg-muted/40 p-1"
                    role="radiogroup"
                    aria-label="Cuánto ya estaba considerado"
                >
                    <button
                        v-for="o in [
                            { k: 'total', l: 'Todo el importe', i: Scale },
                            { k: 'parcial', l: 'Sólo una parte', i: PieChart },
                        ] as const"
                        :key="o.k"
                        type="button"
                        role="radio"
                        :aria-checked="mode === o.k"
                        :disabled="o.k === 'parcial' && !canPartial"
                        :title="
                            o.k === 'parcial' && !canPartial
                                ? 'Una parte se captura de un movimiento a la vez.'
                                : undefined
                        "
                        class="inline-flex h-10 items-center justify-center gap-1.5 rounded-lg text-sm font-semibold transition disabled:cursor-not-allowed disabled:opacity-40"
                        :class="
                            mode === o.k
                                ? 'bg-background shadow-sm'
                                : 'hover:bg-background/60'
                        "
                        @click="mode = o.k"
                    >
                        <component :is="o.i" class="size-4" /> {{ o.l }}
                    </button>
                </div>

                <!-- Motivo -->
                <fieldset class="space-y-1.5">
                    <legend class="mb-1 text-sm font-semibold">
                        Dónde ya está
                    </legend>
                    <label
                        v-for="r in CONSIDERED_REASONS"
                        :key="r.key"
                        class="flex cursor-pointer items-center gap-2.5 rounded-xl border px-3 py-2 transition hover:bg-muted/50"
                        :class="
                            reason === r.key
                                ? 'border-teal-500 bg-teal-50/60 dark:bg-teal-950/20'
                                : ''
                        "
                    >
                        <input
                            v-model="reason"
                            type="radio"
                            name="considered-reason"
                            :value="r.key"
                            class="accent-teal-600"
                        />
                        <span class="flex-1">{{ r.label }}</span>
                    </label>
                </fieldset>

                <!-- Parcial: importe y resto -->
                <div
                    v-if="mode === 'parcial' && single"
                    class="animate-in space-y-3 rounded-2xl border bg-muted/30 p-3 duration-200 fade-in-0 slide-in-from-top-1"
                >
                    <label class="block space-y-1">
                        <span class="text-sm font-semibold"
                            >Importe ya considerado</span
                        >
                        <input
                            v-model="amount"
                            type="text"
                            inputmode="decimal"
                            autocomplete="off"
                            placeholder="0.00"
                            class="h-11 w-full rounded-xl border bg-background px-3 text-right text-lg font-bold tabular-nums outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-500/20"
                            :aria-invalid="!!amountError"
                        />
                        <span
                            v-if="amountError"
                            class="block text-xs text-rose-700 dark:text-rose-400"
                            role="alert"
                            >{{ amountError }}</span
                        >
                    </label>
                    <div class="grid grid-cols-2 gap-2 text-sm tabular-nums">
                        <p class="rounded-xl bg-background px-3 py-2">
                            <span class="block text-xs text-muted-foreground"
                                >Fuera del ER</span
                            >
                            <b>{{
                                mxn(
                                    amountCents === null
                                        ? 0
                                        : amountCents / 100,
                                )
                            }}</b>
                        </p>
                        <p class="rounded-xl bg-background px-3 py-2">
                            <span class="block text-xs text-muted-foreground"
                                >Resto a clasificar</span
                            >
                            <b>{{
                                mxn(
                                    restCents === null
                                        ? maxCents / 100
                                        : restCents / 100,
                                )
                            }}</b>
                        </p>
                    </div>
                    <label class="block space-y-1">
                        <span class="text-sm font-semibold">El resto va a</span>
                        <select
                            v-model="rest"
                            class="h-10 w-full rounded-xl border bg-background px-3 text-sm"
                        >
                            <option value="" disabled>Selecciona…</option>
                            <option
                                v-for="t in restOptions"
                                :key="t.key"
                                :value="t.key"
                            >
                                {{ t.label }}
                            </option>
                        </select>
                    </label>
                </div>
                <p
                    v-else
                    class="rounded-xl bg-muted/50 px-3 py-2 text-sm text-muted-foreground"
                >
                    Todo queda en «{{ labelOf(target) }}».
                </p>

                <label class="block space-y-1">
                    <span class="text-sm font-semibold">Motivo</span>
                    <textarea
                        v-model="notes"
                        rows="2"
                        maxlength="2000"
                        class="w-full rounded-xl border bg-background p-2.5 text-sm outline-none focus:border-teal-500 focus:ring-2 focus:ring-teal-500/20"
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
                    class="bg-teal-700 text-white hover:bg-teal-800"
                    @click="submit"
                >
                    <Loader2 v-if="saving" class="size-4 animate-spin" />
                    Guardar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
