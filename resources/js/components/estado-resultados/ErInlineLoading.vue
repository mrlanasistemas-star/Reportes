<script setup lang="ts">
import { LineChart, RefreshCw } from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

/**
 * Carga ligera dentro de una pestaña (p. ej. Comparativas). Sin porcentaje inventado:
 * barra indeterminada, segundos transcurridos y, si tarda, un aviso honesto con
 * «Cancelar» / «Intentar de nuevo». Se puede salir de la pestaña: la consulta sigue.
 */
const props = withDefaults(
    defineProps<{
        title?: string;
        /** Segundos transcurridos (los lleva el composable). */
        elapsed: number;
        /** Cuántos periodos/elementos se calculan, si se sabe (texto libre). */
        detail?: string | null;
        cancellable?: boolean;
        /** Avisa que se puede seguir trabajando en otras pestañas mientras calcula. */
        background?: boolean;
    }>(),
    {
        title: 'Calculando las comparativas',
        detail: null,
        cancellable: true,
        background: true,
    },
);

const emit = defineEmits<{ cancel: []; retry: [] }>();

const slow = computed(() => props.elapsed >= 30);
const hint = computed(() =>
    slow.value
        ? 'Está tardando más de lo normal. Puedes seguir esperando, cancelar o intentarlo de nuevo.'
        : props.elapsed >= 3
          ? 'La primera vez del día calculamos cada mes desde cero; después cambiar de vista es inmediato.'
          : 'Juntando los meses que se comparan…',
);
</script>

<template>
    <div
        class="flex flex-col items-center gap-4 rounded-2xl border bg-card px-6 py-10 text-center shadow-soft-xs"
        role="status"
        aria-live="polite"
        aria-busy="true"
    >
        <span
            class="er-inline-pulse grid size-11 place-items-center rounded-2xl bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300"
            aria-hidden="true"
        >
            <LineChart class="size-5" />
        </span>
        <div class="space-y-1">
            <p class="font-semibold">{{ title }}</p>
            <p
                class="mx-auto max-w-md text-sm"
                :class="
                    slow
                        ? 'font-semibold text-amber-700 dark:text-amber-300'
                        : 'text-muted-foreground'
                "
            >
                {{ hint }}
            </p>
            <p v-if="detail" class="text-xs text-muted-foreground">
                {{ detail }}
            </p>
        </div>
        <div
            class="h-1.5 w-full max-w-sm overflow-hidden rounded-full bg-muted"
            aria-hidden="true"
        >
            <div
                class="er-inline-sweep h-full w-1/3 rounded-full bg-emerald-500"
            />
        </div>
        <p
            class="text-xs text-muted-foreground tabular-nums"
            aria-hidden="true"
        >
            Llevamos {{ elapsed }} s<template v-if="background">
                · puedes ir a otra pestaña: te avisamos cuando esté
                listo</template
            >
        </p>
        <div
            v-if="cancellable || slow"
            class="flex flex-wrap justify-center gap-2"
        >
            <Button
                v-if="slow"
                size="sm"
                variant="outline"
                class="rounded-lg"
                @click="emit('retry')"
            >
                <RefreshCw class="size-3.5" /> Intentar de nuevo
            </Button>
            <Button
                v-if="cancellable"
                size="sm"
                variant="ghost"
                class="rounded-lg"
                @click="emit('cancel')"
            >
                Cancelar
            </Button>
        </div>
    </div>
</template>

<style scoped>
.er-inline-sweep {
    animation: er-inline-sweep 1.6s ease-in-out infinite;
}
.er-inline-pulse {
    animation: er-inline-pulse 2s ease-in-out infinite;
}
@keyframes er-inline-sweep {
    from {
        transform: translateX(-100%);
    }
    to {
        transform: translateX(300%);
    }
}
@keyframes er-inline-pulse {
    50% {
        transform: scale(1.06);
    }
}
@media (prefers-reduced-motion: reduce) {
    .er-inline-sweep,
    .er-inline-pulse {
        animation: none;
    }
    .er-inline-sweep {
        width: 100%;
        opacity: 0.45;
    }
}
</style>
