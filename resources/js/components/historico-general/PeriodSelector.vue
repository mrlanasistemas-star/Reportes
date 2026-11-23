<script setup lang="ts">
/**
 * Selector de periodo del flujo Histórico general (paso ①). Lista buscable de periodos
 * (excluye semanas base) con su tipo y estatus resumido; muestra el periodo elegido.
 * Props: `periods` (de ReportUploadController::index()) y `modelValue` (id seleccionado).
 * Emits: `update:modelValue` con el id elegido (v-model). No llama rutas.
 */
import { CalendarDays, CheckCircle2, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import StatusBadge from './StatusBadge.vue';

/** Props: catálogo de periodos y id seleccionado (v-model). */
const props = defineProps<{ periods: any[]; modelValue: number | null }>();
/** `update:modelValue`: el usuario eligió otro periodo. */
const emit = defineEmits<{
    (event: 'update:modelValue', value: number): void;
}>();
/** Texto de búsqueda (por etiqueta, código o tipo). */
const query = ref('');

/** Periodo actualmente seleccionado o null. */
const selected = computed(
    () =>
        props.periods.find((period) => period.id === props.modelValue) ?? null,
);
/** Periodos elegibles: todos menos las semanas base (los archivos se suben al mes operativo). */
const nonWeeklyPeriods = computed(() =>
    props.periods.filter((p) => p.type !== 'weekly'),
);

/** Periodos visibles: los primeros 18 sin búsqueda, o hasta 24 coincidencias con búsqueda. */
const filteredPeriods = computed(() => {
    const term = query.value.trim().toLowerCase();

    if (!term) {
        return nonWeeklyPeriods.value.slice(0, 18);
    }

    return nonWeeklyPeriods.value
        .filter((period) =>
            [period.label, period.code, period.type]
                .join(' ')
                .toLowerCase()
                .includes(term),
        )
        .slice(0, 24);
});

/**
 * Nombre en español del tipo de periodo.
 * @param type Tipo (weekly, monthly, bimonthly, quarterly, semiannual, annual).
 * @returns Etiqueta legible; «Periodo» si es desconocido.
 */
const typeLabel = (type?: string) =>
    (
        ({
            weekly: 'Semana base',
            monthly: 'Mes operativo',
            bimonthly: 'Bimestre automático',
            quarterly: 'Trimestre automático',
            semiannual: 'Semestre automático',
            annual: 'Anual automático',
        }) as Record<string, string>
    )[type ?? ''] ?? 'Periodo';

/**
 * Estado de un periodo derivado: consolidado, listo para consolidar o esperando meses.
 * @param period Periodo derivado.
 * @returns Código de estado para StatusBadge.
 */
const automaticStatus = (period: any): string => {
    if (period.radiography_ready) {
        return 'consolidated';
    }

    if (period.can_generate_automatic) {
        return 'ready_to_consolidate';
    }

    return 'waiting';
};

/**
 * Código de estatus resumido de un periodo para StatusBadge (error, bloqueado por fuentes
 * faltantes, fuentes sin procesar, o completo; los derivados usan automaticStatus()).
 * @param period Periodo a evaluar.
 * @returns Código de estado.
 */
const status = (period: any): string => {
    if (period.is_derived) {
        return automaticStatus(period);
    }

    if (period.failed_count > 0) {
        return 'error';
    }

    if (period.missing_sources_count > 0) {
        return 'blocked';
    }

    if ((period.unprocessed_radiography_sources?.length ?? 0) > 0) {
        return 'running';
    }

    return 'completed';
};

/**
 * Texto del estatus resumido de un periodo (mismas reglas que status()).
 * @param period Periodo a evaluar.
 * @returns Etiqueta en español.
 */
const statusLabel = (period: any): string => {
    if (period.is_derived) {
        return (
            (
                {
                    consolidated: 'Consolidado',
                    ready_to_consolidate: 'Listo para consolidar',
                    waiting: 'Esperando meses',
                } as Record<string, string>
            )[automaticStatus(period)] ?? 'Automático'
        );
    }

    if (period.failed_count > 0) {
        return 'Con error';
    }

    if (period.missing_sources_count > 0) {
        return 'Incompleto';
    }

    // Fase 8.6: fuentes cargadas que aún no se leen — no es "procesando".
    if ((period.unprocessed_radiography_sources?.length ?? 0) > 0) {
        return 'Sin procesar';
    }

    return 'Completo';
};
</script>

<template>
    <section class="rounded-2xl border bg-card p-4 shadow-soft-xs sm:p-5">
        <div class="grid gap-5 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.2fr)]">
            <!-- Columna izquierda: explicación y periodo seleccionado -->
            <div>
                <div class="flex items-center gap-3">
                    <div
                        class="flex size-10 items-center justify-center rounded-xl border bg-accent text-primary"
                    >
                        <CalendarDays class="size-5" />
                    </div>
                    <div>
                        <p class="app-kicker">Paso 1</p>
                        <h2 class="text-lg font-semibold text-foreground">
                            Selecciona el periodo
                        </h2>
                    </div>
                </div>
                <p class="mt-3 text-sm leading-6 text-muted-foreground">
                    Selecciona un <strong>mes operativo</strong> para cargar
                    archivos y generar reportes. Los periodos automáticos
                    (bimestre, trimestre, etc.) solo permiten generar reportes.
                </p>
                <div
                    v-if="selected"
                    class="mt-4 rounded-xl border border-primary/25 bg-accent/60 p-4"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                Periodo seleccionado
                            </p>
                            <p
                                class="mt-0.5 text-sm font-semibold text-foreground"
                            >
                                {{ selected.label }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ selected.code }} ·
                                {{ typeLabel(selected.type) }}
                            </p>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ selected.start_date }} →
                                {{ selected.end_date }}
                            </p>
                        </div>
                        <StatusBadge
                            :status="status(selected)"
                            :label="statusLabel(selected)"
                        />
                    </div>
                </div>
            </div>

            <!-- Columna derecha: búsqueda y lista de periodos -->
            <div>
                <label class="relative block">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <input
                        v-model="query"
                        type="search"
                        class="app-input h-10 rounded-lg pl-10"
                        aria-label="Buscar periodo"
                        placeholder="Buscar por nombre, código o tipo de periodo…"
                    />
                </label>

                <div
                    class="mt-3 grid max-h-[19rem] gap-2 overflow-y-auto pr-1 xl:grid-cols-2"
                    role="listbox"
                    aria-label="Periodos"
                >
                    <button
                        v-for="period in filteredPeriods"
                        :key="period.id"
                        type="button"
                        role="option"
                        :aria-selected="modelValue === period.id"
                        class="w-full rounded-xl border p-3 text-left transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :class="
                            modelValue === period.id
                                ? 'border-primary bg-accent'
                                : 'border-border bg-card hover:bg-muted/50'
                        "
                        @click="emit('update:modelValue', period.id)"
                    >
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p
                                    class="truncate text-sm font-semibold text-foreground"
                                >
                                    {{ period.label }}
                                </p>
                                <p class="mt-0.5 text-xs text-muted-foreground">
                                    {{ typeLabel(period.type) }} ·
                                    {{ period.start_date }} →
                                    {{ period.end_date }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ period.uploaded_sources_count }}/{{
                                        period.required_sources_count
                                    }}
                                    fuentes ·
                                    {{
                                        period.pending_critical_incidents_count ??
                                        0
                                    }}
                                    incidencia(s) crítica(s)
                                </p>
                            </div>
                            <div
                                class="flex shrink-0 flex-col items-end gap-1.5"
                            >
                                <CheckCircle2
                                    v-if="modelValue === period.id"
                                    class="size-4 text-primary"
                                    aria-hidden="true"
                                />
                                <StatusBadge
                                    :status="status(period)"
                                    :label="statusLabel(period)"
                                />
                            </div>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </section>
</template>
