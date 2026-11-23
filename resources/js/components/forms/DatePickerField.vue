<script setup lang="ts">
/**
 * DatePickerField — selector de fecha con calendario emergente.
 *
 * Se usa en OkrCheckInDialog, OkrFilters y OkrAssignDialog. Muestra la fecha
 * como dd/MM/yyyy y emite siempre texto 'YYYY-MM-DD'.
 *
 * Props:
 * - modelValue: fecha seleccionada 'YYYY-MM-DD' (null/undefined = vacía).
 * - id, label, placeholder, description, error, disabled: presentación del campo.
 * - clearable: muestra un botón para limpiar la fecha.
 * - min / max: rango permitido ('YYYY-MM-DD').
 *
 * Emits:
 * - update:modelValue(fecha | null): nueva fecha elegida o null al limpiar.
 */
import { parseDate } from '@internationalized/date';
import { format } from 'date-fns';
import { es } from 'date-fns/locale';
import { Calendar as CalendarIcon, X } from 'lucide-vue-next';
// El tipo DateValue se toma de "reka-ui" (no de "@internationalized/date" directo)
// porque es el que espera <Calendar> (ver components/ui/calendar/Calendar.vue) —
// aunque describen el mismo valor en tiempo de ejecución, son declaraciones de tipo
// distintas y TypeScript las trataba como incompatibles al hacer v-model="selected".
import type { DateValue } from 'reka-ui';
import { computed, ref, useId, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

// Modelo SIEMPRE como texto 'YYYY-MM-DD' (fecha de calendario, sin hora ni zona
// horaria): nunca se pasa por new Date('YYYY-MM-DD'), que se interpreta en UTC y
// en México mostraría el día anterior.
type Model = string | null | undefined;

const props = withDefaults(
    defineProps<{
        modelValue: Model;
        id?: string;
        label?: string;
        placeholder?: string;
        disabled?: boolean;
        error?: string | null;
        description?: string | null;
        clearable?: boolean;
        /** Fecha mínima permitida ('YYYY-MM-DD'). */
        min?: string | null;
        /** Fecha máxima permitida ('YYYY-MM-DD'). */
        max?: string | null;
    }>(),
    {
        placeholder: 'Selecciona fecha',
        clearable: false,
    },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: Model): void;
}>();

const autoId = useId();
/** Id del botón disparador: el recibido por prop o uno generado. */
const fieldId = computed(() => props.id ?? `dp-${autoId}`);
/** Id del texto de error o de ayuda para aria-describedby. */
const describedBy = computed(() => {
    if (props.error) {
        return `${fieldId.value}-error`;
    }

    return props.description ? `${fieldId.value}-help` : undefined;
});

/** Estado abierto/cerrado del calendario. */
const open = ref(false);
/** Fecha seleccionada como DateValue para el calendario. */
const selected = ref<DateValue | undefined>(undefined);

/**
 * Convierte texto 'YYYY-MM-DD' (o con hora) a DateValue; devuelve undefined si está vacío o es inválido.
 *
 * @param value Fecha en texto.
 */
const toDateValue = (value: Model): DateValue | undefined => {
    if (!value) {
        return undefined;
    }

    try {
        // Acepta también '2026-11-23T00:00:00' / '2026-11-23 00:00:00' (se toma la fecha).
        return parseDate(String(value).slice(0, 10));
    } catch {
        return undefined;
    }
};

/** Fecha mínima como DateValue. */
const minValue = computed(() => toDateValue(props.min));
/** Fecha máxima como DateValue. */
const maxValue = computed(() => toDateValue(props.max));

/** Sincroniza la fecha seleccionada cuando cambia el modelo desde el padre. */
watch(
    () => props.modelValue,
    (value) => {
        selected.value = toDateValue(value);
    },
    { immediate: true },
);

/** Fecha seleccionada en formato dd/MM/yyyy para mostrar en el botón. */
const displayValue = computed(() => {
    const value = selected.value;

    if (!value) {
        return '';
    }

    const localDate = new Date(value.year, value.month - 1, value.day);

    return format(localDate, 'dd/MM/yyyy', { locale: es });
});

/**
 * Guarda el día elegido, emite 'YYYY-MM-DD' y cierra el calendario.
 *
 * @param value Día elegido en el calendario.
 */
const onPick = (value: DateValue | undefined) => {
    // Volver a pulsar el día elegido no lo borra (para eso está "Limpiar").
    if (!value) {
        return;
    }

    selected.value = value;
    emit('update:modelValue', value.toString());
    open.value = false;
};

/** Borra la fecha seleccionada y emite null. */
const clearValue = () => {
    selected.value = undefined;
    emit('update:modelValue', null);
};
</script>

<template>
    <div class="w-full space-y-1.5">
        <label
            v-if="label"
            :for="fieldId"
            class="text-sm font-semibold text-foreground"
        >
            {{ label }}
        </label>

        <div class="relative">
            <Popover v-model:open="open">
                <PopoverTrigger as-child>
                    <Button
                        :id="fieldId"
                        type="button"
                        variant="outline"
                        :disabled="disabled"
                        :aria-invalid="error ? 'true' : undefined"
                        :aria-describedby="describedBy"
                        :aria-label="
                            displayValue
                                ? `${label ?? 'Fecha'}: ${displayValue}. Cambiar fecha`
                                : undefined
                        "
                        class="h-11 w-full min-w-0 justify-between rounded-2xl border-border bg-background px-3 text-left font-normal hover:bg-muted/60 aria-invalid:border-danger"
                        :class="clearable && modelValue ? 'pr-16' : ''"
                    >
                        <span
                            :class="
                                displayValue
                                    ? 'font-medium text-foreground'
                                    : 'text-muted-foreground'
                            "
                            class="truncate"
                        >
                            {{ displayValue || placeholder }}
                        </span>

                        <CalendarIcon
                            class="h-4 w-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </Button>
                </PopoverTrigger>

                <PopoverContent
                    align="start"
                    class="w-auto max-w-[calc(100vw-2rem)] overflow-hidden rounded-3xl border-border bg-popover p-0 shadow-2xl"
                >
                    <!-- @vue-expect-error DateValue de reka-ui (Calendar) y de @internationalized/date
               (parseDate/toDateValue de este archivo) son dos declaraciones de tipo
               distintas para el mismo valor en tiempo de ejecución — friction real de
               tipos entre paquetes externos, no un bug de este componente. -->
                    <Calendar
                        :model-value="selected"
                        :default-placeholder="selected"
                        :min-value="minValue"
                        :max-value="maxValue"
                        locale="es"
                        prevent-deselect
                        initial-focus
                        class="p-3"
                        @update:model-value="onPick"
                    />
                </PopoverContent>
            </Popover>

            <!-- Fuera del botón disparador: un <button> dentro de otro es HTML inválido
           y el clic/teclado se confunden (abría el calendario al limpiar). -->
            <button
                v-if="clearable && modelValue && !disabled"
                type="button"
                class="absolute top-1/2 right-9 inline-flex h-7 w-7 -translate-y-1/2 items-center justify-center rounded-full text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                aria-label="Limpiar fecha"
                @click="clearValue"
            >
                <X class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>

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
