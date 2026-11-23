<script setup lang="ts">
/**
 * TextareaField — área de texto multilínea con etiqueta, ayuda y error accesibles.
 *
 * Se usa en OkrCheckInDialog.
 *
 * Props:
 * - id / name: identificador y nombre (el id se genera si falta).
 * - label: etiqueta visible; `required` añade un asterisco.
 * - placeholder, rows (3 por defecto), maxlength: atributos del <textarea>.
 * - error: mensaje de error (marca el campo como inválido).
 * - description: texto de ayuda (se oculta cuando hay error).
 * - disabled / required: estado del campo.
 *
 * v-model: texto capturado.
 */
// Fix 08-sep-2026 (módulo OKR): este componente estaba duplicado de TextField
// — renderizaba un <input> de una sola línea, nunca un <textarea> real (no
// tenía consumidores todavía, así que no rompía ninguna pantalla existente).
import { computed, useId } from 'vue';

const model = defineModel<string | number | null>();

const props = withDefaults(
    defineProps<{
        id?: string;
        name?: string;
        label?: string;
        placeholder?: string;
        rows?: number;
        error?: string | null;
        description?: string | null;
        disabled?: boolean;
        required?: boolean;
        maxlength?: number;
    }>(),
    {
        placeholder: '',
        rows: 3,
        disabled: false,
        required: false,
    },
);

const autoId = useId();
/** Id del textarea: el recibido por prop o uno generado. */
const fieldId = computed(() => props.id ?? `ta-${autoId}`);
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

        <textarea
            :id="fieldId"
            v-model="model"
            :name="name"
            :rows="rows"
            :placeholder="placeholder"
            :disabled="disabled"
            :required="required"
            :maxlength="maxlength"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="app-textarea resize-y"
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
