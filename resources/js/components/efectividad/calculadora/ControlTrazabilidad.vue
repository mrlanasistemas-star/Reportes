<script setup lang="ts">
import { ChevronDown, ListChecks } from 'lucide-vue-next';
import { computed } from 'vue';
import EstadoReporteBadge from './EstadoReporteBadge.vue';
import type { Auditoria } from './types';
import { fmtMoney } from './types';

const props = defineProps<{
    auditoria: Auditoria;
    metodologia?: string | null;
    engineVersion?: string | null;
}>();

const abierto = defineModel<boolean>('abierto', { default: false });

type Renglon = {
    clave: string;
    titulo: string;
    detalle: string;
    valor: string;
    /** Cifra que conviene revisar (distinta de cero en un contador de riesgo). */
    revisar: boolean;
};

const n = (v: number | null | undefined) =>
    typeof v === 'number' && Number.isFinite(v)
        ? v.toLocaleString('es-MX')
        : '—';
const m = (v: number | null | undefined) =>
    typeof v === 'number' && Number.isFinite(v) ? fmtMoney(v) : '—';
const distinto = (v: number | null | undefined) =>
    typeof v === 'number' && Number.isFinite(v) && v !== 0;

const renglones = computed<Renglon[]>(() => {
    const a = props.auditoria;

    return [
        {
            clave: 'contratos_ajustados',
            titulo: 'Contratos ajustados',
            detalle: 'Contratos con algún ajuste aplicado durante el cálculo.',
            valor: n(a.contratos_ajustados),
            revisar: false,
        },
        {
            clave: 'cuotas_inferidas',
            titulo: 'Cuotas con meta inferida',
            detalle:
                'Estimación por saldo de cartera (sin cuota explícita en el archivo).',
            valor: n(a.cuotas_inferidas),
            revisar: distinto(a.cuotas_inferidas),
        },
        {
            clave: 'importe_inferido',
            titulo: 'Importe inferido',
            detalle: 'Monto total de las metas estimadas por saldo.',
            valor: m(a.importe_inferido),
            revisar: distinto(a.importe_inferido),
        },
        {
            clave: 'contratos_con_diferencia',
            titulo: 'Contratos con diferencias al cierre',
            detalle:
                'El saldo final no cuadra con la cartera inicial y los pagos.',
            valor: n(a.contratos_con_diferencia),
            revisar: distinto(a.contratos_con_diferencia),
        },
        {
            clave: 'diferencia_sin_asignar',
            titulo: 'Diferencia sin asignar',
            detalle:
                'Importe de esas diferencias que no se atribuyó a ningún bucket.',
            valor: m(a.diferencia_sin_asignar),
            revisar: distinto(a.diferencia_sin_asignar),
        },
        {
            clave: 'desapariciones_sin_soporte',
            titulo: 'Desapariciones sin soporte',
            detalle:
                'Contratos que salieron de cartera sin pago que lo respalde; no se asumen liquidados.',
            valor: n(a.desapariciones_sin_soporte),
            revisar: distinto(a.desapariciones_sin_soporte),
        },
        {
            clave: 'saldo_sin_soporte',
            titulo: 'Saldo sin soporte',
            detalle: 'Saldo de los contratos que desaparecieron sin evidencia.',
            valor: m(a.saldo_sin_soporte),
            revisar: distinto(a.saldo_sin_soporte),
        },
        {
            clave: 'cierres_refi_comprobados',
            titulo: 'Cierres de refinanciamiento comprobados',
            detalle:
                'Contratos cerrados por refinanciamiento con evidencia en los archivos.',
            valor: n(a.cierres_refi_comprobados),
            revisar: false,
        },
    ];
});

const pendientes = computed(
    () => renglones.value.filter((r) => r.revisar).length,
);

function alAlternar(e: Event) {
    abierto.value = (e.target as HTMLDetailsElement).open;
}
</script>

<template>
    <details
        class="group rounded-2xl border bg-card shadow-sm transition-shadow open:shadow-md"
        :open="abierto"
        @toggle="alAlternar"
    >
        <summary
            class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-3 rounded-2xl px-5 py-4 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none [&::-webkit-details-marker]:hidden"
        >
            <span class="flex items-center gap-3">
                <span
                    class="grid size-9 place-items-center rounded-xl bg-muted text-muted-foreground"
                    aria-hidden="true"
                >
                    <ListChecks class="size-4.5" />
                </span>
                <span>
                    <span class="block font-semibold"
                        >Control y trazabilidad</span
                    >
                    <span class="block text-xs text-muted-foreground">
                        {{
                            auditoria.estado === 'PRELIMINAR'
                                ? 'Preliminar: revisar evidencias antes de comisionar.'
                                : 'Reporte conciliado con las evidencias cargadas.'
                        }}
                        <template v-if="pendientes">
                            · {{ pendientes }}
                            {{
                                pendientes === 1
                                    ? 'indicador por revisar'
                                    : 'indicadores por revisar'
                            }}</template
                        >
                    </span>
                </span>
            </span>
            <span class="flex items-center gap-2">
                <EstadoReporteBadge :auditoria="auditoria" />
                <ChevronDown
                    class="size-4 text-muted-foreground transition-transform duration-200 group-open:rotate-180"
                    aria-hidden="true"
                />
            </span>
        </summary>

        <div class="space-y-4 border-t px-5 py-4">
            <p
                v-if="auditoria.estado_texto"
                class="rounded-xl bg-muted/50 px-3 py-2 text-sm text-muted-foreground"
            >
                {{ auditoria.estado_texto }}
            </p>
            <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="r in renglones"
                    :key="r.clave"
                    class="rounded-xl border p-3 transition-colors"
                    :class="
                        r.revisar
                            ? 'border-amber-200 bg-amber-50/60 dark:border-amber-900/60 dark:bg-amber-950/20'
                            : ''
                    "
                >
                    <dt class="text-sm font-medium">{{ r.titulo }}</dt>
                    <dd
                        class="mt-1 text-lg font-semibold tabular-nums"
                        :class="
                            r.revisar
                                ? 'text-amber-800 dark:text-amber-300'
                                : ''
                        "
                    >
                        {{ r.valor }}
                    </dd>
                    <dd class="mt-0.5 text-xs text-muted-foreground">
                        {{ r.detalle }}
                    </dd>
                </div>
            </dl>
            <p
                v-if="metodologia || engineVersion"
                class="text-xs text-muted-foreground"
            >
                <template v-if="metodologia"
                    >Metodología {{ metodologia }}</template
                >
                <template v-if="metodologia && engineVersion"> · </template>
                <template v-if="engineVersion"
                    >Versión del motor {{ engineVersion }}</template
                >
            </p>
        </div>
    </details>
</template>
