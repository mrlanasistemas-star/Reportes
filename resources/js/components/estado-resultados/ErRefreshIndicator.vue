<script setup lang="ts">
/**
 * ErRefreshIndicator — aviso flotante "Actualizando…" durante una recarga.
 *
 * Se usa en pages/EstadoResultados/Index.
 *
 * Props:
 * - active: hay una recarga en curso.
 * - label: qué se actualiza (se muestra "Actualizando <label>…").
 * - delay: milisegundos de espera antes de mostrarse (250 por defecto).
 */
import { Loader2 } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Indicador discreto al cambiar de periodo/alcance o recargar: lo anterior sigue visible
 * (atenuado por quien lo use) y aquí sólo se dice qué se está actualizando. Aparece tras
 * `delay` ms para no parpadear en recargas rápidas.
 */
const props = withDefaults(
    defineProps<{ active: boolean; label?: string | null; delay?: number }>(),
    { label: null, delay: 250 },
);

/** El aviso está visible. */
const shown = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

// Muestra el aviso tras `delay` ms de recarga activa; lo oculta al terminar.
watch(
    () => props.active,
    (on) => {
        clearTimeout(timer);

        if (on) {
            timer = setTimeout(() => (shown.value = true), props.delay);
        } else {
            shown.value = false;
        }
    },
    { immediate: true },
);
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div class="pointer-events-none sticky top-2 z-30 h-0" aria-live="polite">
        <Transition name="er-refresh">
            <div
                v-if="shown"
                role="status"
                class="mx-auto flex w-fit items-center gap-2 overflow-hidden rounded-full border bg-background/95 py-1.5 pr-4 pl-3 text-xs font-semibold shadow-soft-md backdrop-blur"
            >
                <Loader2
                    class="er-refresh-spin size-3.5 text-emerald-600 dark:text-emerald-300"
                    aria-hidden="true"
                />
                {{ label ? `Actualizando ${label}…` : 'Actualizando…' }}
            </div>
        </Transition>
    </div>
</template>

<style scoped>
.er-refresh-spin {
    animation: er-refresh-spin 0.9s linear infinite;
}
@keyframes er-refresh-spin {
    to {
        transform: rotate(360deg);
    }
}
.er-refresh-enter-active,
.er-refresh-leave-active {
    transition:
        opacity 160ms ease,
        transform 160ms ease;
}
.er-refresh-enter-from,
.er-refresh-leave-to {
    opacity: 0;
    transform: translateY(-4px);
}
@media (prefers-reduced-motion: reduce) {
    .er-refresh-spin {
        animation: none;
    }
    .er-refresh-enter-active,
    .er-refresh-leave-active {
        transition: none;
    }
}
</style>
