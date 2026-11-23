<script setup lang="ts">
/**
 * Select desplegable con búsqueda usado en el flujo Histórico general (sucursales,
 * colaboradores, periodos). Filtra por etiqueta o subetiqueta, cierra con Escape o clic fuera
 * y permite limpiar la selección.
 * Props: `items`, `modelValue` (id o null), `placeholder`, `disabled`.
 * Emits: `update:modelValue` (id o null). No llama rutas.
 */
import { Check, ChevronDown, Search, X } from 'lucide-vue-next';
import { computed, nextTick, ref, watch } from 'vue';

/** Opción del select: id numérico, etiqueta y subetiqueta opcional (p. ej. sucursal). */
interface SelectItem {
    id: number;
    label: string;
    sublabel?: string;
}

/** Props con valores por defecto: placeholder «Selecciona una opción», disabled false. */
const props = withDefaults(
    defineProps<{
        items: SelectItem[];
        modelValue: number | null;
        placeholder?: string;
        disabled?: boolean;
    }>(),
    {
        placeholder: 'Selecciona una opción',
        disabled: false,
    },
);

/** `update:modelValue`: nueva selección (null al limpiar). */
const emit = defineEmits<{
    (e: 'update:modelValue', value: number | null): void;
}>();

/** Si el desplegable está abierto. */
const open = ref(false);
/** Texto de búsqueda dentro del desplegable. */
const search = ref('');
/** Contenedor raíz (para detectar clics fuera). */
const containerRef = ref<HTMLDivElement | null>(null);
/** Input de búsqueda (recibe el foco al abrir). */
const searchRef = ref<HTMLInputElement | null>(null);

/** Opción seleccionada actualmente o null. */
const selected = computed(
    () => props.items.find((item) => item.id === props.modelValue) ?? null,
);

/** Opciones que coinciden con la búsqueda (todas si está vacía). */
const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();

    if (!q) {
        return props.items;
    }

    return props.items.filter(
        (item) =>
            item.label.toLowerCase().includes(q) ||
            (item.sublabel?.toLowerCase().includes(q) ?? false),
    );
});

/** Abre el desplegable, limpia la búsqueda y enfoca el input. */
function openDropdown() {
    if (props.disabled || open.value) {
        return;
    }

    open.value = true;
    search.value = '';
    nextTick(() => searchRef.value?.focus());
}

/** Cierra el desplegable y limpia la búsqueda. */
function closeDropdown() {
    open.value = false;
    search.value = '';
}

/** Alterna abrir/cerrar (sin efecto si está deshabilitado). */
function toggle() {
    if (props.disabled) {
        return;
    }

    if (open.value) {
        closeDropdown();
    } else {
        openDropdown();
    }
}

/**
 * Selecciona una opción y cierra.
 * @param id Id de la opción elegida.
 */
function pick(id: number) {
    emit('update:modelValue', id);
    closeDropdown();
}

/**
 * Limpia la selección sin abrir el desplegable.
 * @param event Clic del botón X (se detiene su propagación).
 */
function clear(event: Event) {
    event.stopPropagation();
    emit('update:modelValue', null);
}

/**
 * Cierra el desplegable con la tecla Escape.
 * @param e Evento de teclado.
 */
function onKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        e.stopPropagation();
        closeDropdown();
    }
}

/**
 * Cierra el desplegable si el clic ocurrió fuera del componente.
 * @param event Evento mousedown del documento.
 */
function onOutsideClick(event: MouseEvent) {
    if (
        containerRef.value &&
        !containerRef.value.contains(event.target as Node)
    ) {
        closeDropdown();
    }
}

/** Registra/retira el listener global de mousedown mientras el desplegable está abierto. */
watch(open, (val) => {
    if (val) {
        document.addEventListener('mousedown', onOutsideClick);
    } else {
        document.removeEventListener('mousedown', onOutsideClick);
    }
});
</script>

<template>
    <div ref="containerRef" class="relative" @keydown="onKeydown">
        <!-- Disparador: valor seleccionado / placeholder y botón limpiar -->
        <button
            type="button"
            class="flex h-11 w-full items-center justify-between gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 text-sm transition focus:ring-4 focus:ring-ring/30 focus:outline-none dark:border-slate-700 dark:bg-slate-800"
            :class="
                disabled
                    ? 'cursor-not-allowed opacity-50'
                    : 'hover:border-primary/30 hover:bg-accent/40'
            "
            :disabled="disabled"
            @click.stop="toggle"
        >
            <span
                v-if="selected"
                class="truncate font-semibold text-slate-900 dark:text-slate-100"
                >{{ selected.label }}</span
            >
            <span v-else class="truncate text-slate-400">{{
                placeholder
            }}</span>
            <span class="flex shrink-0 items-center gap-1">
                <button
                    v-if="selected && !disabled"
                    type="button"
                    class="rounded-full p-0.5 text-slate-400 transition hover:bg-rose-100 hover:text-rose-500 dark:hover:bg-rose-500/15 dark:hover:text-rose-400"
                    @click="clear"
                >
                    <X class="size-3.5" />
                </button>
                <ChevronDown
                    class="size-4 shrink-0 text-slate-400 transition"
                    :class="open ? 'rotate-180' : ''"
                />
            </span>
        </button>

        <!-- Panel desplegable: búsqueda y lista de opciones -->
        <transition name="dropdown">
            <div
                v-if="open"
                class="absolute z-50 mt-2 w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl shadow-slate-200/80 dark:border-slate-700 dark:bg-slate-900 dark:shadow-black/40"
            >
                <div
                    class="border-b border-slate-100 p-2 dark:border-slate-800"
                >
                    <div
                        class="flex items-center gap-2 rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800"
                    >
                        <Search class="size-4 shrink-0 text-slate-400" />
                        <input
                            ref="searchRef"
                            v-model="search"
                            type="text"
                            class="flex-1 bg-transparent text-sm outline-none placeholder:text-slate-400 dark:text-slate-100"
                            placeholder="Buscar..."
                            @keydown.esc.stop="closeDropdown"
                        />
                    </div>
                </div>
                <ul class="max-h-60 overflow-y-auto py-1">
                    <li
                        v-if="filtered.length === 0"
                        class="px-4 py-3 text-sm text-slate-400"
                    >
                        Sin resultados
                    </li>
                    <li
                        v-for="item in filtered"
                        :key="item.id"
                        class="flex cursor-pointer items-center gap-3 px-4 py-2.5 transition hover:bg-accent/60"
                        :class="modelValue === item.id ? 'bg-accent' : ''"
                        @mousedown.prevent="pick(item.id)"
                    >
                        <Check
                            class="size-4 shrink-0 text-primary transition"
                            :class="
                                modelValue === item.id
                                    ? 'opacity-100'
                                    : 'opacity-0'
                            "
                        />
                        <div class="min-w-0">
                            <p
                                class="truncate text-sm font-semibold text-slate-900 dark:text-slate-100"
                            >
                                {{ item.label }}
                            </p>
                            <p
                                v-if="item.sublabel"
                                class="truncate text-xs text-slate-500 dark:text-slate-400"
                            >
                                {{ item.sublabel }}
                            </p>
                        </div>
                    </li>
                </ul>
            </div>
        </transition>
    </div>
</template>

<style scoped>
.dropdown-enter-active,
.dropdown-leave-active {
    transition: all 150ms ease;
}
.dropdown-enter-from,
.dropdown-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}
</style>
