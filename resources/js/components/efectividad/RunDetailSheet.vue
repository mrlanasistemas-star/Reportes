<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Ban,
    CheckCircle2,
    Circle,
    CircleDashed,
    Download,
    ExternalLink,
    FileSpreadsheet,
    Loader2,
    Mail,
    OctagonX,
    PauseCircle,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useEfectividadRunPolling } from '@/composables/useEfectividadRunPolling';
import { download as downloadRoute } from '@/routes/efectividad-cobranza';
import runs from '@/routes/efectividad-cobranza/runs';
import {
    FAILURE_KIND_LABELS,
    formatDateTime,
    formatDuration,
    formatNumber,
    isActiveStatus,
} from './format';
import RunIssuesList from './RunIssuesList.vue';
import RunStatusBadge from './RunStatusBadge.vue';
import { isV4Coverage, isV4Sources } from './types';
import type { RunDetail, StageEntry } from './types';

const props = defineProps<{ runId: number | null; seed?: RunDetail | null }>();
const open = defineModel<boolean>('open', { default: false });

const detail = ref<RunDetail | null>(null);
const loadError = ref<string | null>(null);

const polling = useEfectividadRunPolling({
    onUpdate: (d) => {
        detail.value = d;
        loadError.value = null;
    },
    onError: (message) => {
        loadError.value = message;
    },
});

watch(
    () => [open.value, props.runId] as const,
    ([isOpen, id]) => {
        if (!isOpen || !id) {
            polling.stop();

            return;
        }

        detail.value = props.seed && props.seed.id === id ? props.seed : null;
        loadError.value = null;
        // Siempre se consulta la BD al abrir; si sigue activa, se mantiene actualizado.
        polling.refreshNow(id).then((d) => {
            if (d && isActiveStatus(d.status)) {
                polling.start(id);
            }
        });
    },
    { immediate: true },
);

const notificationLabel = computed(() => {
    const n = detail.value?.notification;

    if (!n?.status) {
        return detail.value && isActiveStatus(detail.value.status)
            ? 'Se enviará al terminar.'
            : 'Sin registro (ejecución anterior a las notificaciones por correo).';
    }

    return (
        {
            sent: `Entregado al servidor de correo el ${formatDateTime(n.notified_at)}.`,
            sending: 'Enviando…',
            failed: `No enviado: ${n.error ?? 'error desconocido'} (intentos: ${n.attempts}).`,
            skipped: n.error ?? 'Omitido.',
        }[n.status] ?? n.status
    );
});

function stageIcon(stage: StageEntry) {
    return (
        {
            done: CheckCircle2,
            running: Loader2,
            blocked: PauseCircle,
            failed: OctagonX,
            cancelled: Ban,
            pending: Circle,
            unknown: CircleDashed,
        }[stage.status] ?? Circle
    );
}

function stageTone(stage: StageEntry) {
    return (
        {
            done: 'text-emerald-600 dark:text-emerald-400',
            running: 'text-primary animate-spin',
            blocked: 'text-amber-600',
            failed: 'text-destructive',
            cancelled: 'text-muted-foreground',
            pending: 'text-muted-foreground/50',
            unknown: 'text-muted-foreground/50',
        }[stage.status] ?? ''
    );
}

function stageDuration(stage: StageEntry): string | null {
    if (!stage.started_at || !stage.finished_at) {
        return null;
    }

    return formatDuration(
        (new Date(stage.finished_at).getTime() -
            new Date(stage.started_at).getTime()) /
            1000,
    );
}

const v4Sources = computed(() =>
    detail.value && isV4Sources(detail.value.sources)
        ? detail.value.sources
        : null,
);
const legacySources = computed(() =>
    detail.value?.sources && !isV4Sources(detail.value.sources)
        ? detail.value.sources
        : null,
);
// Motor Aprobada/Periódica: sin `sources` (fotografías/uploads) — la única
// trazabilidad real es la cobertura de cartera/ingresos que el adaptador YA deriva.
const legacyCoverage = computed(() =>
    !detail.value?.sources &&
    detail.value?.coverage &&
    !isV4Coverage(detail.value.coverage)
        ? detail.value.coverage
        : null,
);

function dmy(ymd: string | null | undefined): string {
    return ymd ? ymd.slice(0, 10).split('-').reverse().join('/') : '—';
}

const hasLegacyTimeline = computed(() =>
    detail.value?.stages.every((s) => s.status === 'unknown'),
);

function openInModule() {
    if (detail.value) {
        open.value = false;
        router.get(runs.show(detail.value.id).url);
    }
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="right"
            class="w-full gap-0 overflow-y-auto p-0 sm:max-w-2xl"
        >
            <SheetHeader class="border-b p-4 sm:p-5">
                <div class="flex flex-wrap items-center gap-2 pr-8">
                    <SheetTitle class="text-base"
                        >Ejecución #{{ runId }}</SheetTitle
                    >
                    <RunStatusBadge
                        v-if="detail"
                        :state="detail.display_state"
                        :label="detail.display_label"
                    />
                </div>
                <SheetDescription>
                    <template v-if="detail"
                        >{{ detail.report }} · {{ detail.period_label }} ·
                        {{ detail.branch_label }}</template
                    >
                    <template v-else
                        >Consultando el estado guardado en el
                        servidor…</template
                    >
                </SheetDescription>
            </SheetHeader>

            <div v-if="loadError && !detail" class="p-5">
                <Alert variant="destructive"
                    ><AlertDescription>{{ loadError }}</AlertDescription></Alert
                >
            </div>

            <div v-else-if="!detail" class="space-y-3 p-5">
                <Skeleton class="h-6 w-1/2" />
                <Skeleton class="h-24 w-full" />
                <Skeleton class="h-40 w-full" />
            </div>

            <div v-else class="space-y-6 p-4 sm:p-5">
                <!-- Resumen -->
                <section class="space-y-3">
                    <dl
                        class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-3"
                    >
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Motor de cálculo
                            </dt>
                            <dd class="font-medium">
                                {{ detail.engine_label }}
                            </dd>
                        </div>
                        <div v-if="detail.mode">
                            <dt class="text-xs text-muted-foreground">
                                Modo y corte
                            </dt>
                            <dd class="font-medium">
                                {{ detail.mode }} ·
                                {{ dmy(detail.cutoff_date) }}
                            </dd>
                        </div>
                        <div v-if="detail.incidents_count !== null">
                            <dt class="text-xs text-muted-foreground">
                                Incidencias de cálculo
                            </dt>
                            <dd class="font-medium">
                                {{ formatNumber(detail.incidents_count) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Solicitado por
                            </dt>
                            <dd class="font-medium">
                                {{ detail.requested_by?.name ?? '—' }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Solicitado
                            </dt>
                            <dd class="font-medium">
                                {{ formatDateTime(detail.queued_at) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Inicio
                            </dt>
                            <dd class="font-medium">
                                {{ formatDateTime(detail.started_at) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Finalización
                            </dt>
                            <dd class="font-medium">
                                {{ formatDateTime(detail.finished_at) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Duración
                            </dt>
                            <dd class="font-medium">
                                {{ formatDuration(detail.duration_seconds) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Última actividad
                            </dt>
                            <dd class="font-medium">
                                {{ formatDateTime(detail.heartbeat_at) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Intentos
                            </dt>
                            <dd class="font-medium">
                                {{ detail.attempts || '—' }}
                            </dd>
                        </div>
                        <div v-if="detail.failure_kind">
                            <dt class="text-xs text-muted-foreground">
                                Tipo de problema
                            </dt>
                            <dd class="font-medium">
                                {{
                                    FAILURE_KIND_LABELS[detail.failure_kind] ??
                                    detail.failure_kind
                                }}
                            </dd>
                        </div>
                        <div v-if="detail.technical_reference">
                            <dt class="text-xs text-muted-foreground">
                                Referencia técnica (admin)
                            </dt>
                            <dd class="font-mono text-xs">
                                {{ detail.technical_reference }}
                            </dd>
                        </div>
                    </dl>
                    <p
                        v-if="detail.health_message"
                        class="rounded-md border border-amber-300 bg-amber-50 p-2.5 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
                    >
                        {{ detail.health_message }}
                    </p>
                    <p
                        v-if="detail.superseded_by_id"
                        class="text-sm text-muted-foreground"
                    >
                        Existe una versión más reciente de este reporte
                        (ejecución #{{ detail.superseded_by_id }}). Esta se
                        conserva como versión previa.
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="detail.has_excel" as-child size="sm">
                            <a :href="downloadRoute(detail.id).url"
                                ><Download /> Descargar Excel</a
                            >
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            @click="openInModule"
                            ><ExternalLink /> Abrir en el módulo</Button
                        >
                    </div>
                </section>

                <!-- Etapas -->
                <section class="space-y-3">
                    <h3 class="text-sm font-semibold">
                        Etapas del procesamiento
                    </h3>
                    <p
                        v-if="hasLegacyTimeline"
                        class="text-sm text-muted-foreground"
                    >
                        Esta ejecución se generó antes del seguimiento por
                        etapas; no hay registro detallado de su avance.
                    </p>
                    <ol v-else class="relative space-y-3 border-l pl-5">
                        <li
                            v-for="stage in detail.stages"
                            :key="stage.key"
                            class="relative"
                        >
                            <component
                                :is="stageIcon(stage)"
                                class="absolute top-0.5 -left-[1.72rem] size-4 rounded-full bg-background"
                                :class="stageTone(stage)"
                                aria-hidden="true"
                            />
                            <div
                                class="flex flex-wrap items-baseline justify-between gap-x-2"
                            >
                                <p
                                    class="text-sm font-medium"
                                    :class="{
                                        'text-muted-foreground':
                                            stage.status === 'pending',
                                    }"
                                >
                                    {{ stage.position }}. {{ stage.label }}
                                </p>
                                <span
                                    v-if="stageDuration(stage)"
                                    class="text-xs text-muted-foreground tabular-nums"
                                    >{{ stageDuration(stage) }}</span
                                >
                            </div>
                            <p class="text-xs text-muted-foreground">
                                {{ stage.summary ?? stage.description }}
                            </p>
                            <p
                                v-if="
                                    stage.total !== null &&
                                    stage.status === 'running'
                                "
                                class="text-xs tabular-nums"
                            >
                                {{ formatNumber(stage.processed) }} de
                                {{ formatNumber(stage.total) }} registros
                            </p>
                        </li>
                    </ol>
                </section>

                <!-- Incidencias -->
                <section class="space-y-3">
                    <h3 class="text-sm font-semibold">
                        Incidencias
                        <span class="font-normal text-muted-foreground"
                            >({{ detail.issues.length }})</span
                        >
                    </h3>
                    <p
                        v-if="!detail.issues.length"
                        class="text-sm text-muted-foreground"
                    >
                        Sin incidencias registradas.
                    </p>
                    <RunIssuesList v-else :issues="detail.issues" />
                </section>

                <!-- Fuentes utilizadas (motor V4: fotografías diarias + ingresos) -->
                <section v-if="v4Sources" class="space-y-3">
                    <h3 class="text-sm font-semibold">Fuentes utilizadas</h3>
                    <ul class="divide-y rounded-lg border">
                        <li
                            v-for="f in v4Sources.fotos"
                            :key="f.fecha"
                            class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm"
                        >
                            <FileSpreadsheet
                                class="size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span class="w-32 shrink-0 font-medium"
                                >{{ f.dia }} {{ dmy(f.fecha) }}</span
                            >
                            <span
                                class="min-w-0 flex-1 truncate text-xs text-muted-foreground"
                            >
                                {{
                                    f.archivos
                                        .map((a) => a.archivo)
                                        .join(', ') || 'Sin fotografía'
                                }}
                            </span>
                            <span class="text-xs tabular-nums"
                                >{{ formatNumber(f.contratos) }} contratos</span
                            >
                            <span
                                class="text-xs"
                                :class="
                                    f.estado === 'disponible'
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-amber-600'
                                "
                                >{{
                                    {
                                        disponible: 'Disponible',
                                        pendiente: 'Procesando',
                                        fallida: 'Error',
                                        faltante: 'Faltante',
                                    }[f.estado]
                                }}</span
                            >
                        </li>
                        <li class="px-3 py-2 text-sm">
                            Ingresos Cobranza:
                            {{
                                formatNumber(
                                    v4Sources.ingresos.movimientos_corte,
                                )
                            }}
                            movimientos del corte ·
                            {{
                                formatNumber(
                                    v4Sources.ingresos.historial_movimientos,
                                )
                            }}
                            en el histórico previo
                        </li>
                    </ul>
                </section>
                <section v-else-if="legacySources" class="space-y-3">
                    <h3 class="text-sm font-semibold">Fuentes utilizadas</h3>
                    <div
                        v-for="key in ['saldos', 'ingresos'] as const"
                        :key="key"
                        class="rounded-lg border"
                    >
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-2 border-b bg-muted/40 px-3 py-2"
                        >
                            <p class="text-sm font-medium">
                                {{ legacySources[key].label }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                <template v-if="key === 'saldos'"
                                    >Corte
                                    {{ legacySources.saldos.period_label }} ·
                                    {{
                                        formatNumber(
                                            legacySources.saldos.rows_in_db,
                                        )
                                    }}
                                    registros</template
                                >
                                <template v-else>
                                    {{
                                        formatNumber(
                                            legacySources.ingresos
                                                .rows_in_period,
                                        )
                                    }}
                                    del periodo ·
                                    {{
                                        formatNumber(
                                            legacySources.ingresos.history_rows,
                                        )
                                    }}
                                    en histórico
                                    <template
                                        v-if="legacySources.ingresos.history_to"
                                        >(hasta
                                        {{
                                            legacySources.ingresos.history_to
                                        }})</template
                                    >
                                </template>
                            </p>
                        </div>
                        <ul
                            v-if="legacySources[key].uploads.length"
                            class="divide-y"
                        >
                            <li
                                v-for="u in legacySources[key].uploads"
                                :key="u.id"
                                class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-sm"
                            >
                                <FileSpreadsheet
                                    class="size-4 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span
                                    class="min-w-0 flex-1 truncate"
                                    :title="u.file"
                                    >{{ u.file }}</span
                                >
                                <span class="text-xs text-muted-foreground">{{
                                    formatDateTime(u.uploaded_at)
                                }}</span>
                                <span class="text-xs tabular-nums"
                                    >{{ formatNumber(u.rows) }} reg.</span
                                >
                                <span
                                    class="text-xs"
                                    :class="
                                        u.status === 'processed'
                                            ? 'text-emerald-600 dark:text-emerald-400'
                                            : 'text-amber-600'
                                    "
                                >
                                    {{
                                        {
                                            processed: 'Procesado',
                                            failed: 'Falló',
                                            pending: 'Pendiente',
                                            processing: 'Procesando',
                                        }[u.status ?? ''] ?? u.status
                                    }}
                                </span>
                                <span
                                    v-if="!u.file_exists"
                                    class="text-xs text-amber-600"
                                    >Archivo físico no disponible</span
                                >
                            </li>
                        </ul>
                        <p
                            v-else
                            class="px-3 py-2 text-sm text-muted-foreground"
                        >
                            Sin archivos registrados para este periodo.
                        </p>
                    </div>
                </section>
                <section
                    v-else-if="legacyCoverage"
                    class="space-y-2 rounded-lg border p-3"
                >
                    <h3 class="text-sm font-semibold">Fuentes utilizadas</h3>
                    <p class="text-sm text-muted-foreground">
                        {{ legacyCoverage.message }}
                    </p>
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Filas de cartera (Saldos por Cliente)
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{
                                    formatNumber(
                                        legacyCoverage.cartera_rows_total,
                                    )
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Filas de Ingresos Cobranza
                            </dt>
                            <dd class="font-medium tabular-nums">
                                {{
                                    formatNumber(
                                        legacyCoverage.ingresos_rows_total,
                                    )
                                }}
                                ({{
                                    formatNumber(
                                        legacyCoverage.ingresos_rows_usables,
                                    )
                                }}
                                utilizables)
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Columnas verificadas -->
                <section v-if="detail.columns" class="space-y-3">
                    <h3 class="text-sm font-semibold">Columnas verificadas</h3>
                    <Collapsible
                        v-for="key in ['ingresos', 'saldos'] as const"
                        :key="key"
                        class="rounded-lg border"
                    >
                        <CollapsibleTrigger
                            class="flex w-full items-center justify-between px-3 py-2 text-left text-sm font-medium hover:bg-muted/40"
                        >
                            <span>{{ detail.columns[key]?.label }}</span>
                            <span
                                class="text-xs font-normal text-muted-foreground"
                            >
                                {{
                                    detail.columns[key]?.fields.filter(
                                        (f) => f.status === 'missing',
                                    ).length
                                }}
                                faltantes ·
                                {{ detail.columns[key]?.fields.length }} campos
                            </span>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <div class="overflow-x-auto border-t">
                                <table class="w-full min-w-[480px] text-xs">
                                    <thead
                                        class="bg-muted/40 text-left text-muted-foreground"
                                    >
                                        <tr>
                                            <th class="px-3 py-1.5 font-medium">
                                                Campo
                                            </th>
                                            <th class="px-3 py-1.5 font-medium">
                                                Encabezado en archivo
                                            </th>
                                            <th
                                                class="px-3 py-1.5 text-right font-medium"
                                            >
                                                Registros vacíos
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y">
                                        <tr
                                            v-for="f in detail.columns[key]
                                                ?.fields ?? []"
                                            :key="f.field"
                                        >
                                            <td class="px-3 py-1.5">
                                                {{ f.label }}
                                                <span
                                                    v-if="f.critical"
                                                    class="text-muted-foreground"
                                                    >*</span
                                                >
                                            </td>
                                            <td class="px-3 py-1.5">
                                                <span
                                                    v-if="f.status === 'found'"
                                                    class="text-emerald-700 dark:text-emerald-400"
                                                    >{{
                                                        f.original_header
                                                    }}</span
                                                >
                                                <span
                                                    v-else-if="
                                                        f.status === 'missing'
                                                    "
                                                    class="font-medium text-destructive"
                                                    >No encontrado</span
                                                >
                                                <span
                                                    v-else
                                                    class="text-muted-foreground"
                                                    >Sin archivo para
                                                    revisar</span
                                                >
                                            </td>
                                            <td
                                                class="px-3 py-1.5 text-right tabular-nums"
                                            >
                                                {{
                                                    f.empty_rows === null
                                                        ? '—'
                                                        : `${formatNumber(f.empty_rows)} de ${formatNumber(f.total_rows)}`
                                                }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                                <p
                                    class="px-3 py-1.5 text-[11px] text-muted-foreground"
                                >
                                    * Campo indispensable para la calculadora
                                    aprobada.
                                </p>
                            </div>
                        </CollapsibleContent>
                    </Collapsible>
                </section>

                <!-- Notificación -->
                <section class="space-y-2">
                    <h3 class="text-sm font-semibold">
                        Notificación por correo
                    </h3>
                    <p
                        class="flex items-start gap-2 text-sm text-muted-foreground"
                    >
                        <Mail
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        {{ notificationLabel }}
                    </p>
                </section>
            </div>
        </SheetContent>
    </Sheet>
</template>
