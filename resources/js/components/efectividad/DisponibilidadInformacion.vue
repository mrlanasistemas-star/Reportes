<script setup lang="ts">
import {
    AlertTriangle,
    CalendarDays,
    CheckCircle2,
    CircleSlash,
    FileSpreadsheet,
    History,
    Info,
    Loader2,
    RefreshCw,
    Upload,
    XCircle,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from '@/components/feedback/Toast';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { availability as availabilityRoute } from '@/routes/efectividad-cobranza';
import snapshots from '@/routes/efectividad-cobranza/snapshots';
import { csrfToken, formatNumber } from './format';
import RunIssuesList from './RunIssuesList.vue';
import type { Availability, PhotoAvailability } from './types';

const props = defineProps<{
    periodId: number | string | null;
    branchId: number | string | null;
    mode: 'SEMANAL' | 'DIARIO';
    cutoffDate: string | null;
}>();

const emit = defineEmits<{ (e: 'update', value: Availability | null): void }>();

const data = ref<Availability | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const uploadingDate = ref<string | null>(null);
const fileInput = ref<HTMLInputElement | null>(null);
const targetPhoto = ref<PhotoAvailability | null>(null);
let timer: ReturnType<typeof setTimeout> | null = null;

const dateFmt = new Intl.DateTimeFormat('es-MX', {
    day: '2-digit',
    month: 'short',
    timeZone: 'UTC',
});
const fullDateFmt = new Intl.DateTimeFormat('es-MX', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC',
});

function fmtDate(ymd: string | null, full = false): string {
    if (!ymd) {
        return '—';
    }

    const d = new Date(`${ymd.slice(0, 10)}T00:00:00Z`);

    return (full ? fullDateFmt : dateFmt).format(d);
}

async function load() {
    if (timer) {
        clearTimeout(timer);
        timer = null;
    }

    if (!props.periodId || (props.mode === 'DIARIO' && !props.cutoffDate)) {
        data.value = null;
        emit('update', null);

        return;
    }

    loading.value = true;
    loadError.value = null;

    try {
        const res = await fetch(
            availabilityRoute({
                query: {
                    period_id: String(props.periodId),
                    branch_id: props.branchId
                        ? String(props.branchId)
                        : undefined,
                    mode: props.mode,
                    cutoff_date:
                        props.mode === 'DIARIO'
                            ? (props.cutoffDate ?? undefined)
                            : undefined,
                },
            }).url,
            { headers: { Accept: 'application/json' } },
        );

        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        data.value = (await res.json()) as Availability;
        emit('update', data.value);

        // Mientras haya fotografías procesándose, se consulta de nuevo (desde la BD).
        if (
            data.value.supported &&
            data.value.fotos.some((f) => f.estado === 'pendiente')
        ) {
            timer = setTimeout(load, 5000);
        }
    } catch {
        loadError.value =
            'No se pudo consultar la disponibilidad de información. Intenta de nuevo.';
    } finally {
        loading.value = false;
    }
}

watch(
    () => [props.periodId, props.branchId, props.mode, props.cutoffDate],
    () => void load(),
    { immediate: true },
);

onBeforeUnmount(() => {
    if (timer) {
        clearTimeout(timer);
    }
});

defineExpose({ reload: load });

function pickFile(photo: PhotoAvailability) {
    targetPhoto.value = photo;
    fileInput.value?.click();
}

async function onFileChosen(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    const photo = targetPhoto.value;
    input.value = '';

    if (!file || !photo) {
        return;
    }

    uploadingDate.value = photo.fecha;
    const body = new FormData();
    body.append('file', file);
    body.append('snapshot_date', photo.fecha);

    try {
        const res = await fetch(snapshots.store().url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body,
        });
        const payload = await res.json().catch(() => ({}));

        if (!res.ok) {
            const firstError = payload?.errors
                ? (Object.values(payload.errors)[0] as string[])[0]
                : null;
            void toast.fire({
                icon: 'error',
                title:
                    firstError ??
                    payload?.message ??
                    'No se pudo cargar la fotografía.',
            });

            return;
        }

        void toast.fire({ icon: 'success', title: payload.message });
        await load();
    } catch {
        void toast.fire({
            icon: 'error',
            title: 'No se pudo conectar con el servidor para cargar la fotografía.',
        });
    } finally {
        uploadingDate.value = null;
    }
}

const ok = computed(() => (data.value?.supported ? data.value : null));

const estadoMeta: Record<
    PhotoAvailability['estado'],
    { label: string; tone: string; icon: unknown }
> = {
    disponible: {
        label: 'Disponible',
        tone: 'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300',
        icon: CheckCircle2,
    },
    pendiente: {
        label: 'Procesando',
        tone: 'border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-300',
        icon: Loader2,
    },
    fallida: {
        label: 'Error al procesar',
        tone: 'border-red-200 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300',
        icon: XCircle,
    },
    faltante: {
        label: 'Faltante',
        tone: 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200',
        icon: CircleSlash,
    },
};
</script>

<template>
    <Card class="gap-4">
        <CardHeader
            class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"
        >
            <div class="min-w-0">
                <CardTitle class="text-base"
                    >Fotografías diarias (cortes según Excel)</CardTitle
                >
                <CardDescription>
                    El modo «Cortes según Excel» necesita una fotografía de
                    cartera por día (domingo base a fecha de corte) y los
                    Ingresos Cobranza del periodo. Ninguna fotografía faltante
                    se sustituye con otra.
                </CardDescription>
            </div>
            <Button
                variant="outline"
                size="sm"
                class="shrink-0"
                :disabled="loading || !periodId"
                @click="load"
            >
                <RefreshCw :class="{ 'animate-spin': loading }" />
                Actualizar
            </Button>
        </CardHeader>

        <CardContent class="space-y-4">
            <p v-if="!periodId" class="text-sm text-muted-foreground">
                Selecciona una semana operativa para ver qué información está
                disponible.
            </p>

            <Alert v-else-if="loadError" variant="destructive">
                <XCircle />
                <AlertDescription>{{ loadError }}</AlertDescription>
            </Alert>

            <Alert v-else-if="data && !data.supported">
                <Info />
                <AlertTitle
                    >Periodo no compatible con «Cortes según Excel»</AlertTitle
                >
                <AlertDescription>{{ data.message }}</AlertDescription>
            </Alert>

            <template v-else-if="ok">
                <div
                    class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm"
                >
                    <span class="flex items-center gap-1.5">
                        <CalendarDays
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        Domingo base
                        <strong>{{
                            fmtDate(ok.ventana.domingo_base, true)
                        }}</strong>
                        · Corte
                        <strong>{{
                            fmtDate(ok.ventana.fecha_corte, true)
                        }}</strong>
                    </span>
                    <Badge
                        :variant="
                            ok.fotos_disponibles === ok.fotos_requeridas
                                ? 'default'
                                : 'outline'
                        "
                    >
                        {{ ok.fotos_disponibles }} de
                        {{ ok.fotos_requeridas }} fotografías
                    </Badge>
                </div>

                <input
                    ref="fileInput"
                    type="file"
                    class="hidden"
                    accept=".xlsx,.xls,.xlsm"
                    @change="onFileChosen"
                />

                <ul
                    class="grid grid-cols-1 gap-2 min-[420px]:grid-cols-2 lg:grid-cols-4"
                >
                    <li
                        v-for="foto in ok.fotos"
                        :key="foto.fecha"
                        class="flex min-w-0 flex-col gap-2 rounded-lg border p-3"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">
                                    {{ foto.dia }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ fmtDate(foto.fecha, true) }}
                                </p>
                            </div>
                            <span
                                class="inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-medium"
                                :class="estadoMeta[foto.estado].tone"
                            >
                                <component
                                    :is="estadoMeta[foto.estado].icon"
                                    class="size-3"
                                    :class="{
                                        'animate-spin':
                                            foto.estado === 'pendiente',
                                    }"
                                    aria-hidden="true"
                                />
                                {{ estadoMeta[foto.estado].label }}
                            </span>
                        </div>
                        <p
                            v-if="foto.estado === 'disponible'"
                            class="text-xs text-muted-foreground"
                        >
                            {{ formatNumber(foto.contratos) }} contratos ·
                            {{ foto.oficinas.length }} oficina(s)
                        </p>
                        <p
                            v-for="a in foto.archivos"
                            :key="a.snapshot_id"
                            class="flex items-center gap-1 truncate text-xs text-muted-foreground"
                            :title="a.archivo ?? ''"
                        >
                            <FileSpreadsheet
                                class="size-3 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="truncate">{{ a.archivo }}</span>
                        </p>
                        <p
                            v-if="foto.error"
                            class="line-clamp-3 text-xs text-destructive"
                            :title="foto.error"
                        >
                            {{ foto.error }}
                        </p>
                        <p
                            v-if="foto.estado === 'faltante'"
                            class="text-xs text-muted-foreground"
                        >
                            Saldos por Cliente de Lendus al cierre del
                            {{ fmtDate(foto.fecha, true) }}.
                        </p>
                        <Button
                            size="sm"
                            variant="outline"
                            class="mt-auto w-full"
                            :disabled="
                                uploadingDate !== null ||
                                foto.estado === 'pendiente'
                            "
                            @click="pickFile(foto)"
                        >
                            <Loader2
                                v-if="uploadingDate === foto.fecha"
                                class="animate-spin"
                            />
                            <Upload v-else />
                            {{
                                foto.estado === 'disponible'
                                    ? 'Reemplazar'
                                    : 'Cargar fotografía'
                            }}
                        </Button>
                    </li>
                </ul>

                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <div class="rounded-lg border p-3">
                        <p
                            class="flex items-center gap-1.5 text-sm font-medium"
                        >
                            <component
                                :is="
                                    ok.ingresos.movimientos_corte > 0
                                        ? CheckCircle2
                                        : AlertTriangle
                                "
                                class="size-4"
                                :class="
                                    ok.ingresos.movimientos_corte > 0
                                        ? 'text-emerald-600'
                                        : 'text-amber-600'
                                "
                                aria-hidden="true"
                            />
                            Ingresos del periodo
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ formatNumber(ok.ingresos.movimientos_corte) }}
                            movimientos en
                            {{ ok.ingresos.dias_con_movimientos }} día(s)
                            <template v-if="ok.ingresos.primer_dia">
                                ({{ fmtDate(ok.ingresos.primer_dia) }} a
                                {{ fmtDate(ok.ingresos.ultimo_dia) }})</template
                            >. Se cargan en Histórico General (Lendus Ingresos
                            Cobranza del mes).
                        </p>
                    </div>
                    <div class="rounded-lg border p-3">
                        <p
                            class="flex items-center gap-1.5 text-sm font-medium"
                        >
                            <History
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            Histórico de ingresos
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            <template v-if="ok.ingresos.historial_movimientos">
                                {{
                                    formatNumber(
                                        ok.ingresos.historial_movimientos,
                                    )
                                }}
                                movimientos previos ({{
                                    fmtDate(ok.ingresos.historial_desde, true)
                                }}
                                a
                                {{
                                    fmtDate(ok.ingresos.historial_hasta, true)
                                }}); semana anterior:
                                {{
                                    formatNumber(
                                        ok.ingresos.semana_previa_movimientos,
                                    )
                                }}.
                            </template>
                            <template v-else
                                >Sin movimientos anteriores al periodo: no se
                                podrán reconocer anticipos previos.</template
                            >
                        </p>
                    </div>
                </div>

                <RunIssuesList v-if="ok.issues.length" :issues="ok.issues" />
            </template>
        </CardContent>
    </Card>
</template>
