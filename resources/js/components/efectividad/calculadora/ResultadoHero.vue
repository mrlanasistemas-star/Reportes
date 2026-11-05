<script setup lang="ts">
import {
    ArrowDown,
    CheckCircle2,
    Clock,
    Info,
    ShieldAlert,
} from 'lucide-vue-next';
import { computed } from 'vue';
import EstadoReporteBadge from './EstadoReporteBadge.vue';
import type { Resultado } from './types';
import {
    colorMora,
    fmtFecha,
    fmtMoney,
    fmtPct,
    semaforo,
    tienePct,
} from './types';

const props = defineProps<{
    resultado: Resultado;
    /** Tiempo que tardó el cálculo (ms); null si no se midió. */
    duracionMs?: number | null;
}>();

defineEmits<{ verControl: [] }>();

const MODO: Record<Resultado['modo'], string> = {
    DIARIO: 'Diario',
    ACUMULADO: 'Acumulado',
    SEMANAL: 'Semanal',
    MENSUAL: 'Mensual',
};

/** GRAN TOTAL del reporte completo (sin filtros). */
const total = computed(
    () => props.resultado.filas.find((f) => f.tipo === 'gran_total') ?? null,
);
const p = computed(() => props.resultado.parametros);
const auditoria = computed(() => props.resultado.auditoria ?? null);
const preliminar = computed(() => auditoria.value?.estado === 'PRELIMINAR');

const duracion = computed(() => {
    const ms = props.duracionMs;

    if (ms === null || ms === undefined || !Number.isFinite(ms)) {
        return null;
    }

    return ms < 1000
        ? 'menos de 1 s'
        : `${(ms / 1000).toLocaleString('es-MX', { maximumFractionDigits: 1 })} s`;
});

const efectividadColor = computed(() =>
    total.value ? semaforo(total.value) || 'text-foreground' : '',
);
</script>

<template>
    <article
        class="relative overflow-hidden rounded-2xl border shadow-sm"
        :class="
            total
                ? 'border-emerald-200/80 bg-gradient-to-br from-emerald-50/80 via-card to-card dark:border-emerald-900/60 dark:from-emerald-950/30'
                : 'bg-card'
        "
        aria-labelledby="resultado-hero-titulo"
    >
        <div class="space-y-6 p-5 md:p-6">
            <!-- Encabezado: confirmación + estado del reporte -->
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex items-start gap-3">
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-full"
                        :class="
                            total
                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/60 dark:text-emerald-300'
                                : 'bg-muted text-muted-foreground'
                        "
                        aria-hidden="true"
                    >
                        <CheckCircle2 v-if="total" class="size-5" />
                        <Info v-else class="size-5" />
                    </span>
                    <div class="min-w-0">
                        <p
                            class="text-xs font-semibold tracking-widest uppercase"
                            :class="
                                total
                                    ? 'text-emerald-700 dark:text-emerald-400'
                                    : 'text-muted-foreground'
                            "
                        >
                            {{
                                total
                                    ? 'Reporte generado'
                                    : 'Cálculo terminado sin movimientos'
                            }}
                            · {{ MODO[resultado.modo] }}
                        </p>
                        <h2
                            id="resultado-hero-titulo"
                            class="mt-0.5 text-xl font-semibold tracking-tight md:text-2xl"
                        >
                            {{ resultado.titulo }}
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Ingresos del {{ fmtFecha(p.inicio_ingresos) }} al
                            {{ fmtFecha(p.fin_ingresos) }} · Corte
                            {{ fmtFecha(p.fecha_corte) }} · Domingo base
                            {{ fmtFecha(p.domingo_base) }}
                        </p>
                    </div>
                </div>
                <div class="flex flex-col items-start gap-1.5 sm:items-end">
                    <EstadoReporteBadge
                        v-if="auditoria"
                        :auditoria="auditoria"
                    />
                    <p
                        class="flex items-center gap-1 text-xs text-muted-foreground"
                    >
                        <Clock class="size-3.5" aria-hidden="true" />
                        Generado {{ resultado.generado
                        }}<template v-if="duracion">
                            · en {{ duracion }}</template
                        >
                    </p>
                </div>
            </div>

            <!-- Cifras principales -->
            <div
                v-if="total"
                class="grid gap-3 lg:grid-cols-[minmax(0,1.2fr)_repeat(3,minmax(0,1fr))]"
            >
                <div
                    class="rounded-2xl border bg-card/80 p-4 shadow-xs backdrop-blur-sm"
                >
                    <p class="text-xs font-medium text-muted-foreground">
                        Efectividad total · GRAN TOTAL
                    </p>
                    <p
                        class="mt-1 text-4xl font-semibold tracking-tight tabular-nums md:text-5xl"
                        :class="
                            tienePct(total.pct_total)
                                ? efectividadColor
                                : 'text-muted-foreground'
                        "
                    >
                        {{ fmtPct(total.pct_total) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{
                            tienePct(total.pct_total)
                                ? 'Cobranza total sobre Sano por cobrar, sin tope.'
                                : 'Sin base: no hay Sano por cobrar en este corte.'
                        }}
                    </p>
                </div>
                <div class="rounded-2xl border bg-card/80 p-4 shadow-xs">
                    <p class="text-xs font-medium text-muted-foreground">
                        Sano por cobrar
                    </p>
                    <p
                        class="mt-1 text-xl font-semibold tabular-nums md:text-2xl"
                    >
                        {{ fmtMoney(total.sano_pc) }}
                    </p>
                </div>
                <div class="rounded-2xl border bg-card/80 p-4 shadow-xs">
                    <p class="text-xs font-medium text-muted-foreground">
                        Cobranza total
                    </p>
                    <p
                        class="mt-1 text-xl font-semibold tabular-nums md:text-2xl"
                    >
                        {{ fmtMoney(total.cobranza_total) }}
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground tabular-nums">
                        Sano {{ fmtMoney(total.sano_cob) }} · Atraso y vencida
                        {{ fmtMoney(total.atraso_cob + total.vencido_cob) }}
                    </p>
                </div>
                <div class="rounded-2xl border bg-card/80 p-4 shadow-xs">
                    <p class="text-xs font-medium text-muted-foreground">
                        Mora
                    </p>
                    <p
                        class="mt-1 text-xl font-semibold tabular-nums md:text-2xl"
                        :class="colorMora(total.mora)"
                    >
                        {{ fmtMoney(total.mora) }}
                    </p>
                    <p
                        v-if="total.mora < 0"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        Negativa: se cobró más de lo que había por cobrar.
                    </p>
                </div>
            </div>
            <p
                v-else
                class="rounded-2xl border border-dashed p-4 text-sm text-muted-foreground"
            >
                Los archivos se procesaron correctamente, pero no hay cartera ni
                cobranza para este corte.
            </p>
        </div>

        <!-- Aviso de reporte preliminar -->
        <div
            v-if="preliminar"
            class="flex flex-col gap-3 border-t border-amber-200 bg-amber-50/80 px-5 py-3 text-sm text-amber-900 sm:flex-row sm:items-center sm:justify-between md:px-6 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200"
        >
            <p class="flex items-start gap-2">
                <ShieldAlert
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    <b class="font-semibold">Reporte preliminar.</b>
                    Revisar evidencias antes de comisionar.
                    <span
                        v-if="auditoria?.estado_texto"
                        class="text-amber-800/80 dark:text-amber-200/80"
                        >{{ auditoria.estado_texto }}</span
                    >
                </span>
            </p>
            <button
                type="button"
                class="inline-flex shrink-0 items-center gap-1.5 self-start rounded-full border border-amber-300 bg-white/70 px-3 py-1 text-xs font-semibold transition-colors hover:bg-white focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:outline-none sm:self-auto dark:border-amber-800 dark:bg-amber-950/60 dark:hover:bg-amber-950"
                @click="$emit('verControl')"
            >
                Ver control y trazabilidad
                <ArrowDown class="size-3.5" aria-hidden="true" />
            </button>
        </div>
    </article>
</template>
