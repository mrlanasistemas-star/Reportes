<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Loader2, RotateCw, TrendingUp, XCircle } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import EfectividadResults from '@/components/efectividad/EfectividadResults.vue';
import {
    csrfToken,
    describeHttpFailure,
    formatTime,
    isActiveStatus,
} from '@/components/efectividad/format';
import RunDetailSheet from '@/components/efectividad/RunDetailSheet.vue';
import RunHistoryTable from '@/components/efectividad/RunHistoryTable.vue';
import RunStatusBadge from '@/components/efectividad/RunStatusBadge.vue';
import RunStatusCard from '@/components/efectividad/RunStatusCard.vue';
import type {
    HistoryFilters,
    RunDetail,
    RunSummary,
    StageInfo,
} from '@/components/efectividad/types';
import { toast } from '@/components/feedback/Toast';
import SelectField from '@/components/forms/SelectField.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useEfectividadRunPolling } from '@/composables/useEfectividadRunPolling';
import {
    generate as generateRoute,
    index as efectividadIndex,
} from '@/routes/efectividad-cobranza';

const props = defineProps<{
    periods: Array<{ id: number; label: string; code: string; type: string }>;
    branches: Array<{ id: number; name: string }>;
    selected: { period_id: number | null; branch_id: number | null };
    focusRun: RunDetail | null;
    activeRuns: RunSummary[];
    history: {
        data: RunSummary[];
        meta: {
            current_page: number;
            last_page: number;
            total: number;
            per_page: number;
        };
    };
    filters: HistoryFilters;
    canFilterByUser: boolean;
    requesters: Array<{ id: number; name: string }>;
    stageCatalog: StageInfo[];
    openDetail?: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Efectividad de Cobranza', href: efectividadIndex() },
        ],
    },
});

const periodOptions = computed(() =>
    props.periods.map((p) => ({ value: p.id, label: p.label })),
);
const branchOptions = computed(() => [
    { value: '', label: 'Todas las sucursales (general)' },
    ...props.branches.map((b) => ({ value: b.id, label: b.name })),
]);

const selectedPeriodId = ref<number | string>(props.selected.period_id ?? '');
const selectedBranchId = ref<number | string>(props.selected.branch_id ?? '');

// La ejecución enfocada SIEMPRE llega del servidor (BD): al volver al módulo,
// recargar o abrir el enlace del correo, se restaura sin depender del navegador.
const focus = ref<RunDetail | RunSummary | null>(props.focusRun);
const submitting = ref(false);
const errorMessage = ref<string | null>(null);

const detailOpen = ref(false);
const detailRunId = ref<number | null>(null);

watch(
    () => props.focusRun,
    (value) => {
        focus.value = value;
        trackFocus();
    },
);

watch(
    () => props.selected,
    (value) => {
        selectedPeriodId.value = value.period_id ?? '';
        selectedBranchId.value = value.branch_id ?? '';
    },
);

const polling = useEfectividadRunPolling({
    onUpdate: (detail) => {
        if (focus.value?.id === detail.id) {
            focus.value = detail;
        }
    },
    onFinished: (detail) => {
        const messages = {
            success: {
                icon: 'success',
                title: 'Tu reporte de Efectividad de Cobranza está listo.',
            },
            blocked: {
                icon: 'warning',
                title: 'No se pudo generar el reporte porque faltan datos obligatorios.',
            },
            failed: {
                icon: 'error',
                title: 'Se produjo un error durante la generación.',
            },
        } as const;
        const msg = messages[detail.status as keyof typeof messages];

        if (msg) {
            void toast.fire({ icon: msg.icon, title: msg.title });
        }

        router.reload({
            only: ['history', 'activeRuns', 'backgroundActivity'],
        });
    },
});

function trackFocus() {
    if (focus.value && isActiveStatus(focus.value.status)) {
        polling.start(focus.value.id);
    } else {
        polling.stop();
    }
}

// Otras ejecuciones activas del usuario (distintas a la enfocada) se refrescan
// desde la BD con una recarga parcial ligera mientras existan.
const otherActiveRuns = computed(() =>
    props.activeRuns.filter((r) => r.id !== focus.value?.id),
);
let othersTimer: ReturnType<typeof setInterval> | null = null;
watch(
    () => otherActiveRuns.value.length,
    (count) => {
        if (count > 0 && !othersTimer) {
            othersTimer = setInterval(() => {
                if (document.visibilityState === 'visible') {
                    router.reload({
                        only: ['activeRuns', 'history', 'backgroundActivity'],
                    });
                }
            }, 10000);
        } else if (count === 0 && othersTimer) {
            clearInterval(othersTimer);
            othersTimer = null;
        }
    },
    { immediate: true },
);

onMounted(() => {
    trackFocus();

    if (props.openDetail && props.focusRun) {
        openDetails(props.focusRun.id);
    }
});

onBeforeUnmount(() => {
    if (othersTimer) {
        clearInterval(othersTimer);
    }
});

function reloadForSelection() {
    errorMessage.value = null;
    router.get(
        efectividadIndex().url,
        {
            period_id: selectedPeriodId.value || undefined,
            branch_id: selectedBranchId.value || undefined,
        },
        { preserveState: true, preserveScroll: true },
    );
}

function openDetails(runId: number) {
    detailRunId.value = runId;
    detailOpen.value = true;
}

function followRun(run: RunSummary) {
    router.get(
        efectividadIndex().url,
        { period_id: run.period_id, branch_id: run.branch_id ?? undefined },
        { preserveScroll: true },
    );
}

async function generar() {
    if (!selectedPeriodId.value || submitting.value) {
        return;
    }

    submitting.value = true;
    errorMessage.value = null;
    let res: Response | null = null;

    try {
        res = await fetch(generateRoute().url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                period_id: selectedPeriodId.value,
                branch_id: selectedBranchId.value || null,
            }),
        });

        if (!res.ok) {
            // Incluye 503 (la cola no aceptó el trabajo): NUNCA se muestra la confirmación.
            errorMessage.value = await describeHttpFailure(res, null);
            router.reload({ only: ['history'] });

            return;
        }

        const payload = (await res.json()) as {
            run_id: number;
            reused: boolean;
            accepted: boolean;
            run: RunSummary;
        };

        if (!payload.accepted) {
            errorMessage.value =
                'El servidor no confirmó la recepción del trabajo. Intenta de nuevo.';

            return;
        }

        focus.value = payload.run;
        trackFocus();

        if (payload.reused) {
            void toast.fire({
                icon: 'info',
                title: `Ya había una generación en curso para este periodo y sucursal. Te mostramos su avance (#${payload.run_id}).`,
            });
        }

        router.reload({
            only: ['history', 'activeRuns', 'backgroundActivity'],
        });
    } catch (error) {
        errorMessage.value = await describeHttpFailure(res, error);
    } finally {
        submitting.value = false;
    }
}

const focusIsActive = computed(
    () => !!focus.value && isActiveStatus(focus.value.status),
);
const focusDetail = computed(() =>
    focus.value && 'rows' in focus.value ? focus.value : null,
);
const showResults = computed(
    () =>
        focusDetail.value?.status === 'success' &&
        focusDetail.value.rows.length > 0,
);
</script>

<template>
    <Head title="Efectividad de Cobranza" />

    <div class="flex flex-col gap-5 p-4 md:p-6">
        <!-- Encabezado -->
        <header class="flex items-start gap-3">
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
            >
                <TrendingUp class="size-5" aria-hidden="true" />
            </div>
            <div class="min-w-0">
                <h1 class="text-2xl font-bold tracking-tight">
                    Efectividad de Cobranza
                </h1>
                <p class="text-sm text-muted-foreground">
                    Genera y consulta reportes de recuperación por periodo,
                    sucursal y promotor.
                </p>
            </div>
        </header>

        <!-- Filtros + Generar: el botón nunca sale de la tarjeta -->
        <Card class="py-4">
            <CardContent
                class="grid grid-cols-1 items-end gap-3 px-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto]"
            >
                <SelectField
                    v-model="selectedPeriodId"
                    label="Periodo"
                    :options="periodOptions"
                    placeholder="Selecciona un periodo"
                    @update:model-value="reloadForSelection"
                />
                <SelectField
                    v-model="selectedBranchId"
                    label="Sucursal"
                    :options="branchOptions"
                    placeholder="Todas (general)"
                    @update:model-value="reloadForSelection"
                />
                <Button
                    class="w-full sm:col-span-2 lg:col-span-1 lg:w-auto"
                    :disabled="!selectedPeriodId || submitting || focusIsActive"
                    @click="generar"
                >
                    <Loader2 v-if="submitting" class="animate-spin" />
                    <TrendingUp v-else />
                    {{
                        focusIsActive
                            ? 'Generación en curso'
                            : 'Generar reporte'
                    }}
                </Button>
            </CardContent>
        </Card>

        <Alert v-if="errorMessage" variant="destructive">
            <XCircle />
            <AlertTitle>No se pudo iniciar la generación</AlertTitle>
            <AlertDescription
                class="flex flex-wrap items-center justify-between gap-3"
            >
                <span>{{ errorMessage }}</span>
                <Button variant="outline" size="sm" @click="generar">
                    <RotateCw />
                    Reintentar
                </Button>
            </AlertDescription>
        </Alert>

        <!-- Otras ejecuciones activas del usuario (restauradas desde la BD) -->
        <Card v-if="otherActiveRuns.length" class="gap-2 py-3">
            <CardContent class="space-y-2 px-4">
                <p
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Otras generaciones en segundo plano
                </p>
                <ul class="divide-y">
                    <li
                        v-for="run in otherActiveRuns"
                        :key="run.id"
                        class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-medium">
                                #{{ run.id }} · {{ run.period_label }} ·
                                {{ run.branch_label }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Solicitado {{ formatTime(run.queued_at) }}
                                <template v-if="run.current_stage">
                                    · Paso {{ run.current_stage.position }}/{{
                                        run.stage_count
                                    }}
                                    {{ run.current_stage.label }}</template
                                >
                            </p>
                        </div>
                        <div class="flex items-center gap-2">
                            <RunStatusBadge
                                :state="run.display_state"
                                :label="run.display_label"
                            />
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="followRun(run)"
                                >Seguir</Button
                            >
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>

        <!-- Estado de la generación enfocada -->
        <RunStatusCard
            v-if="focus"
            :run="focus"
            :catalog="stageCatalog"
            :refreshing="polling.refreshing.value"
            @details="openDetails(focus.id)"
            @refresh="polling.refreshNow(focus.id)"
            @retry="generar"
        />
        <Card v-else-if="selectedPeriodId" class="border-dashed py-6">
            <CardContent class="px-4 text-center text-sm text-muted-foreground">
                No hay reportes generados para este periodo y sucursal. Presiona
                <strong>Generar reporte</strong> para crear uno.
            </CardContent>
        </Card>

        <EfectividadResults
            v-if="showResults && focusDetail"
            :run="focusDetail"
        />

        <RunHistoryTable
            :history="history"
            :filters="filters"
            :periods="periods"
            :branches="branches"
            :requesters="requesters"
            :can-filter-by-user="canFilterByUser"
            :selected="selected"
            @view="openDetails"
        />

        <RunDetailSheet
            v-model:open="detailOpen"
            :run-id="detailRunId"
            :seed="focusDetail"
        />
    </div>
</template>
