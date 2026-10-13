<script setup lang="ts">
import { useNow } from '@vueuse/core';
import {
    Ban,
    Download,
    Info,
    ListChecks,
    OctagonX,
    RefreshCw,
    RotateCw,
    ServerCog,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { download as downloadRoute } from '@/routes/efectividad-cobranza';
import {
    FAILURE_KIND_LABELS,
    formatDateTime,
    formatDuration,
    formatNumber,
    formatTime,
    secondsSince,
} from './format';
import RunIssuesList from './RunIssuesList.vue';
import RunStatusBadge from './RunStatusBadge.vue';
import StageStepper from './StageStepper.vue';
import type { RunDetail, RunSummary, StageInfo } from './types';

const props = defineProps<{
    run: RunSummary | RunDetail;
    catalog: StageInfo[];
    refreshing?: boolean;
}>();

const emit = defineEmits<{
    (e: 'details'): void;
    (e: 'refresh'): void;
    (e: 'retry'): void;
    (e: 'cancel'): void;
}>();

const now = useNow({ interval: 1000 });

const isActive = computed(() => props.run.is_active);
const elapsed = computed(() => {
    if (!props.run.started_at) {
        return null;
    }

    return props.run.finished_at
        ? props.run.duration_seconds
        : secondsSince(props.run.started_at, now.value.getTime());
});
const waiting = computed(() =>
    props.run.status === 'queued'
        ? secondsSince(props.run.queued_at, now.value.getTime())
        : null,
);

const criticalIssues = computed(() =>
    'issues' in props.run
        ? props.run.issues.filter((i) => i.severity === 'critical')
        : [],
);

const stageLine = computed(() => {
    const stage = props.run.current_stage;

    if (!stage) {
        return props.run.status === 'queued'
            ? 'Esperando a que el servidor tome el trabajo.'
            : null;
    }

    return `Paso ${stage.position} de ${props.run.stage_count} · ${stage.label}`;
});

const showHealthAlert = computed(
    () =>
        ['delayed', 'stalled'].includes(props.run.display_state) &&
        !!props.run.health_message,
);

const isCancelling = computed(() => props.run.display_state === 'cancelling');
</script>

<template>
    <Card class="gap-0 overflow-hidden py-0">
        <CardContent class="space-y-4 p-4 sm:p-5">
            <!-- Encabezado: reporte, identificador, estado -->
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        {{ run.engine_label }} · Ejecución #{{ run.id }}
                    </p>
                    <h2 class="truncate text-base font-semibold sm:text-lg">
                        {{ run.period_label ?? 'Periodo' }}
                        <span class="font-normal text-muted-foreground"
                            >· {{ run.branch_label }}</span
                        >
                    </h2>
                </div>
                <RunStatusBadge
                    :state="run.display_state"
                    :label="run.display_label"
                />
            </div>

            <!-- Aviso de segundo plano (solo cuando el servidor YA aceptó el trabajo) -->
            <Alert v-if="isActive" class="border-primary/30 bg-primary/5">
                <ServerCog />
                <AlertTitle
                    >Tu reporte se está preparando en segundo plano.</AlertTitle
                >
                <AlertDescription>
                    Puedes continuar utilizando Reportes, entrar a OKR, revisar
                    otras páginas o cerrar esta ventana. El procesamiento
                    continuará en el servidor. Te notificaremos cuando esté
                    listo.
                </AlertDescription>
            </Alert>

            <!-- Etapa y progreso real -->
            <div v-if="isActive || run.current_stage" class="space-y-2.5">
                <StageStepper
                    :catalog="catalog"
                    :current="run.current_stage"
                    :status="run.status"
                />
                <div
                    class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1"
                >
                    <p class="text-sm font-medium">{{ stageLine }}</p>
                    <p
                        v-if="run.progress.determinate"
                        class="text-sm text-muted-foreground tabular-nums"
                    >
                        {{ run.progress.percent }}% ·
                        {{ formatNumber(run.progress.current) }} de
                        {{ formatNumber(run.progress.total) }} registros
                    </p>
                </div>
                <Progress
                    v-if="isActive"
                    :model-value="
                        run.progress.determinate ? run.progress.percent : null
                    "
                    :aria-label="stageLine ?? 'Progreso'"
                />
                <p
                    v-if="isActive && run.current_stage"
                    class="text-sm text-muted-foreground"
                >
                    {{ run.current_stage.description }}
                </p>
                <p
                    v-if="run.status === 'retrying' && run.log"
                    class="text-sm text-violet-700 dark:text-violet-300"
                >
                    {{ run.log }}
                </p>
            </div>

            <!-- Cancelación solicitada: el Job sigue vivo hasta su siguiente punto seguro -->
            <Alert
                v-if="isCancelling"
                class="border-slate-300 bg-slate-50 text-slate-900 dark:border-slate-800 dark:bg-slate-950/40 dark:text-slate-100"
            >
                <OctagonX />
                <AlertTitle>Cancelación solicitada</AlertTitle>
                <AlertDescription class="text-slate-900/90 dark:text-slate-100/90">
                    Se solicitó detener la generación. El sistema la
                    finalizará en cuanto alcance un punto seguro — esto puede
                    tardar unos momentos si está en medio de un cálculo.
                </AlertDescription>
            </Alert>

            <!-- Diagnóstico de salud del worker -->
            <Alert
                v-if="showHealthAlert"
                class="border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
            >
                <Info />
                <AlertTitle>{{ run.display_label }}</AlertTitle>
                <AlertDescription
                    class="text-amber-900/90 dark:text-amber-100/90"
                    >{{ run.health_message }}</AlertDescription
                >
            </Alert>

            <!-- Datos verificables -->
            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-muted-foreground">Solicitado</dt>
                    <dd class="font-medium">
                        {{ formatDateTime(run.queued_at) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Inicio</dt>
                    <dd class="font-medium">
                        {{
                            run.started_at
                                ? formatTime(run.started_at)
                                : 'Aún no inicia'
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        {{
                            run.finished_at
                                ? 'Duración'
                                : run.status === 'queued'
                                  ? 'En espera'
                                  : 'Tiempo transcurrido'
                        }}
                    </dt>
                    <dd class="font-medium tabular-nums">
                        {{
                            formatDuration(
                                run.status === 'queued' ? waiting : elapsed,
                            )
                        }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        {{ run.finished_at ? 'Finalizado' : 'Intentos' }}
                    </dt>
                    <dd class="font-medium">
                        {{
                            run.finished_at
                                ? formatTime(run.finished_at)
                                : run.attempts || '—'
                        }}
                    </dd>
                </div>
            </dl>

            <!-- Resultado final -->
            <div v-if="run.status === 'blocked'" class="space-y-2">
                <p class="text-sm font-medium">
                    No se pudo generar el reporte porque faltan datos
                    obligatorios.
                </p>
                <RunIssuesList
                    v-if="criticalIssues.length"
                    :issues="criticalIssues"
                    :limit="2"
                />
            </div>
            <div
                v-else-if="run.status === 'failed'"
                class="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm"
            >
                <p class="font-medium text-destructive">
                    {{
                        run.error_message ??
                        'Se produjo un error durante la generación.'
                    }}
                </p>
                <p
                    v-if="run.failure_kind"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    Tipo:
                    {{
                        FAILURE_KIND_LABELS[run.failure_kind] ??
                        run.failure_kind
                    }}
                </p>
            </div>
            <p
                v-else-if="run.status === 'success'"
                class="text-sm text-muted-foreground"
            >
                El reporte se generó correctamente y está disponible para
                descarga.
            </p>
            <p
                v-else-if="run.status === 'cancelled'"
                class="text-sm text-muted-foreground"
            >
                La generación fue cancelada por solicitud del usuario. Puedes
                generarla de nuevo cuando lo necesites.
            </p>

            <!-- Acciones -->
            <div class="flex flex-wrap gap-2">
                <Button variant="outline" size="sm" @click="emit('details')">
                    <ListChecks />
                    Ver detalles
                </Button>
                <Button
                    v-if="isActive"
                    variant="ghost"
                    size="sm"
                    :disabled="refreshing"
                    @click="emit('refresh')"
                >
                    <RefreshCw :class="{ 'animate-spin': refreshing }" />
                    Consultar progreso
                </Button>
                <Button v-if="run.has_excel" as-child size="sm">
                    <a :href="downloadRoute(run.id).url">
                        <Download />
                        Descargar Excel
                    </a>
                </Button>
                <Button
                    v-if="
                        run.status === 'failed' ||
                        run.status === 'blocked' ||
                        run.status === 'cancelled'
                    "
                    variant="ghost"
                    size="sm"
                    @click="emit('retry')"
                >
                    <RotateCw />
                    Generar de nuevo
                </Button>
                <Button
                    v-if="run.can_cancel"
                    variant="outline"
                    size="sm"
                    class="text-destructive hover:text-destructive"
                    @click="emit('cancel')"
                >
                    <Ban />
                    Cancelar generación
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
