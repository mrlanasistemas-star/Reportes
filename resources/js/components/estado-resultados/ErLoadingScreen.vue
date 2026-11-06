<script setup lang="ts">
import {
    AlertTriangle,
    Building2,
    FileSpreadsheet,
    Landmark,
    RefreshCw,
    Scale,
    Sigma,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';

/**
 * Pantalla de carga del Estado de Resultados (primera carga de un periodo/alcance).
 *
 * Honesta: el servidor no informa avance, así que NO hay porcentaje. Se explica qué se está
 * calculando, cuánto lleva la espera y, si tarda, por qué (primer cálculo del día) y qué
 * hacer. Aparece sólo si la carga pasa de `delay` ms: una carga rápida no parpadea.
 */
const props = withDefaults(
    defineProps<{
        /** Qué se está cargando, p. ej. «Junio 2026 · Consolidado MR LANA». */
        context?: string | null;
        /** Marca de tiempo (ms) en que empezó la carga; si falta, cuenta desde que se monta. */
        since?: number | null;
        /** Error de la carga: muestra el estado de error con «Intentar de nuevo». */
        error?: string | null;
        /** Muestra el botón «Cancelar» (deja de esperar esta carga). */
        cancellable?: boolean;
        delay?: number;
    }>(),
    {
        context: null,
        since: null,
        error: null,
        cancellable: false,
        delay: 600,
    },
);

const emit = defineEmits<{ retry: []; cancel: [] }>();

const STEPS = [
    { icon: FileSpreadsheet, label: 'Leyendo los estados de cuenta del banco' },
    { icon: Scale, label: 'Conciliando pagos con el ERP' },
    { icon: Building2, label: 'Trayendo ingresos y nómina de Radiografía' },
    { icon: Sigma, label: 'Calculando EBITDA y el resultado del mes' },
];

const start = props.since ?? Date.now();
const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    now.value = Date.now();
    timer = setInterval(() => (now.value = Date.now()), 500);
});
onBeforeUnmount(() => clearInterval(timer));

const ms = computed(() => Math.max(0, now.value - start));
const visible = computed(() => !!props.error || ms.value >= props.delay);
const seconds = computed(() => Math.floor(ms.value / 1000));
/** Paso resaltado: recorre la lista en bucle (decorativo, no es avance real). */
const highlighted = computed(() => Math.floor(ms.value / 1800) % STEPS.length);

const phase = computed<'normal' | 'first' | 'slow'>(() =>
    seconds.value >= 30 ? 'slow' : seconds.value >= 4 ? 'first' : 'normal',
);
const hint = computed(() =>
    phase.value === 'slow'
        ? 'Está tardando más de lo normal. Puedes seguir esperando o intentarlo de nuevo.'
        : phase.value === 'first'
          ? 'La primera consulta del día tarda unos segundos más porque calculamos todo desde cero. Después será casi inmediato.'
          : 'Esto suele tomar sólo unos segundos.',
);
const elapsedLabel = computed(() =>
    seconds.value < 60
        ? `${seconds.value} s`
        : `${Math.floor(seconds.value / 60)} min ${seconds.value % 60} s`,
);
</script>

<template>
    <!-- Antes del umbral: sólo reserva espacio (sin parpadeo en cargas rápidas). -->
    <div
        v-if="!visible"
        class="min-h-[420px]"
        aria-busy="true"
        aria-label="Cargando el Estado de Resultados"
    />

    <section
        v-else-if="error"
        role="alert"
        class="flex min-h-[420px] flex-col items-center justify-center gap-4 rounded-2xl border border-rose-200 bg-card px-6 py-12 text-center shadow-soft-xs dark:border-rose-900/60"
    >
        <span
            class="grid size-12 place-items-center rounded-2xl bg-rose-100 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300"
        >
            <AlertTriangle class="size-6" />
        </span>
        <div class="max-w-md space-y-1">
            <p class="text-base font-bold">
                No pudimos preparar el Estado de Resultados
            </p>
            <p class="text-sm text-muted-foreground">{{ error }}</p>
        </div>
        <Button class="rounded-xl" @click="emit('retry')">
            <RefreshCw class="size-4" /> Intentar de nuevo
        </Button>
    </section>

    <section
        v-else
        role="status"
        aria-live="polite"
        aria-busy="true"
        class="er-load relative grid min-h-[420px] overflow-hidden rounded-2xl border bg-card shadow-soft-xs md:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]"
    >
        <!-- Ilustración: la cascada del ER (ingresos → OPEX → EBITDA) armándose. -->
        <div
            class="relative flex items-end justify-center gap-2.5 border-b bg-gradient-to-b from-emerald-50/80 to-transparent px-8 pt-10 pb-8 md:border-r md:border-b-0 dark:from-emerald-950/30"
            aria-hidden="true"
        >
            <div class="flex h-44 w-full max-w-64 items-end gap-2.5">
                <span
                    class="er-load-bar flex-1 rounded-t-lg bg-emerald-500/85"
                    style="--h: 92%; --d: 0ms"
                />
                <span
                    class="er-load-bar flex-1 self-start rounded-b-lg bg-rose-400/70"
                    style="--h: 34%; --d: 160ms"
                />
                <span
                    class="er-load-bar flex-1 self-start rounded-b-lg bg-rose-400/50"
                    style="--h: 52%; --d: 320ms"
                />
                <span
                    class="er-load-bar flex-1 rounded-t-lg bg-emerald-600"
                    style="--h: 48%; --d: 480ms"
                />
                <span
                    class="er-load-bar flex-1 rounded-t-lg bg-emerald-700/80 dark:bg-emerald-400/80"
                    style="--h: 38%; --d: 640ms"
                />
            </div>
            <Landmark
                class="absolute top-5 left-5 size-5 text-emerald-700/70 dark:text-emerald-300/70"
            />
        </div>

        <div class="flex flex-col justify-center gap-5 p-6 md:p-8">
            <div class="space-y-1">
                <p
                    class="text-xs font-semibold tracking-wide text-emerald-700 uppercase dark:text-emerald-300"
                >
                    Preparando tu reporte
                </p>
                <h2 class="text-xl font-bold text-balance">
                    Estamos armando el Estado de Resultados
                </h2>
                <p v-if="context" class="text-sm text-muted-foreground">
                    {{ context }}
                </p>
            </div>

            <ul class="space-y-1.5" aria-label="Lo que incluye el cálculo">
                <li
                    v-for="(s, i) in STEPS"
                    :key="s.label"
                    class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm transition-colors duration-500"
                    :class="
                        i === highlighted
                            ? 'bg-emerald-50 font-semibold text-foreground dark:bg-emerald-950/40'
                            : 'text-muted-foreground'
                    "
                >
                    <component
                        :is="s.icon"
                        class="size-4 shrink-0"
                        :class="
                            i === highlighted
                                ? 'text-emerald-600 dark:text-emerald-300'
                                : ''
                        "
                        aria-hidden="true"
                    />
                    {{ s.label }}
                </li>
            </ul>

            <div class="space-y-2">
                <!-- Indeterminada a propósito: no sabemos el avance real. -->
                <div
                    class="h-1.5 overflow-hidden rounded-full bg-muted"
                    aria-hidden="true"
                >
                    <div
                        class="er-load-sweep h-full w-1/3 rounded-full bg-emerald-500"
                    />
                </div>
                <div
                    class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-xs"
                >
                    <p
                        class="max-w-prose"
                        :class="
                            phase === 'slow'
                                ? 'font-semibold text-amber-700 dark:text-amber-300'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ hint }}
                    </p>
                    <p
                        class="shrink-0 text-muted-foreground tabular-nums"
                        aria-hidden="true"
                    >
                        Llevamos {{ elapsedLabel }}
                    </p>
                </div>
            </div>

            <div
                v-if="phase === 'slow' || cancellable"
                class="flex flex-wrap gap-2"
            >
                <Button
                    v-if="phase === 'slow'"
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
    </section>
</template>

<style scoped>
.er-load {
    animation: er-load-in 260ms var(--ease-out, ease) both;
}
.er-load-bar {
    height: var(--h);
    transform-origin: bottom;
    animation: er-load-grow 2.4s var(--ease-out, ease) var(--d) infinite;
}
.er-load-bar.self-start {
    transform-origin: top;
}
.er-load-sweep {
    animation: er-load-sweep 1.6s ease-in-out infinite;
}
@keyframes er-load-in {
    from {
        opacity: 0;
        transform: translateY(6px);
    }
}
@keyframes er-load-grow {
    0% {
        transform: scaleY(0.15);
        opacity: 0.5;
    }
    35%,
    80% {
        transform: scaleY(1);
        opacity: 1;
    }
    100% {
        transform: scaleY(0.15);
        opacity: 0.5;
    }
}
@keyframes er-load-sweep {
    from {
        transform: translateX(-100%);
    }
    to {
        transform: translateX(300%);
    }
}
@media (prefers-reduced-motion: reduce) {
    .er-load,
    .er-load-bar,
    .er-load-sweep {
        animation: none;
    }
    .er-load-sweep {
        width: 100%;
        opacity: 0.45;
    }
}
</style>
