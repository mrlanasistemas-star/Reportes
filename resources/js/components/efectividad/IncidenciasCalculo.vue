<script setup lang="ts">
import { Download, Search, ShieldAlert } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import SelectField from '@/components/forms/SelectField.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { incidents as incidentsRoute } from '@/routes/efectividad-cobranza';
import { exportMethod as exportIncidentsRoute } from '@/routes/efectividad-cobranza/incidents';
import { formatMoney, formatNumber } from './format';
import type { IncidentRow, IncidentSummary } from './types';

const props = defineProps<{
    runId: number;
    total: number | null;
    summary: IncidentSummary[];
}>();

const code = ref<string | number>('');
const search = ref('');
const page = ref(1);
const rows = ref<IncidentRow[]>([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 });
const loading = ref(false);
const failed = ref(false);
const expanded = ref<number | null>(null);

const codeOptions = computed(() => [
    { value: '', label: 'Todos los códigos' },
    ...props.summary.map((s) => ({
        value: s.codigo,
        label: `${s.codigo} · ${s.etiqueta} (${s.cantidad})`,
    })),
]);

const query = computed(() => ({
    code: code.value ? String(code.value) : undefined,
    q: search.value.trim() || undefined,
}));

async function load() {
    loading.value = true;
    failed.value = false;

    try {
        const res = await fetch(
            incidentsRoute(props.runId, {
                query: { ...query.value, page: String(page.value) },
            }).url,
            { headers: { Accept: 'application/json' } },
        );

        if (!res.ok) {
            throw new Error(String(res.status));
        }

        const payload = await res.json();
        rows.value = payload.data;
        meta.value = payload.meta;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

let debounce: ReturnType<typeof setTimeout> | null = null;
watch([code, search], () => {
    if (debounce) {
        clearTimeout(debounce);
    }

    debounce = setTimeout(() => {
        page.value = 1;
        void load();
    }, 300);
});
watch(page, () => void load());
watch(
    () => props.runId,
    () => {
        page.value = 1;
        void load();
    },
    { immediate: true },
);

const exportUrl = computed(
    () => exportIncidentsRoute(props.runId, { query: query.value }).url,
);

function fmtDate(ymd: string | null): string {
    return ymd ? ymd.slice(0, 10).split('-').reverse().join('/') : '—';
}
</script>

<template>
    <Card class="gap-3">
        <CardHeader
            class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="min-w-0">
                <CardTitle class="flex items-center gap-2 text-base">
                    <ShieldAlert
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    Incidencias de cálculo
                    <span class="font-normal text-muted-foreground"
                        >({{ formatNumber(total ?? 0) }})</span
                    >
                </CardTitle>
                <CardDescription>
                    Movimientos que la calculadora no pudo clasificar con
                    certeza (bitácora _AUDITORIA_EFECTIVIDAD). Se excluyen del
                    cálculo; nunca se fuerzan para eliminar la incidencia.
                </CardDescription>
            </div>
            <Button
                v-if="total"
                as-child
                variant="outline"
                size="sm"
                class="shrink-0"
            >
                <a :href="exportUrl"><Download /> Exportar CSV</a>
            </Button>
        </CardHeader>

        <CardContent class="space-y-3 px-0 sm:px-6">
            <p v-if="!total" class="px-4 text-sm text-muted-foreground sm:px-0">
                Sin incidencias de cálculo en esta ejecución.
            </p>

            <template v-else>
                <ul
                    v-if="summary.length"
                    class="flex flex-wrap gap-2 px-4 text-xs sm:px-0"
                >
                    <li
                        v-for="s in summary"
                        :key="s.codigo"
                        class="rounded-md border bg-muted/40 px-2 py-1"
                    >
                        <strong>{{ s.codigo }}</strong> {{ s.etiqueta }}:
                        {{ formatNumber(s.cantidad) }}
                        <template v-if="s.importe"
                            >· {{ formatMoney(s.importe) }}</template
                        >
                        ·
                        <span class="text-muted-foreground">{{
                            s.tratamiento
                        }}</span>
                    </li>
                </ul>

                <div class="grid grid-cols-1 gap-2 px-4 sm:grid-cols-2 sm:px-0">
                    <SelectField
                        v-model="code"
                        :options="codeOptions"
                        placeholder="Todos los códigos"
                    />
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            v-model="search"
                            class="pl-8"
                            placeholder="Buscar contrato"
                            aria-label="Buscar contrato"
                        />
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead>
                            <tr
                                class="border-b text-left text-xs text-muted-foreground uppercase"
                            >
                                <th class="py-2 pr-3 pl-4 sm:pl-0">Código</th>
                                <th class="py-2 pr-3">Contrato</th>
                                <th class="py-2 pr-3">Fecha</th>
                                <th class="py-2 pr-3">Motivo</th>
                                <th class="py-2 pr-3">Estado</th>
                                <th class="py-2 pr-4 text-right sm:pr-0">
                                    Detalle
                                </th>
                            </tr>
                        </thead>
                        <tbody :class="{ 'opacity-60': loading }">
                            <template v-for="r in rows" :key="r.id">
                                <tr class="border-b align-top last:border-0">
                                    <td
                                        class="py-2 pr-3 pl-4 font-mono text-xs sm:pl-0"
                                    >
                                        {{ r.code }}
                                    </td>
                                    <td class="py-2 pr-3 font-mono text-xs">
                                        {{ r.contract ?? '—' }}
                                    </td>
                                    <td class="py-2 pr-3 text-xs tabular-nums">
                                        {{ fmtDate(r.date) }}
                                    </td>
                                    <td class="py-2 pr-3">
                                        {{ r.description }}
                                    </td>
                                    <td class="py-2 pr-3 text-xs">
                                        {{ r.status }}
                                    </td>
                                    <td class="py-2 pr-4 text-right sm:pr-0">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            @click="
                                                expanded =
                                                    expanded === r.id
                                                        ? null
                                                        : r.id
                                            "
                                            >{{
                                                expanded === r.id
                                                    ? 'Ocultar'
                                                    : 'Ver'
                                            }}</Button
                                        >
                                    </td>
                                </tr>
                                <tr
                                    v-if="expanded === r.id"
                                    class="border-b bg-muted/30"
                                >
                                    <td colspan="6" class="px-4 py-2 text-xs">
                                        <p>
                                            <strong>ID:</strong>
                                            <span class="font-mono break-all">{{
                                                r.key
                                            }}</span>
                                        </p>
                                        <p v-if="r.reference">
                                            <strong>Movimiento:</strong>
                                            {{ r.reference }}
                                        </p>
                                        <p v-if="r.amount !== null">
                                            <strong>Importe:</strong>
                                            {{ formatMoney(r.amount) }}
                                        </p>
                                        <p>
                                            <strong>Acción recomendada:</strong>
                                            {{ r.action }}
                                        </p>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="!rows.length && !loading">
                                <td
                                    colspan="6"
                                    class="py-6 text-center text-sm text-muted-foreground"
                                >
                                    {{
                                        failed
                                            ? 'No se pudieron cargar las incidencias.'
                                            : 'Sin coincidencias.'
                                    }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div
                    v-if="meta.last_page > 1"
                    class="flex items-center justify-between gap-2 px-4 text-sm sm:px-0"
                >
                    <span class="text-muted-foreground"
                        >Página {{ meta.current_page }} de
                        {{ meta.last_page }}</span
                    >
                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="page <= 1 || loading"
                            @click="page--"
                            >Anterior</Button
                        >
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="page >= meta.last_page || loading"
                            @click="page++"
                            >Siguiente</Button
                        >
                    </div>
                </div>
            </template>
        </CardContent>
    </Card>
</template>
