<script setup lang="ts">
/**
 * MissingSourcesBanner — aviso de fuentes pendientes de cargar.
 *
 * Se usa en pages/EstadoResultados/Index. Solo aparece si falta alguna fuente;
 * es ámbar si hay obligatorias pendientes y azul si solo faltan opcionales.
 * Lista hasta tres nombres.
 *
 * Props:
 * - checklist: lista de fuentes con su estado.
 *
 * Emits:
 * - go: el usuario pulsa "Ver qué falta" (ir a la pestaña Fuentes).
 */
import { ArrowRight, FolderInput } from 'lucide-vue-next';
import { computed } from 'vue';
import type { SourceChecklist } from './types';

/** Aviso bajo la barra superior: cuántas fuentes faltan y un acceso directo a Fuentes. */
const props = defineProps<{ checklist: SourceChecklist }>();
defineEmits<{ go: [] }>();

/** Fuentes que aún no están listas. */
const pending = computed(() =>
    props.checklist.items.filter((i) => i.state !== 'lista'),
);
/** Fuentes pendientes que son obligatorias. */
const required = computed(() => pending.value.filter((i) => i.required));
/** Hasta tres nombres pendientes (sin el prefijo "Estado de cuenta: "), con "…" si hay más. */
const names = computed(
    () =>
        pending.value
            .slice(0, 3)
            .map((i) => i.label.replace(/^Estado de cuenta: /, ''))
            .join(', ') + (pending.value.length > 3 ? '…' : ''),
);
</script>

<template>
    <div
        v-if="pending.length"
        role="status"
        class="flex flex-col gap-2 rounded-2xl border px-4 py-3 text-sm sm:flex-row sm:items-center"
        :class="
            required.length
                ? 'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100'
                : 'border-sky-200 bg-sky-50 text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100'
        "
    >
        <FolderInput class="size-4.5 shrink-0" aria-hidden="true" />
        <p class="min-w-0 flex-1">
            <b>{{
                pending.length === 1
                    ? 'Falta 1 fuente'
                    : `Faltan ${pending.length} fuentes`
            }}</b>
            <!-- Desglose sólo si hay obligatorias mezcladas con opcionales;
                 si todas son opcionales se dice así (nunca «0 obligatorias»). -->
            <span v-if="!required.length"> (todas opcionales)</span>
            <span v-else-if="required.length !== pending.length">
                ({{ required.length }} obligatoria{{
                    required.length === 1 ? '' : 's'
                }})</span
            >: {{ names }}
        </p>
        <button
            type="button"
            class="inline-flex shrink-0 items-center gap-1 self-start rounded-lg bg-foreground px-3 py-1.5 text-xs font-bold text-background transition hover:opacity-90 focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:outline-none sm:self-auto"
            @click="$emit('go')"
        >
            Ver qué falta <ArrowRight class="size-3.5" />
        </button>
    </div>
</template>
