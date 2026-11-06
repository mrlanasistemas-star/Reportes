<script setup lang="ts">
import { ref } from 'vue';
import type { Component } from 'vue';
import { useTabIndicator } from '@/composables/useTabIndicator';

export interface ErTab {
    key: string;
    label: string;
    icon: Component;
    badge?: string | number | null;
    tone?: 'warn' | 'bad' | null;
}

const props = defineProps<{ tabs: ErTab[] }>();
const active = defineModel<string>({ required: true });

const nav = ref<HTMLElement | null>(null);
const { indicatorStyle, indicatorReady } = useTabIndicator(
    nav,
    () => active.value,
);

function onKey(e: KeyboardEvent, i: number): void {
    const dir = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;

    if (!dir) {
        return;
    }

    e.preventDefault();
    const next = props.tabs[(i + dir + props.tabs.length) % props.tabs.length];
    active.value = next.key;
    nav.value?.querySelector<HTMLElement>(`[data-tab="${next.key}"]`)?.focus();
}
</script>

<template>
    <div
        class="sticky top-0 z-20 -mx-4 border-b bg-background/90 px-4 backdrop-blur md:-mx-6 md:px-6"
    >
        <nav
            ref="nav"
            class="relative flex gap-0.5 overflow-x-auto [scrollbar-width:none]"
            role="tablist"
            aria-label="Secciones del Estado de Resultados"
        >
            <button
                v-for="(t, i) in tabs"
                :key="t.key"
                type="button"
                role="tab"
                :data-tab="t.key"
                :aria-selected="active === t.key"
                :tabindex="active === t.key ? 0 : -1"
                class="flex shrink-0 items-center gap-1.5 rounded-t-lg px-2 py-3.5 text-sm font-semibold transition-colors focus-visible:bg-muted focus-visible:outline-none"
                :class="
                    active === t.key
                        ? 'text-foreground'
                        : 'text-muted-foreground hover:text-foreground'
                "
                @click="active = t.key"
                @keydown="onKey($event, i)"
            >
                <component
                    :is="t.icon"
                    class="size-4"
                    :class="
                        active === t.key
                            ? 'text-emerald-600 dark:text-emerald-400'
                            : ''
                    "
                />
                {{ t.label }}
                <span
                    v-if="t.badge"
                    class="rounded-full px-1.5 py-px text-[10px] font-bold tabular-nums"
                    :class="
                        t.tone === 'bad'
                            ? 'bg-rose-500 text-white'
                            : 'bg-amber-400 text-amber-950'
                    "
                    >{{ t.badge }}</span
                >
            </button>
            <span
                class="app-tab-indicator"
                :style="indicatorStyle"
                :data-ready="indicatorReady"
                aria-hidden="true"
            />
        </nav>
    </div>
</template>
