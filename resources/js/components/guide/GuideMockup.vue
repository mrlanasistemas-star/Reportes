<script setup lang="ts">
/**
 * GuideMockup — marco de "ventana" ilustrativa para las capturas simuladas del manual.
 *
 * Lo usa GuideManual. El contenido simulado se pasa por el slot por defecto.
 *
 * Props:
 * - title: texto de la barra de título de la ventana.
 */
defineProps<{ title: string }>();
</script>

<template>
    <div
        class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm"
    >
        <div
            class="flex items-center gap-1.5 border-b border-slate-100 bg-slate-50 px-3 py-2"
        >
            <span class="size-2 rounded-full bg-rose-300"></span>
            <span class="size-2 rounded-full bg-amber-300"></span>
            <span class="size-2 rounded-full bg-emerald-300"></span>
            <span
                class="ml-2 truncate text-[11px] font-semibold text-slate-500"
                >{{ title }}</span
            >
        </div>
        <div class="bg-slate-50/60 p-4">
            <slot />
        </div>
    </div>
</template>
