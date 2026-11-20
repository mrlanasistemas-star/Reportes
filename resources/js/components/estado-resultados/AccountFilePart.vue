<script setup lang="ts">
import {
    AlertTriangle,
    CheckCircle2,
    Loader2,
    Lock,
    Trash2,
    UploadCloud,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import { swal } from '@/composables/estado-resultados/erSwal';
import FileLifecycle from './FileLifecycle.vue';
import { escapeHtml, mxn } from './types';
import type { AccountFilePart, UploadRules } from './types';

/**
 * Una zona de archivo de la cuenta: «Archivo 1 (obligatorio)» o «Archivo 2 (opcional)».
 * El archivo 2 nunca se exige; se puede quitar (el 1 sólo se sustituye).
 */
const props = defineProps<{
    slotKey: string;
    accountLabel: string;
    file: AccountFilePart;
    canUpload: boolean;
    uploading: boolean;
    /** Otra operación de la misma cuenta en curso (deshabilita esta zona). */
    busy?: boolean;
    uploadRules?: UploadRules | null;
    lockReason?: string | null;
    /** Movimientos de la cuenta por revisar (se muestran en el archivo 1). */
    pendingReview?: number;
}>();

const emit = defineEmits<{ upload: [file: File]; remove: [] }>();

const dragging = ref(false);
const input = ref<HTMLInputElement | null>(null);
const optional = computed(() => !props.file.required);
const upload = computed(() => props.file.upload);
const inputId = computed(() =>
    props.file.part === 1
        ? `er-slot-input-${props.slotKey}`
        : `er-slot-input-${props.slotKey}-${props.file.part}`,
);
const disabled = computed(() => props.uploading || !!props.busy);

/** Si ya hay archivo en esta zona, se confirma antes de sustituirlo (clic o arrastre). */
async function confirmReplace(file: File): Promise<boolean> {
    const current = upload.value;

    if (!current) {
        return true;
    }

    const r = await swal.fire({
        icon: 'warning',
        title: `¿Sustituir el ${optional.value ? 'archivo 2' : 'archivo 1'}?`,
        html: `<p>Vas a sustituir <b>«${escapeHtml(current.name)}»</b> (${current.movements} movimiento(s)) por <b>«${escapeHtml(file.name)}»</b> en «${escapeHtml(props.accountLabel)}».</p><p style="margin-top:8px">Sólo cambia este archivo; el otro archivo de la cuenta se queda como está. El anterior queda en el historial de cargas.</p>`,
        showCancelButton: true,
        confirmButtonText: 'Sí, sustituir archivo',
        cancelButtonText: 'Conservar el actual',
        focusCancel: true,
    });

    return r.isConfirmed;
}

async function pick(files: FileList | null | undefined): Promise<void> {
    const file = files?.[0];

    if (input.value) {
        input.value.value = '';
    }

    if (file && (await confirmReplace(file))) {
        emit('upload', file);
    }
}

function onDrop(e: DragEvent): void {
    dragging.value = false;

    if (props.canUpload && !props.lockReason && !disabled.value) {
        pick(e.dataTransfer?.files);
    }
}

async function askRemove(): Promise<void> {
    const current = upload.value;

    if (!current || disabled.value) {
        return;
    }

    const r = await swal.fire({
        icon: 'warning',
        title: '¿Quitar el archivo 2?',
        html: `<p>Se quitará <b>«${escapeHtml(current.name)}»</b> (${current.movements} movimiento(s)) de «${escapeHtml(props.accountLabel)}».</p><p style="margin-top:8px">Sus movimientos dejan de contar en el reporte; si alguno también viene en el archivo 1, se sigue contando una vez. El archivo queda en el historial de cargas.</p>`,
        showCancelButton: true,
        confirmButtonText: 'Sí, quitar archivo 2',
        cancelButtonText: 'Conservarlo',
        focusCancel: true,
    });

    if (r.isConfirmed) {
        emit('remove');
    }
}
</script>

<template>
    <div
        class="space-y-2 rounded-xl border p-3"
        :class="
            !upload && !optional
                ? 'border-rose-200 bg-rose-50/40 dark:border-rose-900 dark:bg-rose-950/20'
                : 'border-border bg-muted/20'
        "
    >
        <div class="flex items-center justify-between gap-2">
            <p class="text-xs font-bold">
                {{ file.label }}
            </p>
            <span
                class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold"
                :class="
                    upload
                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300'
                        : optional
                          ? 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300'
                          : 'bg-rose-100 text-rose-700 dark:bg-rose-950/60 dark:text-rose-300'
                "
            >
                {{
                    upload
                        ? 'Cargado'
                        : optional
                          ? 'Opcional · sin archivo'
                          : 'Falta'
                }}
            </span>
        </div>

        <div v-if="upload" class="space-y-2 text-xs">
            <p class="truncate font-medium" :title="upload.name">
                {{ upload.name }}
            </p>
            <FileLifecycle
                v-if="upload.lifecycle"
                :lifecycle="upload.lifecycle"
                :label="upload.lifecycle_label"
            />
            <div class="grid grid-cols-3 gap-2">
                <div class="rounded-lg bg-muted/50 p-2">
                    <p class="text-[11px] text-muted-foreground">Movimientos</p>
                    <p class="font-semibold tabular-nums">
                        {{ upload.movements }}
                    </p>
                </div>
                <div class="rounded-lg bg-muted/50 p-2">
                    <p class="text-[11px] text-muted-foreground">Cargos</p>
                    <p class="font-semibold tabular-nums">
                        {{ mxn(upload.total_cargos) }}
                    </p>
                </div>
                <div class="rounded-lg bg-muted/50 p-2">
                    <p class="text-[11px] text-muted-foreground">Abonos</p>
                    <p class="font-semibold tabular-nums">
                        {{ mxn(upload.total_abonos) }}
                    </p>
                </div>
            </div>
            <p
                v-if="upload.control_ok === true"
                class="flex items-center gap-1 text-emerald-700 dark:text-emerald-400"
            >
                <CheckCircle2 class="size-3.5" /> Totales cuadran con el banco
                ("TOTAL EXCEL")
            </p>
            <p
                v-else-if="upload.control_ok === false"
                class="flex items-center gap-1 font-semibold text-rose-600"
            >
                <AlertTriangle class="size-3.5" /> Los totales NO cuadran con
                los totales de control del archivo
            </p>
            <p class="text-muted-foreground">
                {{ upload.first_date }} a {{ upload.last_date }} ·
                {{ upload.uploaded_by ?? '—' }} · {{ upload.uploaded_at }}
            </p>
            <p
                v-if="(pendingReview ?? 0) > 0"
                class="flex items-center gap-1 font-medium text-amber-700 dark:text-amber-400"
            >
                <AlertTriangle class="size-3.5" />
                {{ pendingReview }} movimiento(s) de la cuenta requieren
                revisión
            </p>
            <ul v-if="upload.warnings_detail?.length" class="space-y-1">
                <li
                    v-for="(w, i) in upload.warnings_detail"
                    :key="i"
                    class="flex gap-1.5 rounded-lg p-1.5"
                    :class="
                        w.severity === 'bloqueante'
                            ? 'bg-rose-50 font-semibold text-rose-800 dark:bg-rose-950/30 dark:text-rose-300'
                            : 'bg-muted/60 text-muted-foreground'
                    "
                >
                    <span
                        class="shrink-0 rounded px-1 text-[10px] font-bold"
                        :class="
                            w.severity === 'bloqueante'
                                ? 'bg-rose-600 text-white'
                                : 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200'
                        "
                        >{{
                            w.severity === 'bloqueante' ? 'Bloquea' : 'Info'
                        }}</span
                    >
                    {{ w.message }}
                </li>
            </ul>
            <ul
                v-else-if="upload.warnings.length"
                class="space-y-1 rounded-xl border border-amber-200 bg-amber-50/70 p-2 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
            >
                <li v-for="(w, i) in upload.warnings" :key="i">· {{ w }}</li>
            </ul>
        </div>
        <p v-else-if="optional" class="text-xs text-muted-foreground">
            Úsalo sólo si el estado de cuenta del mes viene en dos archivos. No
            es obligatorio y no detiene la aprobación. Si un movimiento viene en
            los dos archivos, se cuenta una sola vez.
        </p>

        <p
            v-if="canUpload && lockReason"
            class="flex items-start gap-2 rounded-xl border border-dashed p-3 text-xs text-muted-foreground"
        >
            <Lock class="mt-0.5 size-3.5 shrink-0" />
            No se pueden cargar archivos: {{ lockReason }}
        </p>
        <template v-else-if="canUpload">
            <label
                class="flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed p-3 text-center text-xs transition focus-within:ring-3 focus-within:ring-emerald-500/30"
                :class="[
                    dragging
                        ? 'border-emerald-500 bg-emerald-50/70 dark:bg-emerald-950/30'
                        : 'border-border hover:border-emerald-400 hover:bg-emerald-50/40 dark:hover:bg-emerald-950/20',
                    busy && !uploading ? 'pointer-events-none opacity-60' : '',
                ]"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <Loader2
                    v-if="uploading"
                    class="size-5 animate-spin text-emerald-600"
                />
                <UploadCloud v-else class="size-5 text-emerald-600" />
                <span class="font-semibold">{{
                    uploading
                        ? 'Trabajando con el archivo…'
                        : upload
                          ? 'Sustituir este archivo (te pediremos confirmar)'
                          : 'Arrastra el Excel aquí o haz clic para elegirlo'
                }}</span>
                <span class="text-muted-foreground"
                    >{{
                        uploadRules
                            ? uploadRules.extensions
                                  .map((x) => '.' + x)
                                  .join(' / ')
                            : '.xlsx / .xls / .xlsm'
                    }}<template v-if="uploadRules">
                        · máximo {{ uploadRules.max_mb }} MB</template
                    >
                    · se valida completo antes de guardar</span
                >
                <span
                    v-if="!uploading"
                    class="mt-1 inline-flex items-center gap-1.5 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm"
                    aria-hidden="true"
                >
                    <UploadCloud class="size-3.5" />
                    {{
                        upload
                            ? 'Elegir archivo nuevo'
                            : optional
                              ? 'Subir archivo 2'
                              : 'Subir estado de cuenta'
                    }}
                </span>
                <input
                    :id="inputId"
                    ref="input"
                    type="file"
                    class="sr-only"
                    :accept="uploadRules?.accept ?? '.xlsx,.xls,.xlsm'"
                    :disabled="disabled"
                    @change="pick(($event.target as HTMLInputElement).files)"
                />
            </label>
            <button
                v-if="optional && upload"
                type="button"
                class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-rose-200 px-3 py-1.5 text-xs font-bold text-rose-700 transition hover:bg-rose-50 disabled:opacity-60 dark:border-rose-900 dark:text-rose-300 dark:hover:bg-rose-950/30"
                :disabled="disabled"
                @click="askRemove"
            >
                <Trash2 class="size-3.5" /> Quitar archivo 2
            </button>
        </template>
    </div>
</template>
