<script setup lang="ts">
import {
    CalendarDays,
    CheckCircle2,
    Download,
    History,
    Search,
    ShieldCheck,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { download as downloadRoute } from '@/routes/efectividad-cobranza';
import { formatDateTime, formatMoney, formatNumber, formatPct } from './format';
import IncidenciasCalculo from './IncidenciasCalculo.vue';
import { isV4Coverage } from './types';
import type { ResultRow, RunDetail } from './types';

const props = defineProps<{ run: RunDetail }>();

const search = ref('');
const onlyTotals = ref(false);

const isTotal = (r: ResultRow) => !!r.es_total;
const isGrand = (r: ResultRow) =>
    r.tipo === 'gran_total' || r.promotor === 'GRAN TOTAL';

const granTotal = computed(() => props.run.rows.find(isGrand) ?? null);

/** Filas visibles + encabezado de oficina (como la hoja EFECTIVIDAD de la macro). */
const displayRows = computed(() => {
    const term = search.value.trim().toLocaleLowerCase('es-MX');
    const out: Array<
        { kind: 'office'; label: string } | { kind: 'row'; row: ResultRow }
    > = [];
    let office: string | null = null;

    for (const row of props.run.rows) {
        if (onlyTotals.value && !isTotal(row)) {
            continue;
        }

        if (
            term &&
            !isGrand(row) &&
            !`${row.promotor} ${row.oficina ?? ''}`
                .toLocaleLowerCase('es-MX')
                .includes(term)
        ) {
            continue;
        }

        const title = row.oficina_titulo ?? null;

        if (row.tipo === 'promotor' && title && title !== office) {
            out.push({ kind: 'office', label: title });
            office = title;
        }

        out.push({ kind: 'row', row });
    }

    return out;
});

const visibleCount = computed(
    () => displayRows.value.filter((r) => r.kind === 'row').length,
);

const vigencia = computed(() => {
    if (!props.run.is_current_engine) {
        return {
            label: 'Calculadora anterior — no comparable con el motor V4',
            icon: History,
            tone: 'text-amber-700 dark:text-amber-300',
        };
    }

    if (props.run.superseded_by_id) {
        return {
            label: `Versión previa — existe la ejecución #${props.run.superseded_by_id} más reciente`,
            icon: History,
            tone: 'text-muted-foreground',
        };
    }

    if (props.run.is_stale) {
        return {
            label: 'Obsoleto — cambiaron fotografías o ingresos después de generarlo',
            icon: History,
            tone: 'text-amber-700 dark:text-amber-300',
        };
    }

    return {
        label: 'Vigente — generado con los datos de origen actuales',
        icon: CheckCircle2,
        tone: 'text-emerald-700 dark:text-emerald-400',
    };
});

const kpis = computed(() => {
    const t = granTotal.value;

    if (!t) {
        return [];
    }

    return [
        { label: 'Sano por cobrar', value: formatMoney(t.sano_pc) },
        {
            label: 'Cobranza total',
            value: formatMoney(t.cobranza_total),
            hint: `Sano ${formatMoney(t.sano_cob)} · Atraso ${formatMoney(t.atraso_cob)} · Vencido ${formatMoney(t.vencido_cob)}`,
        },
        {
            label: '% Efectividad total',
            value: formatPct(t.pct_total),
            hint: `% Sano ${formatPct(t.sano_pct)}`,
        },
        {
            label: 'Mora',
            value: formatMoney(t.mora),
            hint: `Atraso por cobrar ${formatMoney(t.atraso_pc)} · Vencido por cobrar ${formatMoney(t.vencido_pc)}`,
        },
    ];
});

const v4Coverage = computed(() =>
    isV4Coverage(props.run.coverage) ? props.run.coverage : null,
);
const legacyCoverage = computed(() =>
    props.run.coverage && !isV4Coverage(props.run.coverage)
        ? props.run.coverage
        : null,
);

function dmy(ymd: string | null | undefined): string {
    return ymd ? ymd.slice(0, 10).split('-').reverse().join('/') : '—';
}

/** Semáforo de la columna L de la macro (solo si hay Sano por cobrar). */
function semaforo(row: ResultRow): string {
    if (!(row.sano_pc > 0)) {
        return '';
    }

    if (row.pct_total >= 0.95) {
        return 'bg-[#C6EFCE] text-[#006100] dark:bg-emerald-950/60 dark:text-emerald-300';
    }

    if (row.pct_total >= 0.9) {
        return 'bg-[#FFEB9C] text-[#9C6500] dark:bg-amber-950/60 dark:text-amber-300';
    }

    return 'bg-[#FFC7CE] text-[#9C0006] dark:bg-red-950/60 dark:text-red-300';
}

function moraTone(value: number): string {
    return value < 0
        ? 'text-[#008000] dark:text-emerald-400'
        : value > 0
          ? 'text-[#C00000] dark:text-red-400'
          : '';
}
</script>

<template>
    <section class="space-y-4" aria-label="Resultados">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
                <h2 class="text-lg font-semibold">Resultados</h2>
                <p
                    class="flex items-center gap-1.5 text-sm"
                    :class="vigencia.tone"
                >
                    <component
                        :is="vigencia.icon"
                        class="size-4 shrink-0"
                        aria-hidden="true"
                    />
                    {{ vigencia.label }} · {{ formatDateTime(run.finished_at) }}
                </p>
            </div>
            <Button v-if="run.has_excel" as-child>
                <a :href="downloadRoute(run.id).url"
                    ><Download /> Descargar Excel</a
                >
            </Button>
        </div>

        <!-- Parámetros de corte (columnas P-Q de la macro) -->
        <p
            v-if="run.parameters"
            class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border bg-muted/30 px-3 py-2 text-xs"
        >
            <CalendarDays
                class="size-4 text-muted-foreground"
                aria-hidden="true"
            />
            <span
                ><strong>{{ run.parameters.modo }}</strong></span
            >
            <span>Domingo base {{ dmy(run.parameters.domingo_base) }}</span>
            <span>Corte {{ dmy(run.parameters.fecha_corte) }}</span>
            <span
                >Ingresos {{ dmy(run.parameters.inicio_ingresos) }} a
                {{ dmy(run.parameters.fin_ingresos) }}</span
            >
            <span class="text-muted-foreground">{{ run.engine_label }}</span>
        </p>

        <div v-if="kpis.length" class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <Card v-for="kpi in kpis" :key="kpi.label" class="gap-1 py-4">
                <CardHeader class="px-4"
                    ><CardTitle
                        class="text-xs font-medium text-muted-foreground"
                        >{{ kpi.label }}</CardTitle
                    ></CardHeader
                >
                <CardContent class="px-4">
                    <p class="text-xl font-bold tabular-nums sm:text-2xl">
                        {{ kpi.value }}
                    </p>
                    <p
                        v-if="kpi.hint"
                        class="mt-1 text-xs text-muted-foreground"
                    >
                        {{ kpi.hint }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <!-- Cobertura real -->
        <Card v-if="v4Coverage" class="gap-2 py-4">
            <CardContent class="space-y-2 px-4">
                <p class="flex items-start gap-2 text-sm">
                    <ShieldCheck
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span
                        >{{ formatNumber(v4Coverage.filas_fotos) }} registros de
                        {{ v4Coverage.fotos.length }} fotografías diarias ·
                        {{ formatNumber(v4Coverage.ingresos_corte) }}
                        movimientos del corte ·
                        {{ formatNumber(v4Coverage.ingresos_historial) }}
                        movimientos evaluados del historial.</span
                    >
                </p>
                <ul class="flex flex-wrap gap-1.5 text-[11px]">
                    <li
                        v-for="f in v4Coverage.fotos"
                        :key="f.fecha"
                        class="rounded border px-1.5 py-0.5 tabular-nums"
                    >
                        {{ dmy(f.fecha) }}: {{ formatNumber(f.filas) }}
                    </li>
                </ul>
            </CardContent>
        </Card>
        <Card v-else-if="legacyCoverage" class="gap-2 py-4">
            <CardContent class="px-4 text-sm">
                <p class="flex items-start gap-2">
                    <ShieldCheck
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    {{ legacyCoverage.message }}
                </p>
            </CardContent>
        </Card>

        <Card class="gap-3">
            <CardHeader
                class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <CardTitle class="text-base"
                    >Detalle por oficina y promotor</CardTitle
                >
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <label class="flex items-center gap-2 text-sm">
                        <Switch v-model="onlyTotals" />
                        Solo totales
                    </label>
                    <div class="relative sm:w-64">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            v-model="search"
                            placeholder="Buscar promotor u oficina"
                            class="pl-8"
                            aria-label="Buscar promotor u oficina"
                        />
                    </div>
                </div>
            </CardHeader>
            <CardContent class="px-0 sm:px-6">
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[1180px] text-sm">
                        <thead>
                            <tr class="text-xs text-white">
                                <th
                                    class="sticky left-0 bg-[#123656] py-1.5 pr-3 pl-4 text-left sm:pl-2"
                                >
                                    Oficina
                                </th>
                                <th colspan="3" class="bg-[#46A88F] py-1.5">
                                    SANO
                                </th>
                                <th
                                    colspan="3"
                                    class="bg-[#FFE598] py-1.5 text-black"
                                >
                                    VIGENTES CON ATRASO
                                </th>
                                <th colspan="3" class="bg-[#C00000] py-1.5">
                                    VENCIDOS CON ATRASO
                                </th>
                                <th colspan="2" class="bg-[#185D7B] py-1.5">
                                    COBRANZA TOTAL
                                </th>
                                <th class="bg-[#123656] py-1.5">MORA</th>
                            </tr>
                            <tr
                                class="border-b bg-[#123656] text-[11px] text-white"
                            >
                                <th
                                    class="sticky left-0 bg-[#123656] py-1.5 pr-3 pl-4 text-left sm:pl-2"
                                >
                                    Promotor
                                </th>
                                <template v-for="g in 3" :key="g">
                                    <th class="px-2 py-1.5 text-right">
                                        Por cobrar
                                    </th>
                                    <th class="px-2 py-1.5 text-right">
                                        Cobrado
                                    </th>
                                    <th class="px-2 py-1.5 text-right">
                                        % Cobrado
                                    </th>
                                </template>
                                <th class="px-2 py-1.5 text-right">Cobrado</th>
                                <th class="px-2 py-1.5 text-right">
                                    % Cobrado
                                </th>
                                <th class="px-2 py-1.5"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template
                                v-for="(item, idx) in displayRows"
                                :key="idx"
                            >
                                <tr
                                    v-if="item.kind === 'office'"
                                    class="border-b bg-background"
                                >
                                    <td
                                        colspan="13"
                                        class="sticky left-0 py-1.5 pl-4 text-xs font-bold tracking-wide sm:pl-2"
                                    >
                                        {{ item.label }}
                                    </td>
                                </tr>
                                <tr
                                    v-else
                                    class="border-b tabular-nums last:border-0"
                                    :class="
                                        isGrand(item.row)
                                            ? 'border-t-2 border-t-[#123656] bg-primary/5 font-bold'
                                            : item.row.es_total
                                              ? 'bg-muted/50 font-semibold'
                                              : ''
                                    "
                                >
                                    <td
                                        class="sticky left-0 py-1.5 pr-3 pl-4 sm:pl-2"
                                        :class="
                                            isGrand(item.row)
                                                ? 'bg-[#123656] text-white'
                                                : 'bg-inherit'
                                        "
                                    >
                                        {{ item.row.promotor }}
                                    </td>
                                    <td class="px-2 py-1.5 text-right">
                                        {{ formatMoney(item.row.sano_pc) }}
                                    </td>
                                    <td class="px-2 py-1.5 text-right">
                                        {{ formatMoney(item.row.sano_cob) }}
                                    </td>
                                    <td class="px-2 py-1.5 text-right">
                                        {{ formatPct(item.row.sano_pct) }}
                                    </td>
                                    <td class="px-2 py-1.5 text-right">
                                        {{ formatMoney(item.row.atraso_pc) }}
                                    </td>
                                    <td class="px-2 py-1.5 text-right">
                                        {{ formatMoney(item.row.atraso_cob) }}
                                    </td>
                                    <td class="px-2 py-1.5 text-right">
                                        {{ formatPct(item.row.atraso_pct) }}
                                    </td>
                                    <td class="px-2 py-1.5 text-right">
                                        {{ formatMoney(item.row.vencido_pc) }}
                                    </td>
                                    <td class="px-2 py-1.5 text-right">
                                        {{ formatMoney(item.row.vencido_cob) }}
                                    </td>
                                    <td class="px-2 py-1.5 text-right">
                                        {{ formatPct(item.row.vencido_pct) }}
                                    </td>
                                    <td class="px-2 py-1.5 text-right">
                                        {{
                                            formatMoney(item.row.cobranza_total)
                                        }}
                                    </td>
                                    <td
                                        class="px-2 py-1.5 text-right font-semibold"
                                        :class="semaforo(item.row)"
                                    >
                                        {{ formatPct(item.row.pct_total) }}
                                    </td>
                                    <td
                                        class="px-2 py-1.5 text-right font-semibold"
                                        :class="moraTone(item.row.mora)"
                                    >
                                        {{ formatMoney(item.row.mora) }}
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!visibleCount">
                                <td
                                    colspan="13"
                                    class="py-6 text-center text-sm text-muted-foreground"
                                >
                                    Sin coincidencias para "{{ search }}".
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-3 px-4 text-xs text-muted-foreground sm:px-0">
                    Porcentajes calculados sobre el Sano por cobrar (la cobranza
                    de atraso y vencido subsidia al sano). Semáforo: ≥ 95%
                    verde, ≥ 90% amarillo, menor rojo.
                    <template v-if="run.reconciliation">
                        {{ run.reconciliation.summary }}</template
                    >
                </p>
            </CardContent>
        </Card>

        <IncidenciasCalculo
            v-if="run.is_current_engine"
            :run-id="run.id"
            :total="run.incidents_count"
            :summary="run.incidents_summary ?? []"
        />
    </section>
</template>
