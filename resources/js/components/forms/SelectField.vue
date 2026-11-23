<script setup lang="ts">
/**
 * SelectField — select desplegable (reka-ui) con etiqueta, ayuda y error.
 *
 * Se usa en filtros y formularios de OKR, Asignaciones y Efectividad.
 *
 * Props:
 * - id / name: identificador y nombre del control.
 * - label: etiqueta visible; `required` añade un asterisco.
 * - options: opciones { value, label, disabled? }; value '' significa "Todas/Todos".
 * - placeholder: texto cuando no hay selección.
 * - error / description: mensaje de error o de ayuda.
 * - disabled / required: estado del control.
 * - ariaLabel: etiqueta accesible si no hay label visible.
 *
 * v-model: valor seleccionado ('' cuando se elige la opción vacía), con el mismo
 *   tipo que el `value` de la opción elegida (número o texto).
 */
// Select "shadcn" (Radix) genérico con la misma forma que TextField/TextareaField
// (label + description + error) — para catálogos cortos (estado, tipo, dirección...).
// Para listas largas/buscables (sucursal, colaborador, responsable) usa SearchableSelect.
import { computed, useId } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type OptionLike = { value: string | number; label: string; disabled?: boolean };

// Radix/reka-ui prohíbe un SelectItem con value="" (esa cadena está reservada
// para "limpiar la selección") — pero este módulo usa '' como "Todas/Todos/
// Cualquiera" en los filtros. Se traduce a un sentinel interno y de vuelta,
// nunca se expone al componente Select real.
const SENTINEL_EMPTY = '__ff_empty__';

const model = defineModel<string | number | null>();

const props = withDefaults(
    defineProps<{
        id?: string;
        name?: string;
        label?: string;
        options: OptionLike[];
        placeholder?: string;
        error?: string | null;
        description?: string | null;
        disabled?: boolean;
        required?: boolean;
        /** Etiqueta accesible cuando no hay label visible (p. ej. filtros compactos). */
        ariaLabel?: string;
    }>(),
    {
        placeholder: 'Selecciona...',
        disabled: false,
        required: false,
    },
);

const autoId = useId();
/** Id del disparador: el recibido por prop o uno generado. */
const fieldId = computed(() => props.id ?? `sf-${autoId}`);
/** Id del texto de error o de ayuda para aria-describedby. */
const describedBy = computed(() => {
    if (props.error) {
        return `${fieldId.value}-error`;
    }

    return props.description ? `${fieldId.value}-help` : undefined;
});

// El valor del modelo puede ser numérico (ids): se compara siempre como texto
// para que el botón muestre la opción elegida aunque los tipos difieran.
/** Valor que recibe el Select interno (texto; '' / null se traducen al centinela). */
const internalValue = computed(() => {
    const v = model.value;

    return v === '' || v === null || v === undefined
        ? SENTINEL_EMPTY
        : String(v);
});

/** Opciones con valor en texto y la opción vacía traducida al centinela. */
const internalOptions = computed(() =>
    props.options.map((o) => ({
        ...o,
        value: o.value === '' ? SENTINEL_EMPTY : String(o.value),
    })),
);

// Texto visible siempre desde las opciones VIGENTES: Reka conserva el texto del
// item al momento de elegirlo, y una etiqueta que cambia (p.ej. "faltan carteras"
// → "listo para calcular") quedaba desactualizada en el botón.
const selectedLabel = computed(
    () =>
        internalOptions.value.find((o) => o.value === internalValue.value)
            ?.label ?? null,
);

/**
 * Traduce el valor elegido en el Select al modelo ('' si es el centinela).
 * Devuelve el valor ORIGINAL de la opción (número si la opción es numérica),
 * no su versión en texto: así un v-model numérico (ids) conserva su tipo.
 * Quien necesite texto debe declarar sus opciones con valores de texto
 * (p. ej. AssignBranchModal usa String(b.id)).
 *
 * @param value Valor emitido por el Select interno.
 */
function onUpdate(value: unknown) {
    if (value === SENTINEL_EMPTY) {
        model.value = '';

        return;
    }

    const original = props.options.find((o) => String(o.value) === value);

    model.value = original ? original.value : (value as string);
}
</script>

<template>
    <div class="w-full space-y-1.5">
        <label
            v-if="label"
            :for="fieldId"
            class="text-sm font-semibold text-foreground"
        >
            {{ label }}
            <span v-if="required" class="text-danger" aria-hidden="true"
                >*</span
            >
        </label>

        <Select
            :model-value="internalValue"
            :disabled="disabled"
            :name="name"
            :required="required"
            @update:model-value="onUpdate"
        >
            <SelectTrigger
                :id="fieldId"
                class="h-11 w-full min-w-0 rounded-2xl bg-background px-4 text-sm"
                :aria-label="!label ? ariaLabel : undefined"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="describedBy"
            >
                <SelectValue
                    v-if="selectedLabel"
                    :placeholder="placeholder"
                    class="truncate"
                    >{{ selectedLabel }}</SelectValue
                >
                <SelectValue v-else :placeholder="placeholder" />
            </SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="opt in internalOptions"
                    :key="opt.value"
                    :value="opt.value"
                    :disabled="opt.disabled"
                >
                    {{ opt.label }}
                </SelectItem>
            </SelectContent>
        </Select>

        <p
            v-if="description && !error"
            :id="`${fieldId}-help`"
            class="app-help"
        >
            {{ description }}
        </p>
        <p
            v-if="error"
            :id="`${fieldId}-error`"
            role="alert"
            class="app-field-error"
        >
            {{ error }}
        </p>
    </div>
</template>
