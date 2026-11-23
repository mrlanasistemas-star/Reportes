<script setup lang="ts">
/**
 * FormActions — fila de botones Cancelar / Guardar para formularios.
 *
 * Componente genérico; actualmente no se importa en ninguna vista.
 *
 * Props:
 * - saving: muestra "Guardando..." y deshabilita Guardar.
 * - saveText / cancelText: textos de los botones.
 * - align: alineación de la fila ('start', 'end' o 'between').
 * - showCancel: muestra u oculta el botón Cancelar.
 *
 * Emits:
 * - save: clic en Guardar.
 * - cancel: clic en Cancelar.
 */
withDefaults(
    defineProps<{
        saving?: boolean;
        saveText?: string;
        cancelText?: string;
        align?: 'start' | 'end' | 'between';
        showCancel?: boolean;
    }>(),
    {
        saving: false,
        saveText: 'Guardar',
        cancelText: 'Cancelar',
        align: 'end',
        showCancel: true,
    },
);

const emit = defineEmits<{
    (e: 'save'): void;
    (e: 'cancel'): void;
}>();

/** Clase de alineación de flexbox para cada valor de `align`. */
const justifyClass = {
    start: 'justify-start',
    end: 'justify-end',
    between: 'justify-between',
};
</script>

<template>
    <div class="flex flex-wrap items-center gap-3" :class="justifyClass[align]">
        <button
            v-if="showCancel"
            type="button"
            class="app-btn app-btn-secondary"
            @click="emit('cancel')"
        >
            {{ cancelText }}
        </button>

        <button
            type="button"
            class="app-btn app-btn-primary"
            :disabled="saving"
            @click="emit('save')"
        >
            {{ saving ? 'Guardando...' : saveText }}
        </button>
    </div>
</template>
