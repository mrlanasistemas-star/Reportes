<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Loader2, RotateCw, TrendingUp, XCircle } from 'lucide-vue-next';
import Swal from 'sweetalert2';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import DisponibilidadInformacion from '@/components/efectividad/DisponibilidadInformacion.vue';
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
    Availability,
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
import { cancel as cancelRoute } from '@/routes/efectividad-cobranza/runs';

type Mode = 'SEMANAL' | 'DIARIO';

const props = defineProps<{
    periods: Array<{
        id: number;
        label: string;
        code: string;
        type: string;
        start_date: string | null;
        end_date: string | null;
    }>;
    branches: Array<{ id: number; name: string }>;
    selected: {
        period_id: number | null;
        branch_id: number | null;
        mode: Mode;
        cutoff_date: string | null;
    };
    engineVersion: string;
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
    stageCatalogPeriodica: StageInfo[];
    openDetail?: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Efectividad de Cobranza', href: efectividadIndex() },
        ],
    },
});

function dm(ymd: string | null): string {
    return ymd ? ymd.slice(5, 10).split('-').reverse().join('/') : '';
}

// Una semana operativa (lunes-domingo) usa el motor V4 (con fotografías); cualquier
// otro periodo (p.ej. el ciclo real de 4 semanas) usa el motor Aprobada/Periódica —
// el mismo Saldos por Cliente + Ingresos Cobranza que ya subes por Histórico General.
const periodOptions = computed(() =>
    props.periods.map((p) => ({
        value: p.id,
        label:
            p.type === 'weekly'
                ? `${p.label} (${dm(p.start_date)} – ${dm(p.end_date)}) · Motor V4`
                : `${p.label} (${dm(p.start_date)} – ${dm(p.end_date)}) · Aprobada`,
    })),
);
const branchOptions = computed(() => [
    { value: '', label: 'Todas las sucursales (general)' },
    ...props.branches.map((b) => ({ value: b.id, label: b.name })),
]);
const modeOptions = [
    { value: 'SEMANAL', label: 'Semanal (lunes a domingo)' },
    { value: 'DIARIO', label: 'Diario (un día de la semana)' },
];

const selectedPeriodId = ref<number | string>(props.selected.period_id ?? '');
const selectedBranchId = ref<number | string>(props.selected.branch_id ?? '');
const selectedMode = ref<string | number>(props.selected.mode ?? 'SEMANAL');
const selectedCutoff = ref<string | number>(props.selected.cutoff_date ?? '');
const availability = ref<Availability | null>(null);

const selectedPeriod = computed(
    () =>
        props.periods.find((p) => p.id === Number(selectedPeriodId.value)) ??
        null,
);
const isWeeklySelected = computed(
    () => selectedPeriod.value?.type === 'weekly',
);

/** Días lunes-domingo de la semana seleccionada (fecha de corte en modo DIARIO). */
const cutoffOptions = computed(() => {
    const p = selectedPeriod.value;

    if (!p || p.type !== 'weekly' || !p.start_date) {
        return [];
    }

    const dias = [
        'Lunes',
        'Martes',
        'Miércoles',
        'Jueves',
        'Viernes',
        'Sábado',
        'Domingo',
    ];
    const base = new Date(`${p.start_date.slice(0, 10)}T00:00:00Z`).getTime();

    return dias.map((dia, i) => {
        const ymd = new Date(base + i * 86400000).toISOString().slice(0, 10);

        return {
            value: ymd,
            label: `${dia} ${ymd.split('-').reverse().join('/')}`,
        };
    });
});

const cutoffForAvailability = computed(() =>
    selectedMode.value === 'DIARIO' && selectedCutoff.value
        ? String(selectedCutoff.value)
        : null,
);

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
        selectedMode.value = value.mode ?? 'SEMANAL';
        selectedCutoff.value = value.cutoff_date ?? '';
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
            cancelled: {
                icon: 'info',
                title: 'La generación fue cancelada.',
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

    if (!isWeeklySelected.value) {
        selectedMode.value = 'SEMANAL';
    }

    if (
        selectedCutoff.value &&
        !cutoffOptions.value.some((o) => o.value === selectedCutoff.value)
    ) {
        selectedCutoff.value = '';
    }

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

async function generar(confirmPartial = false) {
    if (!selectedPeriodId.value || submitting.value) {
        return;
    }

    if (selectedMode.value === 'DIARIO' && !selectedCutoff.value) {
        errorMessage.value = 'En modo diario selecciona la fecha de corte.';

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
                mode: selectedMode.value,
                cutoff_date:
                    selectedMode.value === 'DIARIO'
                        ? selectedCutoff.value
                        : null,
                confirm_partial: confirmPartial,
            }),
        });

        if (res.status === 409) {
            const body = await res
                .clone()
                .json()
                .catch(() => null);

            if (body?.requires_confirmation) {
                // Mismo control que la macro: confirmar que Ingresos trae la semana completa.
                const answer = await Swal.fire({
                    icon: 'warning',
                    title: 'Confirma la semana de Ingresos',
                    text: `${body.message} ¿Confirmas que el archivo cargado incluye la semana completa (o que no hubo movimientos)?`,
                    showCancelButton: true,
                    confirmButtonText: 'Sí, generar',
                    cancelButtonText: 'No, revisar archivos',
                });
                submitting.value = false;

                if (answer.isConfirmed) {
                    await generar(true);
                }

                return;
            }
        }

        if (res.status === 422) {
            const body = await res.json().catch(() => null);
            errorMessage.value =
                body?.error ??
                body?.message ??
                'Los parámetros de generación no son válidos.';

            return;
        }

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

const focusStageCatalog = computed(() =>
    focus.value?.is_periodica_engine
        ? props.stageCatalogPeriodica
        : props.stageCatalog,
);

const focusIsActive = computed(
    () => !!focus.value && isActiveStatus(focus.value.status),
);

const cancelling = ref(false);

async function cancelarGeneracion() {
    if (!focus.value || cancelling.value) {
        return;
    }

    const runId = focus.value.id;

    const answer = await Swal.fire({
        icon: 'warning',
        title: '¿Cancelar la generación?',
        text: 'El sistema detendrá el proceso en cuanto alcance un punto seguro. Esto no se puede deshacer y tendrás que generar el reporte de nuevo si lo necesitas.',
        showCancelButton: true,
        confirmButtonText: 'Sí, cancelar generación',
        cancelButtonText: 'Seguir esperando',
        confirmButtonColor: '#dc2626',
    });

    if (!answer.isConfirmed) {
        return;
    }

    cancelling.value = true;

    let res: Response | null = null;

    try {
        res = await fetch(cancelRoute(runId).url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
        });

        if (!res.ok) {
            void toast.fire({
                icon: 'error',
                title: await describeHttpFailure(res, null),
            });

            return;
        }

        const payload = (await res.json()) as {
            run: RunSummary;
            message: string;
        };

        if (focus.value?.id === runId) {
            focus.value = { ...focus.value, ...payload.run };
        }

        void toast.fire({ icon: 'info', title: payload.message });
        router.reload({ only: ['history', 'activeRuns', 'backgroundActivity'] });
    } catch (error) {
        void toast.fire({
            icon: 'error',
            title: await describeHttpFailure(res, error),
        });
    } finally {
        cancelling.value = false;
    }
}
const focusDetail = computed(() =>
    focus.value && 'rows' in focus.value ? focus.value : null,
);
const showResults = computed(
    () =>
        focusDetail.value?.status === 'success' &&
        focusDetail.value.rows.length > 0,
);
const sourcesBlocked = computed(
    () =>
        !!availability.value &&
        availability.value.supported &&
        availability.value.bloqueante,
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
                    Calculadora V4 MR LANA: fotografías diarias de cartera +
                    Ingresos Cobranza, por oficina y promotor.
                </p>
            </div>
        </header>

        <!-- Filtros + Generar: el botón nunca sale de la tarjeta -->
        <Card class="py-4">
            <CardContent
                class="grid grid-cols-1 items-end gap-3 px-4 sm:grid-cols-2 xl:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)_auto]"
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
                <template v-if="isWeeklySelected">
                    <SelectField
                        v-model="selectedMode"
                        label="Modo"
                        :options="modeOptions"
                    />
                    <SelectField
                        v-model="selectedCutoff"
                        label="Fecha de corte"
                        :options="
                            selectedMode === 'DIARIO'
                                ? cutoffOptions
                                : [
                                      {
                                          value: '',
                                          label: 'Domingo de la semana',
                                      },
                                  ]
                        "
                        placeholder="Selecciona el día"
                        :disabled="selectedMode !== 'DIARIO'"
                    />
                </template>
                <Button
                    class="w-full sm:col-span-2 xl:col-span-1 xl:w-auto"
                    :disabled="!selectedPeriodId || submitting || focusIsActive"
                    @click="generar()"
                >
                    <Loader2 v-if="submitting" class="animate-spin" />
                    <TrendingUp v-else />
                    {{
                        focusIsActive
                            ? 'Generación en curso'
                            : 'Generar reporte'
                    }}
                </Button>
                <p
                    v-if="isWeeklySelected && sourcesBlocked"
                    class="text-xs text-amber-700 sm:col-span-2 xl:col-span-5 dark:text-amber-300"
                >
                    Faltan fuentes indispensables (ver «Disponibilidad de
                    información»). Si generas ahora, el reporte quedará
                    bloqueado con el detalle de lo que falta; nunca se calcula
                    con fotografías incompletas.
                </p>
                <p
                    v-else-if="selectedPeriodId && !isWeeklySelected"
                    class="text-xs text-muted-foreground sm:col-span-2 xl:col-span-3"
                >
                    Periodo de 4 semanas: se calcula con la metodología
                    Aprobada, usando el Saldos por Cliente y los Ingresos
                    Cobranza que ya subiste por Histórico General — sin
                    fotografías diarias.
                </p>
            </CardContent>
        </Card>

        <Alert v-if="errorMessage" variant="destructive">
            <XCircle />
            <AlertTitle>No se pudo iniciar la generación</AlertTitle>
            <AlertDescription
                class="flex flex-wrap items-center justify-between gap-3"
            >
                <span>{{ errorMessage }}</span>
                <Button variant="outline" size="sm" @click="generar()">
                    <RotateCw />
                    Reintentar
                </Button>
            </AlertDescription>
        </Alert>

        <DisponibilidadInformacion
            v-if="isWeeklySelected"
            :period-id="selectedPeriodId || null"
            :branch-id="selectedBranchId || null"
            :mode="selectedMode === 'DIARIO' ? 'DIARIO' : 'SEMANAL'"
            :cutoff-date="cutoffForAvailability"
            @update="availability = $event"
        />

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
            :catalog="focusStageCatalog"
            :refreshing="polling.refreshing.value"
            @details="openDetails(focus.id)"
            @refresh="polling.refreshNow(focus.id)"
            @retry="generar()"
            @cancel="cancelarGeneracion()"
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
