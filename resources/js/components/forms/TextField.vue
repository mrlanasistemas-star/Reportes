<script setup lang="ts">
/**
 * TextField — campo de texto con etiqueta, ayuda y mensaje de error accesibles.
 *
 * Se usa en pages/Okr/KpiCatalog y como base de estilo de los demás campos.
 *
 * Props:
 * - id / name: identificador y nombre del input (el id se genera si falta).
 * - label: etiqueta visible; `required` añade un asterisco.
 * - type, placeholder, autocomplete, maxlength, inputmode: atributos del <input>.
 * - error: mensaje de error (marca el campo como inválido).
 * - description: texto de ayuda (se oculta cuando hay error).
 * - disabled / required: estado del campo.
 *
 * v-model: texto o número capturado.
 */
// Campo de texto de la casa: label asociado (for/id), ayuda y error enlazados
// por aria-describedby y estado inválido visible (aria-invalid).
import { computed, useId } from 'vue';

const model = defineModel<string | number | null>();

const props = withDefaults(
    defineProps<{
        id?: string;
        name?: string;
        label?: string;
        type?: string;
        placeholder?: string;
        error?: string | null;
        description?: string | null;
        disabled?: boolean;
        required?: boolean;
        autocomplete?: string;
        maxlength?: number;
        inputmode?:
            | 'text'
            | 'numeric'
            | 'decimal'
            | 'email'
            | 'search'
            | 'tel'
            | 'url'
            | 'none';
    }>(),
    {
        type: 'text',
        placeholder: '',
        disabled: false,
        required: false,
    },
);

const autoId = useId();
/** Id del input: el recibido por prop o uno generado. */
const fieldId = computed(() => props.id ?? `tf-${autoId}`);
/** Id del texto de error o de ayuda para aria-describedby. */
const describedBy = computed(() => {
    if (props.error) {
        return `${fieldId.value}-error`;
    }

    return props.description ? `${fieldId.value}-help` : undefined;
});
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

        <input
            :id="fieldId"
            v-model="model"
            :name="name"
            :type="type"
            :placeholder="placeholder"
            :disabled="disabled"
            :required="required"
            :autocomplete="autocomplete"
            :maxlength="maxlength"
            :inputmode="inputmode"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="app-input"
        />

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
