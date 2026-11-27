<script setup lang="ts">
/**
 * SmartSearch — buscador único del módulo. Se escribe lo que sea (sucursal,
 * ruta, nombre o parte de él, categoría, "baja"…) y la lista se filtra al
 * instante; además sugiere convertir lo escrito en un filtro de sucursal o
 * categoría, o abrir directo la ficha de una persona.
 *
 * Teclado: «/» o Ctrl+K enfoca · ↑ ↓ recorren sugerencias · Enter elige · Esc limpia.
 */
import {
    BriefcaseBusiness,
    Building2,
    CornerDownLeft,
    MapPin,
    Search,
    Sparkles,
    X,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import type { HardDataSuggestion } from '@/composables/useHardDataFilters';
import { useInitials } from '@/composables/useInitials';
import { categoryMeta } from '@/lib/hardData';
import CategoryBadge from './CategoryBadge.vue';
import HighlightText from './HighlightText.vue';

const props = defineProps<{
    suggestions: HardDataSuggestion[];
    tokens: string[];
    resultCount: number;
}>();
const query = defineModel<string>({ required: true });
const emit = defineEmits<{ pick: [s: HardDataSuggestion] }>();

const { getInitials } = useInitials();
const input = ref<HTMLInputElement | null>(null);
const focused = ref(false);
const active = ref(0);

const open = computed(() => focused.value && props.suggestions.length > 0);
const examples = ['orizaba', 'junior', 'baja', 'tula centro'];

watch(
    () => props.suggestions,
    () => (active.value = 0),
);

function pick(s: HardDataSuggestion): void {
    emit('pick', s);

    if (s.kind === 'person') {
        input.value?.blur();
    } else {
        input.value?.focus();
    }
}

function onKey(e: KeyboardEvent): void {
    if (e.key === 'ArrowDown' && open.value) {
        e.preventDefault();
        active.value = (active.value + 1) % props.suggestions.length;
    } else if (e.key === 'ArrowUp' && open.value) {
        e.preventDefault();
        active.value =
            (active.value - 1 + props.suggestions.length) %
            props.suggestions.length;
    } else if (e.key === 'Enter' && open.value) {
        e.preventDefault();
        pick(props.suggestions[active.value]);
    } else if (e.key === 'Escape') {
        if (query.value) {
            query.value = '';
        } else {
            input.value?.blur();
        }
    }
}

function onGlobalKey(e: KeyboardEvent): void {
    const target = e.target as HTMLElement | null;
    const typing =
        !!target &&
        (target.tagName === 'INPUT' ||
            target.tagName === 'TEXTAREA' ||
            target.isContentEditable);

    if (
        (e.key === '/' && !typing) ||
        (e.key.toLowerCase() === 'k' && (e.ctrlKey || e.metaKey))
    ) {
        e.preventDefault();
        input.value?.focus();
        input.value?.select();
    }
}

onMounted(() => window.addEventListener('keydown', onGlobalKey));
onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKey));

function suggestionKey(s: HardDataSuggestion): string {
    return s.kind === 'person' ? `p-${s.row.id}` : `${s.kind}-${s.value}`;
}

function suggestionHint(s: HardDataSuggestion): string {
    if (s.kind === 'person') {
        return '';
    }

    const what =
        s.kind === 'branch'
            ? 'Filtrar sucursal'
            : s.kind === 'route'
              ? 'Buscar por ruta'
              : s.kind === 'position'
                ? 'Filtrar puesto'
                : 'Filtrar categoría EBITDA';

    return `${what} · ${s.count} colaborador${s.count === 1 ? '' : 'es'}`;
}
</script>

<template>
    <div class="relative">
        <div
            :class="[
                'group flex items-center gap-3 rounded-2xl border bg-card px-4 py-3 shadow-soft-sm transition-all duration-200 sm:px-5 sm:py-4',
                focused
                    ? 'border-primary/50 ring-4 ring-primary/10'
                    : 'border-border hover:border-primary/30',
            ]"
        >
            <Search
                :class="[
                    'size-5 shrink-0 transition-colors',
                    focused ? 'text-primary' : 'text-muted-foreground',
                ]"
                aria-hidden="true"
            />
            <input
                ref="input"
                v-model="query"
                type="search"
                inputmode="search"
                autocomplete="off"
                spellcheck="false"
                role="combobox"
                aria-label="Buscar colaboradores, sucursales, rutas o categorías"
                aria-autocomplete="list"
                aria-controls="dd-suggestions"
                :aria-expanded="open"
                :aria-activedescendant="open ? `dd-sug-${active}` : undefined"
                placeholder="Escribe ORIZABA, una ruta, un nombre o solo una letra…"
                class="min-w-0 flex-1 bg-transparent text-base font-medium text-foreground outline-none placeholder:font-normal placeholder:text-muted-foreground/70 sm:text-lg [&::-webkit-search-cancel-button]:hidden"
                @focus="focused = true"
                @blur="focused = false"
                @keydown="onKey"
            />
            <span
                v-if="query"
                class="hidden shrink-0 rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-muted-foreground tabular-nums sm:inline"
                aria-live="polite"
                >{{ resultCount }}
                {{ resultCount === 1 ? 'resultado' : 'resultados' }}</span
            >
            <button
                v-if="query"
                type="button"
                class="flex size-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition hover:bg-muted hover:text-foreground"
                aria-label="Limpiar búsqueda"
                @mousedown.prevent
                @click="query = ''"
            >
                <X class="size-4" />
            </button>
            <kbd
                v-else
                class="hidden shrink-0 rounded-md border border-border bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground sm:inline"
                >/</kbd
            >
        </div>

        <div
            v-if="!query"
            class="mt-2 flex flex-wrap items-center gap-1.5 px-1 text-xs text-muted-foreground"
        >
            <Sparkles class="size-3.5 text-primary/70" aria-hidden="true" />
            <span>Prueba:</span>
            <button
                v-for="ex in examples"
                :key="ex"
                type="button"
                class="rounded-full border border-dashed border-border px-2 py-0.5 font-medium transition hover:border-primary/50 hover:text-primary"
                @click="query = ex"
            >
                {{ ex }}
            </button>
        </div>

        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0 -translate-y-1"
            leave-active-class="transition duration-100 ease-in"
            leave-to-class="opacity-0 -translate-y-1"
        >
            <ul
                v-if="open"
                id="dd-suggestions"
                role="listbox"
                class="absolute inset-x-0 top-full z-30 mt-2 max-h-[60vh] overflow-y-auto rounded-2xl border border-border bg-popover p-1.5 shadow-soft-lg"
            >
                <li
                    v-for="(s, i) in suggestions"
                    :id="`dd-sug-${i}`"
                    :key="suggestionKey(s)"
                    role="option"
                    :aria-selected="i === active"
                    :class="[
                        'flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2 text-sm transition-colors',
                        i === active
                            ? 'bg-accent text-accent-foreground'
                            : 'hover:bg-muted/60',
                    ]"
                    @mousedown.prevent
                    @mouseenter="active = i"
                    @click="pick(s)"
                >
                    <template v-if="s.kind === 'person'">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-[11px] font-bold text-primary"
                            >{{ getInitials(s.row.name) }}</span
                        >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold"
                                ><HighlightText
                                    :text="s.row.name"
                                    :tokens="tokens"
                            /></span>
                            <span
                                class="block truncate text-xs text-muted-foreground"
                                >{{ s.row.branch ?? 'Sin sucursal' }} · Ver
                                ficha</span
                            >
                        </span>
                        <CategoryBadge :category="s.row.category" />
                    </template>
                    <template v-else>
                        <span
                            :class="[
                                'flex size-8 shrink-0 items-center justify-center rounded-full',
                                s.kind === 'category'
                                    ? `${categoryMeta(s.value).chip} ring-1`
                                    : 'bg-info-soft text-info',
                            ]"
                        >
                            <Building2
                                v-if="s.kind === 'branch'"
                                class="size-4"
                            />
                            <MapPin
                                v-else-if="s.kind === 'route'"
                                class="size-4"
                            />
                            <BriefcaseBusiness
                                v-else-if="s.kind === 'position'"
                                class="size-4"
                            />
                            <Sparkles v-else class="size-4" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold">
                                <template v-if="s.kind === 'category'">{{
                                    categoryMeta(s.value).label
                                }}</template>
                                <HighlightText
                                    v-else
                                    :text="s.value"
                                    :tokens="tokens"
                                />
                            </span>
                            <span class="block text-xs text-muted-foreground">{{
                                suggestionHint(s)
                            }}</span>
                        </span>
                    </template>
                    <CornerDownLeft
                        v-if="i === active"
                        class="size-3.5 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                </li>
            </ul>
        </Transition>
    </div>
</template>
