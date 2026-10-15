<script setup lang="ts">
import type { Component } from 'vue';

// Fase 8.12: tarjeta de sección de Configuración — encabezado con icono,
// cuerpo y pie opcional para la acción principal (alineada a la derecha).
withDefaults(
    defineProps<{
        title: string;
        description?: string;
        icon?: Component;
        tone?: 'default' | 'danger';
    }>(),
    { description: undefined, icon: undefined, tone: 'default' },
);
</script>

<template>
    <section
        :class="[
            'overflow-hidden rounded-2xl border bg-card text-card-foreground shadow-soft-xs',
            tone === 'danger' ? 'border-danger-border' : '',
        ]"
    >
        <header class="flex items-start gap-3 border-b px-5 py-4 sm:px-6">
            <span
                v-if="icon"
                :class="[
                    'flex size-9 shrink-0 items-center justify-center rounded-lg border',
                    tone === 'danger' ? 'border-danger-border bg-danger-soft text-danger' : 'bg-accent text-primary',
                ]"
            >
                <component :is="icon" class="size-4" aria-hidden="true" />
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="app-section-title">{{ title }}</h2>
                <p v-if="description" class="mt-0.5 text-sm text-muted-foreground">{{ description }}</p>
            </div>
            <slot name="badge" />
        </header>

        <div class="px-5 py-5 sm:px-6">
            <slot />
        </div>

        <footer v-if="$slots.footer" class="flex flex-wrap items-center justify-end gap-3 border-t bg-muted/40 px-5 py-3 sm:px-6">
            <slot name="footer" />
        </footer>
    </section>
</template>
