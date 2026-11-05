<script setup lang="ts">
import { Check } from 'lucide-vue-next';

defineProps<{
    numero: number;
    titulo: string;
    descripcion?: string;
    /** completo = paso resuelto · activo = paso actual · pendiente = aún no disponible. */
    estado: 'completo' | 'activo' | 'pendiente';
}>();
</script>

<template>
    <section
        class="rounded-2xl border bg-card shadow-sm transition-shadow duration-300"
        :class="estado === 'activo' ? 'shadow-md ring-1 ring-primary/20' : ''"
        :aria-labelledby="`paso-${numero}`"
    >
        <header
            class="flex flex-wrap items-start justify-between gap-3 px-5 pt-5 md:px-6"
        >
            <div class="flex min-w-0 items-start gap-3">
                <span
                    class="grid size-8 shrink-0 place-items-center rounded-full text-sm font-semibold transition-colors duration-300"
                    :class="{
                        'bg-emerald-600 text-white dark:bg-emerald-500':
                            estado === 'completo',
                        'bg-primary text-primary-foreground':
                            estado === 'activo',
                        'bg-muted text-muted-foreground':
                            estado === 'pendiente',
                    }"
                    aria-hidden="true"
                >
                    <Check v-if="estado === 'completo'" class="size-4" />
                    <template v-else>{{ numero }}</template>
                </span>
                <div class="min-w-0">
                    <h2 :id="`paso-${numero}`" class="font-semibold">
                        <span class="sr-only">Paso {{ numero }}: </span
                        >{{ titulo }}
                    </h2>
                    <p
                        v-if="descripcion"
                        class="mt-0.5 text-sm text-muted-foreground"
                    >
                        {{ descripcion }}
                    </p>
                </div>
            </div>
            <div v-if="$slots.acciones" class="flex flex-wrap gap-2">
                <slot name="acciones" />
            </div>
        </header>
        <div class="space-y-4 px-5 pt-4 pb-5 md:px-6 md:pb-6">
            <slot />
        </div>
    </section>
</template>
