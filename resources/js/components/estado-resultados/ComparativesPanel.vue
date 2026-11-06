<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeftRight,
    CalendarClock,
    CalendarRange,
    History,
    Loader2,
    RefreshCw,
    Sigma,
    Store,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import {
    tabRequest,
    useErComparatives,
} from '@/composables/estado-resultados/useErComparatives';
import type { CompRequest } from '@/composables/estado-resultados/useErComparatives';
import BranchComparison from './BranchComparison.vue';
import type { CompMode } from './comparativas';
import ComparisonTable from './ComparisonTable.vue';
import ErInlineLoading from './ErInlineLoading.vue';
import TrendCharts from './TrendCharts.vue';

const props = defineProps<{
    periodId: number;
    scope: string;
    periods: Array<{ id: number; code: string; label: string }>;
    /** Revisión de los datos del periodo (huella + estado): al cambiar, se recalcula. */
    rev?: string | null;
}>();

/** Modo y periodo de contraste viven en la URL (los sincroniza el orquestador). */
const mode = defineModel<CompMode>('mode', { default: 'anterior' });
const contra = defineModel<number | null>('contra', { default: null });

const page = usePage();
const base = ref((page.props as { base?: string }).base ?? '');
const comp = useErComparatives(base, { notifyWhenReady: true });
/** Para que el orquestador (o un botón «Actualizar») pida de nuevo sin caché. */
defineExpose({ refresh: comp.refresh });

const MODES: Array<{
    key: CompMode;
    label: string;
    icon: unknown;
    hint: string;
}> = [
    {
        key: 'anterior',
        label: 'Periodo anterior',
        icon: History,
        hint: 'Contra el mes inmediato anterior.',
    },
    {
        key: 'anio_anterior',
        label: 'Mismo mes del año anterior',
        icon: CalendarClock,
        hint: 'Elimina la estacionalidad.',
    },
    {
        key: 'evolucion',
        label: 'Últimos 6 meses',
        icon: CalendarRange,
        hint: 'Tendencia y gráficas.',
    },
    {
        key: 'acumulado',
        label: 'Acumulado anual',
        icon: Sigma,
        hint: 'Del año contra el mismo tramo del año anterior.',
    },
    {
        key: 'personalizado',
        label: 'Personalizado',
        icon: ArrowLeftRight,
        hint: 'Dos periodos a elección.',
    },
    {
        key: 'sucursales',
        label: 'Sucursales',
        icon: Store,
        hint: 'Ranking y gastos centralizados aparte.',
    },
];

/** Periodo A del modo personalizado (por defecto, el periodo seleccionado arriba). */
const periodA = ref<number>(props.periodId);
watch(
    () => props.periodId,
    (v) => (periodA.value = v),
);

const request = computed<CompRequest | null>(() => {
    if (mode.value === 'personalizado') {
        return contra.value && contra.value !== periodA.value
            ? {
                  periodId: periodA.value,
                  scope: props.scope,
                  modes: ['personalizado'],
                  contra: contra.value,
                  charts: false,
                  rev: props.rev,
              }
            : null;
    }

    // Una sola consulta trae todos los modos y las gráficas; queda en caché (y el
    // orquestador la precarga con la misma clave al terminar el dataset).
    return tabRequest(props.periodId, props.scope, props.rev);
});

watch(
    request,
    (r) => {
        if (r) {
            comp.load(r);
        }
    },
    { immediate: true },
);

const d = computed(() => comp.data.value);
const pairFor = computed(() => {
    const m = d.value?.modes;

    if (!m) {
        return null;
    }

    return mode.value === 'anterior'
        ? m.anterior
        : mode.value === 'anio_anterior'
          ? m.anio_anterior
          : mode.value === 'personalizado'
            ? m.personalizado
            : null;
});
const samePeriods = computed(
    () =>
        mode.value === 'personalizado' &&
        !!contra.value &&
        contra.value === periodA.value,
);
</script>

<template>
    <div class="space-y-4">
        <nav class="flex flex-wrap gap-1.5" aria-label="Tipo de comparación">
            <button
                v-for="m in MODES"
                :key="m.key"
                type="button"
                class="inline-flex items-center gap-2 rounded-xl border px-3 py-2 text-sm font-semibold transition focus-visible:ring-3 focus-visible:ring-emerald-500/30 focus-visible:outline-none"
                :class="
                    mode === m.key
                        ? 'border-emerald-600 bg-emerald-600 text-white dark:border-emerald-500 dark:bg-emerald-500 dark:text-emerald-950'
                        : 'bg-card hover:border-foreground/30'
                "
                :aria-pressed="mode === m.key"
                :title="m.hint"
                @click="mode = m.key"
            >
                <component :is="m.icon" class="size-4" />
                {{ m.label }}
            </button>
            <button
                type="button"
                class="ml-auto inline-flex items-center gap-2 rounded-xl px-3 py-2 text-sm font-semibold text-muted-foreground transition hover:bg-muted hover:text-foreground focus-visible:ring-3 focus-visible:ring-emerald-500/30 focus-visible:outline-none disabled:opacity-50"
                :disabled="comp.loading.value || !request"
                :title="
                    d
                        ? `Calculado ${d.generated_at}. Vuelve a calcular con los datos más recientes.`
                        : 'Vuelve a calcular con los datos más recientes.'
                "
                @click="comp.refresh()"
            >
                <RefreshCw
                    class="size-4"
                    :class="
                        comp.loading.value
                            ? 'animate-spin motion-reduce:animate-none'
                            : ''
                    "
                />
                Actualizar
            </button>
        </nav>

        <!-- Personalizado: dos periodos -->
        <div
            v-if="mode === 'personalizado'"
            class="flex flex-wrap items-end gap-3 rounded-2xl border bg-card p-4 shadow-soft-xs"
        >
            <label
                class="flex min-w-60 flex-1 flex-col gap-1 text-xs font-semibold text-muted-foreground"
            >
                Periodo A
                <select
                    v-model.number="periodA"
                    class="h-10 rounded-xl border bg-background px-3 text-sm font-semibold text-foreground outline-none focus:border-emerald-500 focus:ring-3 focus:ring-emerald-500/20"
                >
                    <option v-for="p in periods" :key="p.id" :value="p.id">
                        {{ p.label }}
                    </option>
                </select>
            </label>
            <ArrowLeftRight
                class="mb-3 size-4 text-muted-foreground"
                aria-hidden="true"
            />
            <label
                class="flex min-w-60 flex-1 flex-col gap-1 text-xs font-semibold text-muted-foreground"
            >
                Periodo B (comparación)
                <select
                    :value="contra ?? ''"
                    class="h-10 rounded-xl border bg-background px-3 text-sm font-semibold text-foreground outline-none focus:border-emerald-500 focus:ring-3 focus:ring-emerald-500/20"
                    @change="
                        contra =
                            Number(
                                ($event.target as HTMLSelectElement).value,
                            ) || null
                    "
                >
                    <option value="" disabled>Elige el periodo…</option>
                    <option v-for="p in periods" :key="p.id" :value="p.id">
                        {{ p.label }}
                    </option>
                </select>
            </label>
            <p
                v-if="samePeriods"
                class="w-full text-xs font-semibold text-rose-700 dark:text-rose-300"
            >
                Elige dos periodos distintos.
            </p>
            <p v-else-if="!contra" class="w-full text-xs text-muted-foreground">
                Elige el periodo B para comparar. Si no son comparables
                (duración o fuentes distintas) te lo diremos y no mostraremos
                conclusiones porcentuales.
            </p>
        </div>

        <!-- Cargando sin datos previos: carga honesta (sin porcentaje), con cancelar. -->
        <ErInlineLoading
            v-if="comp.loading.value && !d"
            :elapsed="comp.elapsed.value"
            @cancel="comp.cancel()"
            @retry="request && comp.load(request, true)"
        />
        <div
            v-else-if="comp.error.value"
            class="flex items-start gap-3 rounded-2xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200"
            role="alert"
        >
            <AlertTriangle class="mt-0.5 size-5 shrink-0" />
            <div>
                <p class="font-bold">
                    No se pudieron calcular las comparativas
                </p>
                <p class="mt-0.5">{{ comp.error.value }}</p>
                <button
                    type="button"
                    class="mt-3 inline-flex items-center gap-1.5 rounded-lg border bg-white px-3 py-1.5 text-xs font-semibold text-rose-900 hover:bg-rose-100 dark:bg-transparent dark:text-rose-200"
                    @click="request && comp.load(request, true)"
                >
                    <RefreshCw class="size-3.5" /> Intentar de nuevo
                </button>
            </div>
        </div>

        <div
            v-else-if="!d && request && !comp.loading.value"
            class="flex flex-col items-center gap-3 rounded-2xl border border-dashed bg-card px-6 py-10 text-center text-sm"
        >
            <p class="text-muted-foreground">
                Dejaste de esperar las comparativas. Puedes calcularlas cuando
                quieras.
            </p>
            <button
                type="button"
                class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold hover:bg-muted"
                @click="comp.load(request)"
            >
                <RefreshCw class="size-3.5" /> Calcular ahora
            </button>
        </div>

        <template v-else-if="d">
            <!-- Recalculando con datos previos a la vista: se atenúan y se indica arriba. -->
            <p
                v-if="comp.loading.value"
                class="flex items-center gap-2 text-xs font-semibold text-muted-foreground"
                role="status"
            >
                <Loader2
                    class="size-3.5 animate-spin motion-reduce:animate-none"
                />
                Actualizando las comparativas… ({{ comp.elapsed.value }} s)
            </p>
            <p
                v-if="d.access.message"
                class="rounded-xl border bg-muted/50 px-4 py-2.5 text-xs font-medium"
            >
                {{ d.access.message }}
            </p>

            <ComparisonTable v-if="pairFor" :pair="pairFor" />

            <ComparisonTable
                v-else-if="mode === 'acumulado' && d.modes.acumulado"
                :pair="d.modes.acumulado.comparison"
                :current-label="d.modes.acumulado.current.label"
                :comparison-label="d.modes.acumulado.previous_year.label"
            />

            <template v-else-if="mode === 'evolucion'">
                <section
                    v-if="d.modes.evolucion"
                    class="rounded-2xl border bg-card px-5 py-3 text-sm shadow-soft-xs"
                >
                    <b>{{ d.modes.evolucion.label }}</b> ·
                    {{ d.modes.evolucion.range.from }} a
                    {{ d.modes.evolucion.range.to }} ·
                    {{ d.modes.evolucion.with_data }} con datos.
                    <span class="text-muted-foreground">{{
                        d.modes.evolucion.note
                    }}</span>
                </section>
                <TrendCharts :charts="d.charts" />
            </template>

            <BranchComparison
                v-else-if="mode === 'sucursales' && d.modes.sucursales"
                :branches="d.modes.sucursales"
            />

            <p
                v-else-if="mode !== 'personalizado'"
                class="rounded-2xl border border-dashed bg-card p-8 text-center text-sm text-muted-foreground"
            >
                Esta comparación no está disponible para el alcance elegido.
            </p>

            <p class="text-[11px] text-muted-foreground">
                {{ d.sources_note }} Calculado {{ d.generated_at }}.
            </p>
        </template>
    </div>
</template>
