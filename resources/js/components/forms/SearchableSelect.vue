<script setup lang="ts">
/**
 * SearchableSelect — selector con buscador para listas largas (sucursales,
 * colaboradores, periodos, responsables).
 *
 * Se usa en ComparativeSelectors, OkrFilters, OkrCheckInDialog,
 * OkrCommitmentLetterDialog y en los pasos del Histórico general. El panel se
 * teletransporta a <body> con posición fija (abre hacia arriba si no cabe
 * abajo) y funciona dentro de diálogos gracias a FocusScope.
 *
 * Props:
 * - modelValue: valor seleccionado (comparado como texto).
 * - options: lista de objetos a mostrar.
 * - labelKey / secondaryKey / valueKey: campos de texto principal, texto secundario y valor
 *   (por defecto 'nombre', 'codigo' e 'id').
 * - allowNull / nullLabel: añade una opción para quitar la selección.
 * - id, label, placeholder, searchPlaceholder, description, error, disabled: presentación.
 *
 * Emits:
 * - update:modelValue(valor): nueva selección.
 * - change(valor): igual que el anterior, para quien no usa v-model.
 * - update:search(texto): texto de búsqueda, para cargar opciones de forma asíncrona.
 */
import { Check, ChevronsUpDown, Search, X } from 'lucide-vue-next';
// FocusScope real de reka-ui (no un hack propio) — el panel se teletransporta a
// <body>, así que cuando este componente vive DENTRO de un shadcn Dialog, el
// focus-trap del Dialog "atrapa" el foco y se lo roba de vuelta al buscador en
// cuanto el usuario hace clic/escribe ahí (el input nunca conserva el foco:
// "no me deja escribir"). FocusScope aquí NO atrapa nada por sí mismo
// (`trapped` no se pasa) — solo, al montarse, PAUSA el FocusScope activo más
// arriba en la pila (mismo mecanismo que usan los propios Select/Popover de
// reka-ui anidados en un Dialog) y lo reanuda al cerrarse.
import { FocusScope } from 'reka-ui';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';

type OptionLike = Record<string, any>;

const props = withDefaults(
    defineProps<{
        modelValue: string | number | null;
        options: OptionLike[];
        id?: string;
        label?: string;
        placeholder?: string;
        searchPlaceholder?: string;
        error?: string | null;
        description?: string | null;
        labelKey?: string;
        secondaryKey?: string;
        valueKey?: string;
        allowNull?: boolean;
        nullLabel?: string;
        disabled?: boolean;
    }>(),
    {
        placeholder: 'Selecciona...',
        searchPlaceholder: 'Buscar...',
        labelKey: 'nombre',
        secondaryKey: 'codigo',
        valueKey: 'id',
        allowNull: false,
        nullLabel: 'Sin selección',
        disabled: false,
    },
);

const emit = defineEmits<{
    (e: 'update:modelValue', value: string | number | null): void;
    (e: 'change', value: string | number | null): void;
    // Emite el texto de búsqueda tal cual lo escribe el usuario — para consumidores
    // que resuelven `options` de forma asíncrona (ej. buscador de colaboradores del
    // módulo OKR) en vez de filtrar una lista ya cargada por completo.
    (e: 'update:search', value: string): void;
}>();

/** Estado abierto/cerrado del panel. */
const open = ref(false);
/** Texto de búsqueda escrito en el panel. */
const query = ref('');

const buttonRef = ref<HTMLElement | null>(null);
const panelRef = ref<HTMLElement | null>(null);
const searchRef = ref<HTMLInputElement | null>(null);
/** Posición y tamaño calculados del panel flotante. */
const panelStyle = ref<Record<string, string>>({});

/** Id aleatorio por si no se recibe `id`. */
const uid = `ss-${Math.random().toString(36).slice(2, 10)}`;
const buttonId = computed(() => props.id ?? uid);
const listboxId = computed(() => `${buttonId.value}-panel`);
/** Id del texto de error o de ayuda para aria-describedby. */
const describedBy = computed(() => {
    if (props.error) {
        return `${buttonId.value}-error`;
    }

    return props.description ? `${buttonId.value}-help` : undefined;
});

/** Opción que corresponde al valor actual (o null). */
const selected = computed<OptionLike | null>(() => {
    const value = props.modelValue;

    if (value === null || value === undefined || value === '') {
        return null;
    }

    return (
        props.options.find(
            (item) => String(item?.[props.valueKey]) === String(value),
        ) ?? null
    );
});

/** Opciones cuyo texto principal o secundario contiene el término buscado. */
const filteredOptions = computed(() => {
    const term = query.value.trim().toLowerCase();

    if (!term) {
        return props.options;
    }

    return props.options.filter((item) => {
        const main = String(item?.[props.labelKey] ?? '').toLowerCase();
        const secondary = String(
            item?.[props.secondaryKey] ?? '',
        ).toLowerCase();

        return main.includes(term) || secondary.includes(term);
    });
});

/**
 * Selecciona un valor, emite los eventos y cierra el panel.
 *
 * @param value Valor elegido (null = sin selección).
 */
const pick = (value: string | number | null) => {
    emit('update:modelValue', value);
    emit('change', value);
    close();
};

/**
 * Cierra el panel, limpia la búsqueda y (opcionalmente) devuelve el foco al botón.
 *
 * @param restoreFocus Si es true el foco vuelve al botón disparador.
 */
const close = (restoreFocus = true) => {
    const wasOpen = open.value;
    open.value = false;
    query.value = '';

    // Teclado/lector de pantalla: el foco vuelve al botón al cerrar (si no, se
    // perdía en <body> al elegir o al pulsar Escape).
    if (wasOpen && restoreFocus) {
        nextTick(() => buttonRef.value?.focus());
    }
};

// Navegación con flechas entre el buscador y las opciones del panel.
/** Botones de opción visibles en el panel, en orden. */
const optionButtons = () =>
    Array.from(
        panelRef.value?.querySelectorAll<HTMLButtonElement>(
            '[data-ss-option]',
        ) ?? [],
    );

/**
 * Flechas, Inicio y Fin para moverse entre las opciones; flecha arriba desde la
 * primera opción regresa al buscador.
 *
 * @param event Evento de teclado dentro del panel.
 */
const onPanelKeydown = (event: KeyboardEvent) => {
    if (
        event.key !== 'ArrowDown' &&
        event.key !== 'ArrowUp' &&
        event.key !== 'Home' &&
        event.key !== 'End'
    ) {
        return;
    }

    const items = optionButtons();

    if (!items.length) {
        return;
    }

    event.preventDefault();
    const current = items.indexOf(document.activeElement as HTMLButtonElement);
    let next = 0;

    if (event.key === 'End') {
        next = items.length - 1;
    } else if (event.key === 'ArrowDown') {
        next = current < 0 ? 0 : Math.min(items.length - 1, current + 1);
    } else if (event.key === 'ArrowUp') {
        if (current <= 0) {
            searchRef.value?.focus();

            return;
        }

        next = current - 1;
    }

    items[next]?.focus();
};

/**
 * Abre el panel con flecha arriba/abajo cuando el botón tiene el foco.
 *
 * @param event Evento de teclado en el botón.
 */
const onTriggerKeydown = (event: KeyboardEvent) => {
    if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && !open.value) {
        event.preventDefault();
        toggle();
    }
};

/** Abre o cierra el panel; al abrir calcula su posición y enfoca el buscador. */
const toggle = async () => {
    if (props.disabled) {
        return;
    }

    open.value = !open.value;

    if (open.value) {
        await nextTick();
        updatePosition();
        searchRef.value?.focus();
    }
};

/** Calcula la posición fija del panel respecto al botón, abriendo hacia arriba si abajo no cabe. */
const updatePosition = () => {
    const button = buttonRef.value;
    const panel = panelRef.value;

    if (!button || !panel) {
        return;
    }

    const rect = button.getBoundingClientRect();
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const margin = 12;
    const gap = 8;

    const width = Math.min(rect.width, viewportWidth - margin * 2);
    const left = Math.max(
        margin,
        Math.min(rect.left, viewportWidth - width - margin),
    );

    const availableBelow = viewportHeight - rect.bottom - gap - margin;
    const availableAbove = rect.top - gap - margin;
    const openUp = availableBelow < 260 && availableAbove > availableBelow;

    // Hacia arriba se ancla por `bottom` (justo encima del botón): con un `top` fijo
    // (rect.top - 320) un panel más bajo que 320 px quedaba flotando lejos del botón.
    panelStyle.value = {
        position: 'fixed',
        left: `${left}px`,
        width: `${width}px`,
        maxHeight: `${Math.max(180, Math.min(360, openUp ? availableAbove : availableBelow))}px`,
        ...(openUp
            ? { bottom: `${viewportHeight - rect.top + gap}px`, top: 'auto' }
            : { top: `${rect.bottom + gap}px`, bottom: 'auto' }),
    };
};

/**
 * Escape cierra solo este panel (se captura antes que el Dialog contenedor).
 *
 * @param event Evento de teclado de la ventana.
 */
const handleEscape = (event: KeyboardEvent) => {
    if (event.key === 'Escape' && open.value) {
        // Fase de captura en window: el Escape cierra solo este panel y no también
        // el Dialog que contiene al selector.
        event.stopPropagation();
        close();
    }
};

/** Recalcula la posición del panel abierto al hacer scroll o cambiar el tamaño. */
const handleReflow = () => {
    if (open.value) {
        updatePosition();
    }
};

/** Registra (y al desmontar elimina) los listeners de Escape, resize y scroll. */
onMounted(() => {
    window.addEventListener('keydown', handleEscape, true);
    window.addEventListener('resize', handleReflow);
    window.addEventListener('scroll', handleReflow, true);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleEscape, true);
    window.removeEventListener('resize', handleReflow);
    window.removeEventListener('scroll', handleReflow, true);
});

/** Si cambian las opciones con el panel abierto, se recalcula su posición. */
watch(
    () => props.options,
    async () => {
        if (!open.value) {
            return;
        }

        await nextTick();
        updatePosition();
    },
);

/** Reenvía el texto de búsqueda al padre (evento update:search). */
watch(query, (value) => emit('update:search', value));
</script>

<template>
    <div class="w-full space-y-1.5">
        <label
            v-if="label"
            :for="buttonId"
            class="text-sm font-semibold text-foreground"
        >
            {{ label }}
        </label>

        <button
            :id="buttonId"
            ref="buttonRef"
            type="button"
            :disabled="disabled"
            aria-haspopup="listbox"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="open ? listboxId : undefined"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="describedBy"
            class="flex h-11 w-full min-w-0 items-center justify-between gap-2 rounded-2xl border border-border bg-background px-4 text-left text-sm shadow-sm transition hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60 aria-[invalid=true]:border-danger"
            @click="toggle"
            @keydown="onTriggerKeydown"
        >
            <span class="min-w-0 truncate">
                <template v-if="selected">
                    <span class="font-medium text-foreground">
                        {{ selected[labelKey] }}
                    </span>
                    <span
                        v-if="selected[secondaryKey]"
                        class="ml-1 text-muted-foreground"
                    >
                        ({{ selected[secondaryKey] }})
                    </span>
                </template>

                <template v-else>
                    <span class="text-muted-foreground">
                        {{ placeholder }}
                    </span>
                </template>
            </span>

            <ChevronsUpDown
                class="h-4 w-4 shrink-0 text-muted-foreground"
                aria-hidden="true"
            />
        </button>

        <!-- Panel flotante teletransportado a <body> -->
        <Teleport to="body">
            <div
                v-if="open"
                class="fixed inset-0 z-[9998]"
                style="pointer-events: auto"
                data-dismissable-layer
            >
                <div class="absolute inset-0" @mousedown.prevent="close()" />

                <FocusScope>
                    <div
                        :id="listboxId"
                        ref="panelRef"
                        :style="panelStyle"
                        role="dialog"
                        :aria-label="
                            label ? `Opciones de ${label}` : 'Opciones'
                        "
                        @keydown="onPanelKeydown"
                        class="z-[9999] overflow-hidden rounded-3xl border border-border bg-popover shadow-2xl"
                        @mousedown.stop
                    >
                        <div class="border-b border-border p-3">
                            <div class="relative">
                                <Search
                                    class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <input
                                    ref="searchRef"
                                    v-model="query"
                                    type="search"
                                    autocomplete="off"
                                    :aria-label="searchPlaceholder"
                                    :placeholder="searchPlaceholder"
                                    class="app-input pl-10"
                                />
                            </div>
                        </div>

                        <div class="max-h-[inherit] overflow-auto p-2">
                            <button
                                v-if="allowNull"
                                type="button"
                                data-ss-option
                                class="flex w-full items-center justify-between rounded-2xl px-3 py-2.5 text-left text-sm transition hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                                @click="pick(null)"
                            >
                                <span>{{ nullLabel }}</span>
                                <X
                                    class="h-4 w-4 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </button>

                            <button
                                v-for="option in filteredOptions"
                                :key="String(option[valueKey])"
                                type="button"
                                data-ss-option
                                :aria-pressed="
                                    String(modelValue) ===
                                    String(option[valueKey])
                                        ? 'true'
                                        : 'false'
                                "
                                class="flex w-full items-center justify-between gap-3 rounded-2xl px-3 py-2.5 text-left text-sm transition hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                                :class="
                                    String(modelValue) ===
                                    String(option[valueKey])
                                        ? 'bg-accent font-semibold text-accent-foreground'
                                        : ''
                                "
                                @click="pick(option[valueKey])"
                            >
                                <span class="truncate">
                                    {{ option[labelKey] }}
                                    <span
                                        v-if="option[secondaryKey]"
                                        class="ml-1 text-muted-foreground"
                                    >
                                        ({{ option[secondaryKey] }})
                                    </span>
                                </span>

                                <Check
                                    v-if="
                                        String(modelValue) ===
                                        String(option[valueKey])
                                    "
                                    class="h-4 w-4 shrink-0"
                                    aria-hidden="true"
                                />
                            </button>

                            <div
                                v-if="filteredOptions.length === 0"
                                role="status"
                                class="px-3 py-4 text-sm text-muted-foreground"
                            >
                                Sin resultados.
                            </div>
                        </div>
                    </div>
                </FocusScope>
            </div>
        </Teleport>

        <p
            v-if="description && !error"
            :id="`${buttonId}-help`"
            class="app-help"
        >
            {{ description }}
        </p>

        <p
            v-if="error"
            :id="`${buttonId}-error`"
            role="alert"
            class="app-field-error"
        >
            {{ error }}
        </p>
    </div>
</template>
