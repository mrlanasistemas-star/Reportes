<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';
import {
    Building2,
    Check,
    ChevronDown,
    Landmark,
    Lock,
    Search,
    X,
} from 'lucide-vue-next';
import { computed, nextTick, ref, useId, watch } from 'vue';
import ErPickerSurface from './ErPickerSurface.vue';

export interface ScopeOptionItem {
    key: string;
    label: string;
}

const props = withDefaults(
    defineProps<{
        /** Alcances permitidos al usuario (el servidor ya filtró los de sucursal). */
        scopes: ScopeOptionItem[];
        loading?: boolean;
    }>(),
    { loading: false },
);

const scope = defineModel<string>({ required: true });

const CONSOLIDADO = 'CONSOLIDADO';
const LOWER = new Set(['de', 'del', 'la', 'las', 'los', 'y', 'el']);
const uid = useId();
const desktop = useMediaQuery('(min-width: 640px)');

type Group = 'consolidado' | 'sucursal' | 'corporativo' | 'direccion';

const GROUP_TITLE: Record<Exclude<Group, 'consolidado'>, string> = {
    sucursal: 'Sucursales',
    corporativo: 'Corporativo',
    direccion: 'Dirección general',
};

function normalize(s: string): string {
    return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
}

function groupOf(key: string): Group {
    const k = normalize(key);

    if (key === CONSOLIDADO) {
        return 'consolidado';
    }

    if (/corporativ/.test(k)) {
        return 'corporativo';
    }

    if (/direccion general|^dg$/.test(k)) {
        return 'direccion';
    }

    return 'sucursal';
}

function titleCase(s: string): string {
    return s
        .toLowerCase()
        .split(/\s+/)
        .map((w, i) =>
            i > 0 && LOWER.has(w) ? w : w.charAt(0).toUpperCase() + w.slice(1),
        )
        .join(' ');
}

/** Nombre a mostrar: sin el prefijo "Sucursal" y en tipo oración. */
function displayName(s: ScopeOptionItem): string {
    if (s.key === CONSOLIDADO) {
        return s.label || 'Consolidado MR LANA';
    }

    const name = s.label.replace(/^sucursal\s+/i, '') || s.key;

    return name === name.toUpperCase() ? titleCase(name) : name;
}

function initials(s: ScopeOptionItem): string {
    const words = displayName(s)
        .split(/\s+/)
        .filter((w) => !LOWER.has(w.toLowerCase()));

    return (
        (words.length > 1
            ? words[0][0] + words[1][0]
            : (words[0] ?? '?').slice(0, 2)
        ).toUpperCase() || '?'
    );
}

interface Item extends ScopeOptionItem {
    id: string;
    name: string;
    group: Group;
    initials: string;
}

const items = computed<Item[]>(() =>
    props.scopes.map((s, i) => ({
        ...s,
        id: `${uid}-opt-${i}`,
        name: displayName(s),
        group: groupOf(s.key),
        initials: initials(s),
    })),
);
const branchCount = computed(
    () => items.value.filter((i) => i.group === 'sucursal').length,
);
const selected = computed(
    () => items.value.find((i) => i.key === scope.value) ?? null,
);
const interactive = computed(() => items.value.length > 1);
const searchable = computed(() => items.value.length > 6);

function subline(i: Item | null): string {
    if (!i) {
        return 'Sin alcance';
    }

    if (i.group === 'consolidado') {
        return branchCount.value
            ? `Las ${branchCount.value} sucursales juntas`
            : 'Toda la empresa';
    }

    return i.group === 'sucursal' ? 'Sucursal' : GROUP_TITLE[i.group];
}

/* ---------- Búsqueda y navegación ---------- */
const open = ref(false);
const query = ref('');
const activeKey = ref<string | null>(null);
const inputEl = ref<HTMLInputElement | null>(null);
const listEl = ref<HTMLElement | null>(null);

const filtered = computed(() => {
    const q = normalize(query.value);

    return q
        ? items.value.filter(
              (i) =>
                  normalize(i.name).includes(q) || normalize(i.key).includes(q),
          )
        : items.value;
});
const hero = computed(
    () => filtered.value.find((i) => i.group === 'consolidado') ?? null,
);
const groups = computed(() =>
    (['sucursal', 'corporativo', 'direccion'] as const)
        .map((g) => ({
            key: g,
            title: GROUP_TITLE[g],
            items: filtered.value.filter((i) => i.group === g),
        }))
        .filter((g) => g.items.length),
);
/** Orden visual = orden de teclado. */
const ordered = computed(() => [
    ...(hero.value ? [hero.value] : []),
    ...groups.value.flatMap((g) => g.items),
]);
const activeId = computed(
    () => ordered.value.find((i) => i.key === activeKey.value)?.id,
);

watch(open, (on) => {
    if (on) {
        query.value = '';
        activeKey.value = scope.value;
    }
});
watch(filtered, (list) => {
    if (!list.some((i) => i.key === activeKey.value)) {
        activeKey.value = ordered.value[0]?.key ?? null;
    }
});

function onOpenFocus(e: Event): void {
    e.preventDefault();
    void nextTick(() => {
        // En teléfono no se abre el teclado de golpe: se enfoca la lista.
        if (searchable.value && desktop.value) {
            inputEl.value?.focus();
        } else {
            listEl.value?.focus();
        }

        scrollActive('center');
    });
}

function scrollActive(block: ScrollLogicalPosition = 'nearest'): void {
    void nextTick(() => {
        const el = activeId.value
            ? document.getElementById(activeId.value)
            : null;
        el?.scrollIntoView({ block });
    });
}

function move(delta: number | 'first' | 'last'): void {
    const list = ordered.value;

    if (!list.length) {
        return;
    }

    const i = list.findIndex((x) => x.key === activeKey.value);
    const next =
        delta === 'first'
            ? 0
            : delta === 'last'
              ? list.length - 1
              : Math.min(
                    list.length - 1,
                    Math.max(0, (i < 0 ? -1 : i) + delta),
                );
    activeKey.value = list[next].key;
    scrollActive();
}

function choose(key: string | null): void {
    if (!key) {
        return;
    }

    scope.value = key;
    open.value = false;
}

function onKey(e: KeyboardEvent): void {
    const map: Record<string, () => void> = {
        ArrowDown: () => move(1),
        ArrowUp: () => move(-1),
        PageDown: () => move(5),
        PageUp: () => move(-5),
        Enter: () => choose(activeKey.value),
    };

    // Inicio/Fin solo mueven en la lista, no dentro del texto de búsqueda.
    if (e.target !== inputEl.value) {
        map.Home = () => move('first');
        map.End = () => move('last');
        map[' '] = () => choose(activeKey.value);
    }

    const fn = map[e.key];

    if (fn) {
        e.preventDefault();
        fn();
    }
}

const avatarCls = (i: Item, isSelected: boolean) =>
    isSelected
        ? 'bg-cyan-700 text-white dark:bg-cyan-400 dark:text-cyan-950'
        : i.group === 'sucursal'
          ? 'bg-muted text-foreground/75 ring-1 ring-border dark:bg-white/[0.06]'
          : 'bg-violet-600/10 text-violet-700 ring-1 ring-violet-600/15 dark:bg-violet-400/10 dark:text-violet-300';
</script>

<template>
    <!-- Un solo alcance permitido: se muestra, no se elige. -->
    <div
        v-if="!interactive"
        class="flex h-12 min-w-0 items-center gap-2.5 rounded-xl bg-muted/55 px-3 ring-1 ring-border/70 ring-inset dark:bg-white/[0.035]"
        role="group"
        aria-label="Alcance"
    >
        <span
            class="grid size-8 shrink-0 place-items-center rounded-lg bg-cyan-600/10 text-xs font-semibold text-cyan-800 ring-1 ring-cyan-600/15 dark:bg-cyan-400/10 dark:text-cyan-300"
            aria-hidden="true"
        >
            {{ selected?.initials ?? '?' }}
        </span>
        <span class="flex min-w-0 flex-1 flex-col leading-tight">
            <span
                class="truncate text-[0.9375rem] font-semibold tracking-tight"
                >{{ selected?.name ?? 'Sin alcance' }}</span
            >
            <span class="truncate text-[0.6875rem] text-muted-foreground"
                >Tu acceso es solo a esta sucursal</span
            >
        </span>
        <Lock
            class="size-3.5 shrink-0 text-muted-foreground"
            aria-hidden="true"
        />
    </div>

    <ErPickerSurface
        v-else
        v-model:open="open"
        title="Elegir alcance"
        description="Elige el consolidado o una sucursal."
        align="start"
        content-class="w-[22rem]"
        @open-focus="onOpenFocus"
    >
        <template #trigger="{ toggle, open: isOpen }">
            <button
                type="button"
                class="group/trigger flex h-12 w-full min-w-0 items-center gap-2.5 rounded-xl bg-muted/55 px-3 text-left ring-1 ring-border/70 transition ring-inset hover:bg-muted focus-visible:ring-3 focus-visible:ring-cyan-500/35 focus-visible:outline-none aria-expanded:bg-background aria-expanded:ring-cyan-600/30 dark:bg-white/[0.035] dark:hover:bg-white/[0.07]"
                aria-haspopup="dialog"
                :aria-expanded="isOpen"
                :aria-busy="loading || undefined"
                :aria-label="`Alcance: ${selected?.name ?? 'sin elegir'}. Cambiar alcance`"
                @click="toggle"
            >
                <span
                    class="grid size-8 shrink-0 place-items-center rounded-lg text-xs font-semibold"
                    :class="
                        selected?.group === 'consolidado'
                            ? 'bg-linear-to-br from-emerald-600 to-cyan-700 text-white shadow-[inset_0_1px_0_rgb(255_255_255/0.25)] dark:from-emerald-400 dark:to-cyan-500 dark:text-slate-950'
                            : 'bg-cyan-600/10 text-cyan-800 ring-1 ring-cyan-600/15 dark:bg-cyan-400/10 dark:text-cyan-300'
                    "
                    aria-hidden="true"
                >
                    <Landmark
                        v-if="selected?.group === 'consolidado'"
                        class="size-4"
                    />
                    <template v-else>{{ selected?.initials ?? '?' }}</template>
                </span>
                <span class="flex min-w-0 flex-1 flex-col leading-tight">
                    <span
                        class="truncate text-[0.9375rem] font-semibold tracking-tight text-foreground"
                        >{{ selected?.name ?? 'Sin alcance' }}</span
                    >
                    <span
                        class="truncate text-[0.6875rem] text-muted-foreground"
                        >{{ subline(selected) }}</span
                    >
                </span>
                <ChevronDown
                    class="size-4 shrink-0 text-muted-foreground transition-transform duration-200 group-aria-expanded/trigger:rotate-180 motion-reduce:transition-none"
                    aria-hidden="true"
                />
            </button>
        </template>

        <div class="flex max-h-[min(32rem,75dvh)] flex-col" @keydown="onKey">
            <div v-if="searchable" class="border-b p-2.5">
                <label class="relative block">
                    <span class="sr-only">Buscar sucursal</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <input
                        ref="inputEl"
                        v-model="query"
                        type="text"
                        role="combobox"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="Buscar sucursal"
                        aria-autocomplete="list"
                        aria-expanded="true"
                        :aria-controls="`${uid}-list`"
                        :aria-activedescendant="activeId"
                        class="h-10 w-full rounded-lg bg-muted/60 pr-9 pl-9 text-sm outline-none placeholder:text-muted-foreground focus-visible:bg-background focus-visible:ring-2 focus-visible:ring-cyan-500/35 dark:bg-white/[0.05]"
                    />
                    <button
                        v-if="query"
                        type="button"
                        class="absolute top-1/2 right-1.5 grid size-7 -translate-y-1/2 place-items-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
                        aria-label="Borrar búsqueda"
                        @click="((query = ''), inputEl?.focus())"
                    >
                        <X class="size-3.5" />
                    </button>
                </label>
            </div>

            <div
                :id="`${uid}-list`"
                ref="listEl"
                role="listbox"
                aria-label="Alcance"
                :tabindex="searchable && desktop ? -1 : 0"
                :aria-activedescendant="activeId"
                class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-2 outline-none"
            >
                <!-- Consolidado: primera opción y destacada -->
                <div
                    v-if="hero"
                    :id="hero.id"
                    role="option"
                    :aria-selected="hero.key === scope"
                    class="relative mb-1.5 flex cursor-pointer items-center gap-3 rounded-xl border p-2.5 transition"
                    :class="[
                        hero.key === scope
                            ? 'border-emerald-600/35 bg-emerald-600/[0.07] dark:border-emerald-400/30 dark:bg-emerald-400/[0.08]'
                            : 'border-border/70 hover:bg-muted/70',
                        activeKey === hero.key && 'ring-2 ring-cyan-500/35',
                    ]"
                    @click="choose(hero.key)"
                    @mousemove="activeKey = hero.key"
                >
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-xl bg-linear-to-br from-emerald-600 to-cyan-700 text-white shadow-[inset_0_1px_0_rgb(255_255_255/0.25),0_6px_14px_-8px_rgb(4_120_87/0.9)] dark:from-emerald-400 dark:to-cyan-500 dark:text-slate-950"
                        aria-hidden="true"
                    >
                        <Landmark class="size-5" />
                    </span>
                    <span class="min-w-0 flex-1 leading-tight">
                        <span class="block truncate text-sm font-semibold">{{
                            hero.name
                        }}</span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                            >{{ subline(hero) }}</span
                        >
                    </span>
                    <Check
                        v-if="hero.key === scope"
                        class="size-4 shrink-0 text-emerald-700 dark:text-emerald-400"
                        aria-hidden="true"
                    />
                </div>

                <div
                    v-for="g in groups"
                    :key="g.key"
                    role="group"
                    :aria-labelledby="`${uid}-g-${g.key}`"
                    class="mt-1"
                >
                    <p
                        :id="`${uid}-g-${g.key}`"
                        class="flex items-center gap-1.5 px-2 pt-2 pb-1 text-xs font-medium text-muted-foreground"
                    >
                        <Building2 class="size-3.5" aria-hidden="true" />
                        {{ g.title }}
                        <span class="tabular-nums opacity-70">{{
                            g.items.length
                        }}</span>
                    </p>
                    <div
                        v-for="i in g.items"
                        :id="i.id"
                        :key="i.key"
                        role="option"
                        :aria-selected="i.key === scope"
                        class="flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 text-sm transition-colors"
                        :class="[
                            activeKey === i.key
                                ? 'bg-muted text-foreground dark:bg-white/[0.07]'
                                : 'text-foreground/90',
                            i.key === scope && 'font-semibold',
                        ]"
                        @click="choose(i.key)"
                        @mousemove="activeKey = i.key"
                    >
                        <span
                            class="grid size-7 shrink-0 place-items-center rounded-md text-[0.6875rem] font-semibold tracking-wide transition-colors"
                            :class="avatarCls(i, i.key === scope)"
                            aria-hidden="true"
                            >{{ i.initials }}</span
                        >
                        <span class="min-w-0 flex-1 truncate">{{
                            i.name
                        }}</span>
                        <Check
                            v-if="i.key === scope"
                            class="size-4 shrink-0 text-cyan-700 dark:text-cyan-400"
                            aria-hidden="true"
                        />
                    </div>
                </div>

                <p
                    v-if="!ordered.length"
                    class="px-3 py-8 text-center text-sm text-muted-foreground"
                >
                    Ninguna sucursal coincide con «{{ query }}».
                </p>
            </div>

            <p
                class="hidden border-t bg-muted/40 px-3 py-2 text-[0.6875rem] text-muted-foreground sm:block dark:bg-white/[0.02]"
                aria-hidden="true"
            >
                <kbd class="rounded border bg-background px-1 font-sans">↑</kbd>
                <kbd class="ml-0.5 rounded border bg-background px-1 font-sans"
                    >↓</kbd
                >
                para moverte,
                <kbd class="rounded border bg-background px-1 font-sans"
                    >Enter</kbd
                >
                para elegir,
                <kbd class="rounded border bg-background px-1 font-sans"
                    >Esc</kbd
                >
                para cerrar
            </p>
        </div>
    </ErPickerSurface>
</template>
