<script setup lang="ts">
/**
 * Tarjeta de una fuente de datos en la Etapa 1 (Archivos). Muestra nombre, si es necesaria
 * para BD o para el reporte, estatus de la carga (sin cargar, cargado sin procesar,
 * procesado, con error) y notas del procesamiento; permite elegir/arrastrar un archivo,
 * subirlo, descargar el original (GET /historico-general/{upload}/descargar) y eliminarlo.
 * Formatos: PDF para gastos_lendus; PDF/Excel para imss/rotacion; Excel para el resto.
 * Emits: `upload` ({ sourceId, file }) y `delete` (id de la carga); el padre hace la petición.
 */
import {
    CircleAlert,
    Download,
    FileCheck2,
    FileSpreadsheet,
    FileText,
    Trash2,
    UploadCloud,
    X,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { appSwal as Swal } from '@/components/feedback/ConfirmDialog';
import StatusBadge from './StatusBadge.vue';

/**
 * Props: `source` fuente (id, code, name, description, is_required_for_bd/report);
 * `upload` carga vigente del periodo (si existe); `disabled`; `selectedPeriodId`.
 */
const props = defineProps<{
    source: any;
    upload?: any;
    disabled?: boolean;
    selectedPeriodId: number | null;
}>();
/** `upload`: subir el archivo elegido; `delete`: eliminar la carga vigente. */
const emit = defineEmits<{
    (event: 'upload', payload: { sourceId: number; file: File }): void;
    (event: 'delete', id: number): void;
}>();

/** Archivo elegido y validado, pendiente de subir. */
const file = ref<File | null>(null);
/** Hay un archivo arrastrándose sobre la zona (resalta la zona). */
const dragActive = ref(false);
/** Input file oculto (se limpia tras subir). */
const inputRef = ref<HTMLInputElement | null>(null);

/** La fuente solo acepta PDF (Gastos Lendus). */
const isPdf = computed(() => props.source.code === 'gastos_lendus');
/** La fuente acepta PDF o Excel (IMSS, Rotación). */
const isMultiFormat = computed(() =>
    ['imss', 'rotacion'].includes(props.source.code),
);
/** Valor del atributo `accept` del input según la fuente. */
const acceptAttr = computed(() => {
    if (isPdf.value) {
        return '.pdf';
    }

    if (isMultiFormat.value) {
        return '.pdf,.xls,.xlsx,.xlsm';
    }

    return '.xls,.xlsx,.xlsm';
});
/** Texto de formatos permitidos para mostrar al usuario. */
const acceptText = computed(() => {
    if (isPdf.value) {
        return '.pdf';
    }

    if (isMultiFormat.value) {
        return '.pdf, .xls, .xlsx, .xlsm';
    }

    return '.xls, .xlsx, .xlsm';
});
/**
 * Formatea un tamaño en bytes a B/KB/MB/GB.
 * @param bytes Tamaño en bytes.
 * @returns Texto legible o null si no hay tamaño válido.
 */
const formatFileSize = (bytes: number | null | undefined) => {
    if (!bytes || bytes <= 0) {
        return null;
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    let value = bytes;
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex += 1;
    }

    return `${value.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`;
};

/** La fuente es obligatoria para cargar registros a BD. */
const isRequiredForDb = computed(() =>
    Boolean(props.source.is_required_for_bd),
);
/** La fuente es necesaria para generar el reporte. */
const isRequiredForReport = computed(() =>
    Boolean(props.source.is_required_for_report),
);
/** Estatus de la carga vigente ('pending' si no hay carga). */
const status = computed(() => props.upload?.status ?? 'pending');
// Fase 8.6: "cargado" y "procesado" son estados distintos y así se rotulan.
/** Etiqueta en español del estatus de la carga. */
const statusLabel = computed(
    () =>
        (
            ({
                pending: props.upload ? 'Cargado · sin procesar' : 'Sin cargar',
                processing: 'Procesando',
                processed: 'Procesado',
                failed: 'Con error',
            }) as Record<string, string>
        )[status.value] ?? 'Sin cargar',
);

/**
 * Valida la extensión del archivo elegido según la fuente y lo guarda en `file`;
 * si no es válida avisa con SweetAlert y no lo asigna.
 * @param selected Archivo elegido o soltado (null = nada que hacer).
 */
const assignFile = async (selected: File | null) => {
    if (!selected) {
        return;
    }

    const valid = isPdf.value
        ? /\.pdf$/i.test(selected.name)
        : isMultiFormat.value
          ? /\.(pdf|xls|xlsx|xlsm)$/i.test(selected.name)
          : /\.(xls|xlsx|xlsm)$/i.test(selected.name);

    if (!valid) {
        const msg = isPdf.value
            ? 'Carga únicamente archivos PDF para esta fuente.'
            : isMultiFormat.value
              ? 'Carga únicamente archivos PDF, xls, xlsx o xlsm.'
              : 'Carga únicamente archivos Excel: xls, xlsx o xlsm.';
        await Swal.fire({
            title: 'Formato no válido',
            text: msg,
            icon: 'error',
            confirmButtonText: 'Entendido',
        });

        return;
    }

    // Fase 8.6: sin ventana emergente — el nombre aparece en la tarjeta junto al botón para subirlo.
    file.value = selected;
};

/**
 * Maneja el soltado de un archivo en la zona (ignorado si la tarjeta está deshabilitada).
 * @param event Evento drop.
 */
const onDrop = (event: DragEvent) => {
    dragActive.value = false;

    if (props.disabled) {
        return;
    }

    assignFile(event.dataTransfer?.files?.[0] ?? null);
};

/** Emite `upload` con el archivo elegido y limpia la selección y el input. */
const doUpload = () => {
    if (!file.value) {
        return;
    }

    emit('upload', { sourceId: props.source.id, file: file.value });
    file.value = null;

    if (inputRef.value) {
        inputRef.value.value = '';
    }
};
</script>

<template>
    <article
        class="flex flex-col rounded-2xl border bg-card p-5 shadow-soft-xs"
        :class="
            upload?.status === 'failed'
                ? 'border-danger-border'
                : 'border-border'
        "
    >
        <div class="flex items-start justify-between gap-3">
            <div class="flex min-w-0 gap-3">
                <div
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl border bg-muted text-muted-foreground"
                >
                    <FileText v-if="isPdf" class="size-5" aria-hidden="true" />
                    <FileSpreadsheet v-else class="size-5" aria-hidden="true" />
                </div>
                <div class="min-w-0">
                    <h3
                        class="text-sm leading-snug font-semibold text-foreground"
                    >
                        {{ source.name }}
                    </h3>
                    <div class="mt-1 flex flex-wrap gap-1">
                        <span
                            v-if="isRequiredForDb"
                            class="app-pill app-pill-info"
                            title="Obligatoria para pasar a «Cargar registros»."
                            >Necesaria</span
                        >
                        <span
                            v-if="isRequiredForReport"
                            class="app-pill border-brand-accent/25 bg-brand-accent/10 text-brand-accent"
                            title="Se usa para generar la radiografía financiera."
                            >Para el reporte</span
                        >
                        <span
                            v-if="!isRequiredForDb && !isRequiredForReport"
                            class="app-pill app-pill-muted"
                            >Complementaria</span
                        >
                    </div>
                </div>
            </div>
            <StatusBadge
                class="shrink-0"
                :status="
                    upload?.status === 'failed'
                        ? 'failed'
                        : upload?.status === 'processed'
                          ? 'processed'
                          : upload
                            ? 'queued'
                            : 'pending'
                "
                :label="statusLabel"
            />
        </div>

        <p
            v-if="source.description"
            class="mt-2 text-xs leading-relaxed text-muted-foreground"
        >
            {{ source.description }}
        </p>

        <!-- Archivo actual -->
        <div v-if="upload" class="mt-3 rounded-xl border bg-muted/40 p-3">
            <div class="flex items-start gap-2.5">
                <FileCheck2
                    class="mt-0.5 size-4 shrink-0"
                    :class="
                        upload.status === 'failed'
                            ? 'text-danger'
                            : 'text-success'
                    "
                    aria-hidden="true"
                />
                <div class="min-w-0 text-xs">
                    <p
                        class="truncate font-semibold text-foreground"
                        :title="upload.original_name"
                    >
                        {{ upload.original_name }}
                    </p>
                    <p class="mt-0.5 text-muted-foreground">
                        Cargado: {{ upload.uploaded_at ?? '—'
                        }}<span v-if="formatFileSize(upload.file_size)">
                            · {{ formatFileSize(upload.file_size) }}</span
                        >
                    </p>
                    <p
                        v-if="upload.covered_period_labels?.length"
                        class="mt-0.5 line-clamp-2 text-muted-foreground"
                        :title="upload.covered_period_labels.join(', ')"
                    >
                        Cobertura: {{ upload.covered_period_labels.join(', ') }}
                    </p>
                </div>
            </div>
            <div
                v-if="upload.notes"
                class="mt-2.5 rounded-lg border px-2.5 py-2 text-xs"
                :class="
                    upload.status === 'failed'
                        ? 'border-danger-border bg-danger-soft text-foreground'
                        : 'border-border bg-card text-muted-foreground'
                "
            >
                <p
                    v-if="upload.status === 'failed'"
                    class="mb-0.5 flex items-center gap-1 font-semibold text-danger"
                >
                    <CircleAlert class="size-3.5" aria-hidden="true" /> Por qué
                    falló
                </p>
                <p class="leading-relaxed">{{ upload.notes }}</p>
                <p
                    v-if="upload.status === 'failed'"
                    class="mt-1 text-muted-foreground"
                >
                    Corrige el archivo (o elige el correcto) y vuelve a subirlo.
                </p>
            </div>
        </div>

        <!-- Zona para elegir archivo -->
        <div
            class="mt-3 rounded-xl border-2 border-dashed text-center transition-colors"
            :class="[
                dragActive ? 'border-primary bg-accent' : 'border-border',
                upload ? 'p-3' : 'p-4',
                disabled ? 'opacity-60' : '',
            ]"
            @dragenter.prevent="dragActive = true"
            @dragover.prevent="dragActive = true"
            @dragleave.prevent="dragActive = false"
            @drop.prevent="onDrop"
        >
            <UploadCloud
                v-if="!upload"
                class="mx-auto size-6 text-muted-foreground"
                aria-hidden="true"
            />
            <p
                class="text-sm font-medium text-foreground"
                :class="upload ? '' : 'mt-1.5'"
            >
                {{
                    upload
                        ? 'Para reemplazarlo, arrastra aquí otro archivo'
                        : isPdf
                          ? 'Arrastra el PDF aquí'
                          : isMultiFormat
                            ? 'Arrastra el PDF o Excel aquí'
                            : 'Arrastra el Excel aquí'
                }}
            </p>
            <p class="mt-0.5 text-xs text-muted-foreground">
                Formatos: {{ acceptText }}
            </p>
            <button
                type="button"
                class="mt-2.5 inline-flex h-8 items-center rounded-lg border border-border bg-card px-3 text-xs font-semibold text-foreground transition hover:bg-muted disabled:cursor-not-allowed"
                :disabled="disabled"
                @click="inputRef?.click()"
            >
                Seleccionar archivo
            </button>
            <input
                ref="inputRef"
                type="file"
                class="hidden"
                :accept="acceptAttr"
                :aria-label="`Seleccionar archivo para ${source.name}`"
                @change="
                    assignFile(
                        ($event.target as HTMLInputElement).files?.[0] ?? null,
                    )
                "
            />
            <p v-if="disabled && !file" class="app-help mt-2">
                No disponible: hay una carga en curso o el periodo no recibe
                archivos.
            </p>
        </div>

        <!-- Archivo elegido, listo para subir -->
        <div
            v-if="file"
            class="mt-3 flex items-center gap-2 rounded-xl border border-primary/30 bg-accent/60 p-2.5"
        >
            <FileCheck2
                class="size-4 shrink-0 text-primary"
                aria-hidden="true"
            />
            <span
                class="min-w-0 flex-1 truncate text-xs font-semibold text-foreground"
                :title="file.name"
                >{{ file.name }}</span
            >
            <button
                type="button"
                class="rounded-md p-1 text-muted-foreground hover:bg-card hover:text-foreground"
                aria-label="Quitar archivo elegido"
                @click="file = null"
            >
                <X class="size-3.5" />
            </button>
        </div>

        <!-- Acciones: subir/reemplazar, descargar original, eliminar -->
        <div class="mt-auto flex flex-wrap items-center gap-2 pt-4">
            <button
                v-if="file"
                type="button"
                class="inline-flex h-9 items-center rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground transition hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="disabled"
                @click="doUpload"
            >
                {{ upload ? 'Reemplazar archivo' : 'Subir archivo' }}
            </button>
            <a
                v-if="upload?.can_download"
                :href="`/historico-general/${upload.id}/descargar`"
                target="_blank"
                rel="noopener"
                class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-border px-3 text-sm font-medium text-foreground transition hover:bg-muted"
                ><Download class="size-4" aria-hidden="true" /> Descargar
                original</a
            >
            <button
                v-if="upload"
                type="button"
                class="ml-auto inline-flex h-9 items-center gap-1.5 rounded-lg px-2.5 text-sm font-medium text-danger transition hover:bg-danger-soft"
                :aria-label="`Eliminar el archivo de ${source.name}`"
                @click="emit('delete', upload.id)"
            >
                <Trash2 class="size-4" aria-hidden="true" /> Eliminar
            </button>
        </div>
    </article>
</template>
