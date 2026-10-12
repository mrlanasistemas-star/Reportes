<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import {
    AlertTriangle,
    CheckCircle2,
    Download,
    FileSpreadsheet,
    Loader2,
    TrendingUp,
} from 'lucide-vue-next'
import { computed, onBeforeUnmount, ref, watch } from 'vue'

import SelectField from '@/components/forms/SelectField.vue'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { download as downloadRoute, generate as generateRoute, index as efectividadIndex } from '@/routes/efectividad-cobranza'

type Row = {
    promotor: string
    oficina?: string | null
    sano_pc: number
    sano_cob: number
    sano_pct: number
    atraso_pc: number
    atraso_cob: number
    atraso_pct: number
    vencido_pc: number
    vencido_cob: number
    vencido_pct: number
    cobranza_total: number
    pct_total: number
    mora: number
    es_total?: boolean
}

type Coverage = {
    status: string
    message: string
    cartera_rows_total: number
    ingresos_rows_total: number
    ingresos_rows_whitelist_pass: number
    ingresos_rows_usables: number
}

type RunPayload = {
    id: number
    status: 'queued' | 'running' | 'success' | 'failed'
    log: string | null
    metadata: { progress_percent?: number; current_step?: string } | null
    coverage: Coverage | null
    rows: Row[]
    has_excel: boolean
    error_message: string | null
}

const props = defineProps<{
    periods: Array<{ id: number; label: string; code: string; type: string }>
    branches: Array<{ id: number; name: string }>
    selected: { period_id: number | null; branch_id: number | null }
    latestRun: RunPayload | null
    history: Array<{
        id: number
        status: string
        coverage: Coverage | null
        has_excel: boolean
        created_at: string | null
        finished_at: string | null
    }>
}>()

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Efectividad de Cobranza', href: efectividadIndex() }],
    },
})

const periodOptions = computed(() => props.periods.map((p) => ({ value: p.id, label: p.label })))
const branchOptions = computed(() => [
    { value: '', label: 'Todas las sucursales (general)' },
    ...props.branches.map((b) => ({ value: b.id, label: b.name })),
])

const selectedPeriodId = ref<number | string>(props.selected.period_id ?? '')
const selectedBranchId = ref<number | string>(props.selected.branch_id ?? '')

const run = ref<RunPayload | null>(props.latestRun)
const generating = ref(false)

watch(
    () => props.latestRun,
    (value) => {
        run.value = value
    },
)

function reloadForSelection() {
    router.get(
        efectividadIndex().url,
        {
            period_id: selectedPeriodId.value || undefined,
            branch_id: selectedBranchId.value || undefined,
        },
        { preserveState: true, preserveScroll: true },
    )
}

let pollTimer: ReturnType<typeof setInterval> | null = null

function stopPolling() {
    if (pollTimer) {
        clearInterval(pollTimer)
        pollTimer = null
    }
}

async function pollProgress(runId: number) {
    const res = await fetch(`/efectividad-cobranza/runs/${runId}/progreso`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    if (!res.ok) {
        return
    }
    const payload = (await res.json()) as RunPayload
    run.value = payload

    if (payload.status === 'success' || payload.status === 'failed') {
        stopPolling()
        generating.value = false
        router.reload({ only: ['history'] })
    }
}

function startPolling(runId: number) {
    stopPolling()
    pollTimer = setInterval(() => pollProgress(runId), 2500)
}

onBeforeUnmount(stopPolling)

async function generar() {
    if (!selectedPeriodId.value) {
        return
    }

    generating.value = true
    run.value = null

    const res = await fetch(generateRoute().url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({
            period_id: selectedPeriodId.value,
            branch_id: selectedBranchId.value || null,
        }),
    })

    if (!res.ok) {
        generating.value = false
        return
    }

    const { run_id } = (await res.json()) as { run_id: number; status: string }
    await pollProgress(run_id)
    startPolling(run_id)
}

const granTotal = computed(() => run.value?.rows?.find((r) => r.promotor === 'GRAN TOTAL') ?? null)

const isAvailable = computed(() => run.value?.status === 'success' && (run.value?.coverage?.status === 'ok' || run.value?.coverage?.status === 'sin_ingresos_usables'))

const progressPercent = computed(() => run.value?.metadata?.progress_percent ?? 0)
const currentStep = computed(() => run.value?.metadata?.current_step ?? '')

function formatMoney(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—'
    }
    return new Intl.NumberFormat('es-MX', { style: 'currency', currency: 'MXN', maximumFractionDigits: 0 }).format(value)
}

function formatPct(value: number | null | undefined): string {
    if (value === null || value === undefined) {
        return '—'
    }
    return new Intl.NumberFormat('es-MX', { style: 'percent', maximumFractionDigits: 1 }).format(value)
}

function statusBadgeVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    return { queued: 'secondary', running: 'secondary', success: 'default', failed: 'destructive' }[status] as never ?? 'outline'
}

function statusLabel(status: string): string {
    return { queued: 'En cola', running: 'Procesando', success: 'Listo', failed: 'Error' }[status] ?? status
}

function coverageAlertVariant(status: string | undefined): 'default' | 'destructive' {
    if (!status) {
        return 'default'
    }
    return ['bloqueado_sin_fechas', 'sin_cartera', 'sin_datos'].includes(status) ? 'destructive' : 'default'
}
</script>

<template>
    <Head title="Efectividad de Cobranza" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <div class="flex items-center gap-3">
            <TrendingUp class="size-7 text-primary" />
            <div>
                <h1 class="text-2xl font-bold tracking-tight">Efectividad de Cobranza — Aprobada</h1>
                <p class="text-sm text-muted-foreground">
                    Cálculo real sobre los archivos de Ingresos Cobranza y Saldos por Cliente ya importados en Reportería —
                    misma macro aprobada, nunca un segundo motor.
                </p>
            </div>
        </div>

        <Card>
            <CardHeader>
                <CardTitle class="text-base">Filtros</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-4 md:flex-row md:items-end">
                <div class="w-full md:w-72">
                    <SelectField v-model="selectedPeriodId" label="Periodo" :options="periodOptions" placeholder="Selecciona un periodo" @update:model-value="reloadForSelection" />
                </div>
                <div class="w-full md:w-72">
                    <SelectField v-model="selectedBranchId" label="Sucursal" :options="branchOptions" placeholder="Todas (general)" @update:model-value="reloadForSelection" />
                </div>
                <Button :disabled="!selectedPeriodId || generating" @click="generar">
                    <Loader2 v-if="generating" class="animate-spin" />
                    <TrendingUp v-else />
                    Generar reporte
                </Button>
            </CardContent>
        </Card>

        <Card v-if="run">
            <CardHeader class="flex flex-row items-center justify-between gap-3">
                <CardTitle class="text-base">Estado de la generación</CardTitle>
                <Badge :variant="statusBadgeVariant(run.status)">{{ statusLabel(run.status) }}</Badge>
            </CardHeader>
            <CardContent class="space-y-3">
                <div v-if="run.status === 'queued' || run.status === 'running'" class="space-y-2">
                    <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                        <div class="h-full bg-primary transition-all" :style="{ width: `${progressPercent}%` }" />
                    </div>
                    <p class="text-sm text-muted-foreground">{{ currentStep }} — {{ run.log }}</p>
                </div>

                <p v-if="run.status === 'failed'" class="text-sm font-medium text-destructive">
                    {{ run.error_message ?? run.log }}
                </p>

                <Alert v-if="run.coverage" :variant="coverageAlertVariant(run.coverage.status)">
                    <AlertTriangle v-if="coverageAlertVariant(run.coverage.status) === 'destructive'" />
                    <CheckCircle2 v-else />
                    <AlertTitle>Cobertura real de datos</AlertTitle>
                    <AlertDescription>
                        {{ run.coverage.message }}
                        <div class="mt-2 grid grid-cols-2 gap-2 text-xs sm:grid-cols-4">
                            <span>CARTERA: {{ run.coverage.cartera_rows_total }} filas</span>
                            <span>Ingresos: {{ run.coverage.ingresos_rows_total }} filas</span>
                            <span>Whitelist: {{ run.coverage.ingresos_rows_whitelist_pass }}</span>
                            <span>Utilizables: {{ run.coverage.ingresos_rows_usables }}</span>
                        </div>
                    </AlertDescription>
                </Alert>

                <div v-if="run.has_excel">
                    <a :href="downloadRoute(run.id).url">
                        <Button variant="outline">
                            <Download />
                            Descargar Excel
                        </Button>
                    </a>
                </div>
            </CardContent>
        </Card>

        <div v-if="isAvailable && granTotal" class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card>
                <CardHeader class="pb-2"><CardTitle class="text-sm text-muted-foreground">Sano por cobrar</CardTitle></CardHeader>
                <CardContent class="text-2xl font-bold">{{ formatMoney(granTotal.sano_pc) }}</CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2"><CardTitle class="text-sm text-muted-foreground">Total cobrado</CardTitle></CardHeader>
                <CardContent class="text-2xl font-bold">{{ formatMoney(granTotal.cobranza_total) }}</CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2"><CardTitle class="text-sm text-muted-foreground">% Efectividad total</CardTitle></CardHeader>
                <CardContent class="text-2xl font-bold">{{ formatPct(granTotal.pct_total) }}</CardContent>
            </Card>
            <Card>
                <CardHeader class="pb-2"><CardTitle class="text-sm text-muted-foreground">Mora</CardTitle></CardHeader>
                <CardContent class="text-2xl font-bold">{{ formatMoney(granTotal.mora) }}</CardContent>
            </Card>
        </div>

        <Card v-if="isAvailable && run?.rows?.length">
            <CardHeader><CardTitle class="text-base">Detalle por promotor / oficina</CardTitle></CardHeader>
            <CardContent class="overflow-x-auto">
                <table class="w-full min-w-[960px] text-sm">
                    <thead>
                        <tr class="border-b text-left text-xs uppercase text-muted-foreground">
                            <th class="py-2 pr-3">Promotor</th>
                            <th class="py-2 pr-3 text-right">Sano por cobrar</th>
                            <th class="py-2 pr-3 text-right">Sano cobrado</th>
                            <th class="py-2 pr-3 text-right">% Sano</th>
                            <th class="py-2 pr-3 text-right">Atraso cobrado</th>
                            <th class="py-2 pr-3 text-right">Vencido cobrado</th>
                            <th class="py-2 pr-3 text-right">Total cobrado</th>
                            <th class="py-2 pr-3 text-right">% Total</th>
                            <th class="py-2 pr-3 text-right">Mora</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="(row, idx) in run.rows"
                            :key="idx"
                            :class="[row.es_total ? 'font-bold bg-muted/50' : '', 'border-b last:border-0']"
                        >
                            <td class="py-2 pr-3">{{ row.promotor }}</td>
                            <td class="py-2 pr-3 text-right">{{ formatMoney(row.sano_pc) }}</td>
                            <td class="py-2 pr-3 text-right">{{ formatMoney(row.sano_cob) }}</td>
                            <td class="py-2 pr-3 text-right">{{ formatPct(row.sano_pct) }}</td>
                            <td class="py-2 pr-3 text-right">{{ formatMoney(row.atraso_cob) }}</td>
                            <td class="py-2 pr-3 text-right">{{ formatMoney(row.vencido_cob) }}</td>
                            <td class="py-2 pr-3 text-right">{{ formatMoney(row.cobranza_total) }}</td>
                            <td class="py-2 pr-3 text-right">{{ formatPct(row.pct_total) }}</td>
                            <td class="py-2 pr-3 text-right">{{ formatMoney(row.mora) }}</td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <Card v-if="history.length">
            <CardHeader><CardTitle class="text-base">Historial de generaciones (esta sucursal/periodo)</CardTitle></CardHeader>
            <CardContent>
                <ul class="divide-y">
                    <li v-for="item in history" :key="item.id" class="flex items-center justify-between gap-3 py-2 text-sm">
                        <div class="flex items-center gap-2">
                            <Badge :variant="statusBadgeVariant(item.status)">{{ statusLabel(item.status) }}</Badge>
                            <span class="text-muted-foreground">{{ item.created_at ? new Date(item.created_at).toLocaleString('es-MX') : '' }}</span>
                        </div>
                        <a v-if="item.has_excel" :href="downloadRoute(item.id).url">
                            <Button variant="ghost" size="sm">
                                <FileSpreadsheet />
                                Excel
                            </Button>
                        </a>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
