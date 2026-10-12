<script setup lang="ts">
import {
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
import type { RunDetail } from './types';

const props = defineProps<{ run: RunDetail }>();

const search = ref('');
const onlyTotals = ref(false);

const granTotal = computed(
    () => props.run.rows.find((r) => r.promotor === 'GRAN TOTAL') ?? null,
);

const filteredRows = computed(() => {
    const term = search.value.trim().toLocaleLowerCase('es-MX');

    return props.run.rows.filter((row) => {
        if (onlyTotals.value && !row.es_total) {
            return false;
        }

        if (!term || row.promotor === 'GRAN TOTAL') {
            return true;
        }

        return `${row.promotor} ${row.oficina ?? ''}`
            .toLocaleLowerCase('es-MX')
            .includes(term);
    });
});

const vigencia = computed(() => {
    if (props.run.superseded_by_id) {
        return {
            label: `Versión previa — existe la ejecución #${props.run.superseded_by_id} más reciente`,
            icon: History,
            tone: 'text-muted-foreground',
        };
    }

    if (props.run.is_stale) {
        return {
            label: 'Obsoleto — los datos de origen cambiaron después de generarlo',
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
            label: 'Total cobrado',
            value: formatMoney(t.cobranza_total),
            hint: `Sano ${formatMoney(t.sano_cob)} · Atraso ${formatMoney(t.atraso_cob)} · Vencido ${formatMoney(t.vencido_cob)}`,
        },
        {
            label: '% Efectividad total',
            value: formatPct(t.pct_total),
            hint: `% Sano ${formatPct(t.sano_pct)}`,
        },
        { label: 'Mora', value: formatMoney(t.mora) },
    ];
});
</script>

<template>
    <section class="space-y-4" aria-label="Resultados">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h2 class="text-lg font-semibold">Resultados</h2>
                <p
                    class="flex items-center gap-1.5 text-sm"
                    :class="vigencia.tone"
                >
                    <component
                        :is="vigencia.icon"
                        class="size-4"
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
        <Card v-if="run.coverage" class="gap-2 py-4">
            <CardContent
                class="flex flex-col gap-3 px-4 sm:flex-row sm:items-center sm:justify-between"
            >
                <p class="flex items-start gap-2 text-sm">
                    <ShieldCheck
                        class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span>{{ run.coverage.message }}</span>
                </p>
                <dl
                    class="grid shrink-0 grid-cols-3 gap-4 text-xs sm:text-right"
                >
                    <div>
                        <dt class="text-muted-foreground">Cartera</dt>
                        <dd class="font-semibold tabular-nums">
                            {{ formatNumber(run.coverage.cartera_rows_total) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Ingresos</dt>
                        <dd class="font-semibold tabular-nums">
                            {{ formatNumber(run.coverage.ingresos_rows_total) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-muted-foreground">Utilizables</dt>
                        <dd class="font-semibold tabular-nums">
                            {{
                                formatNumber(run.coverage.ingresos_rows_usables)
                            }}
                        </dd>
                    </div>
                </dl>
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
                    <table class="w-full min-w-[960px] text-sm">
                        <thead>
                            <tr
                                class="border-b text-left text-xs text-muted-foreground uppercase"
                            >
                                <th
                                    class="sticky left-0 bg-card py-2 pr-3 pl-4 sm:pl-0"
                                >
                                    Promotor
                                </th>
                                <th class="py-2 pr-3 text-right">
                                    Sano por cobrar
                                </th>
                                <th class="py-2 pr-3 text-right">
                                    Sano cobrado
                                </th>
                                <th class="py-2 pr-3 text-right">% Sano</th>
                                <th class="py-2 pr-3 text-right">
                                    Atraso cobrado
                                </th>
                                <th class="py-2 pr-3 text-right">
                                    Vencido cobrado
                                </th>
                                <th class="py-2 pr-3 text-right">
                                    Total cobrado
                                </th>
                                <th class="py-2 pr-3 text-right">% Total</th>
                                <th class="py-2 pr-4 text-right sm:pr-0">
                                    Mora
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(row, idx) in filteredRows"
                                :key="idx"
                                class="border-b last:border-0"
                                :class="
                                    row.promotor === 'GRAN TOTAL'
                                        ? 'bg-primary/5 font-bold'
                                        : row.es_total
                                          ? 'bg-muted/50 font-semibold'
                                          : ''
                                "
                            >
                                <td
                                    class="sticky left-0 bg-inherit py-2 pr-3 pl-4 sm:pl-0"
                                >
                                    {{ row.promotor }}
                                    <span
                                        v-if="!row.es_total && row.oficina"
                                        class="block text-xs font-normal text-muted-foreground"
                                        >{{ row.oficina }}</span
                                    >
                                </td>
                                <td class="py-2 pr-3 text-right tabular-nums">
                                    {{ formatMoney(row.sano_pc) }}
                                </td>
                                <td class="py-2 pr-3 text-right tabular-nums">
                                    {{ formatMoney(row.sano_cob) }}
                                </td>
                                <td class="py-2 pr-3 text-right tabular-nums">
                                    {{ formatPct(row.sano_pct) }}
                                </td>
                                <td class="py-2 pr-3 text-right tabular-nums">
                                    {{ formatMoney(row.atraso_cob) }}
                                </td>
                                <td class="py-2 pr-3 text-right tabular-nums">
                                    {{ formatMoney(row.vencido_cob) }}
                                </td>
                                <td class="py-2 pr-3 text-right tabular-nums">
                                    {{ formatMoney(row.cobranza_total) }}
                                </td>
                                <td class="py-2 pr-3 text-right tabular-nums">
                                    {{ formatPct(row.pct_total) }}
                                </td>
                                <td
                                    class="py-2 pr-4 text-right tabular-nums sm:pr-0"
                                >
                                    {{ formatMoney(row.mora) }}
                                </td>
                            </tr>
                            <tr v-if="!filteredRows.length">
                                <td
                                    colspan="9"
                                    class="py-6 text-center text-sm text-muted-foreground"
                                >
                                    Sin coincidencias para "{{ search }}".
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p
                    v-if="run.reconciliation"
                    class="mt-3 px-4 text-xs text-muted-foreground sm:px-0"
                >
                    {{ run.reconciliation.summary }}
                </p>
            </CardContent>
        </Card>
    </section>
</template>
