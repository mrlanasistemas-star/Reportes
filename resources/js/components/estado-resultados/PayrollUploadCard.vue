<script setup lang="ts">
import {
    AlertTriangle,
    ChevronDown,
    History,
    Info,
    Loader2,
    Lock,
    UploadCloud,
    Users,
} from 'lucide-vue-next';
import { ref } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { swal } from '@/composables/estado-resultados/erSwal';
import StatusBadge from './StatusBadge.vue';
import { escapeHtml, mxn } from './types';
import type { CorporatePayrollSource, UploadRules } from './types';

/**
 * NOI de corporativo: fuente PROPIA del Estado de Resultados (no se sube en Carga de
 * archivos). Mismo formato que el NOI de sucursales; sólo inserta este archivo.
 */
const props = defineProps<{
    source: CorporatePayrollSource;
    canUpload: boolean;
    uploading: boolean;
    uploadRules?: UploadRules | null;
    lockReason?: string | null;
}>();

const emit = defineEmits<{ upload: [slot: string, file: File] }>();

const dragging = ref(false);
const input = ref<HTMLInputElement | null>(null);
const showHistory = ref(false);
const showRule = ref(false);

async function pick(files: FileList | null | undefined): Promise<void> {
    const file = files?.[0];

    if (input.value) {
        input.value.value = '';
    }

    if (!file) {
        return;
    }

    const current = props.source.upload;

    if (current) {
        const r = await swal.fire({
            icon: 'warning',
            title: '¿Sustituir el NOI de corporativo?',
            html: `<p>Vas a sustituir <b>«${escapeHtml(current.name)}»</b> (${current.employees} colaborador(es)) por <b>«${escapeHtml(file.name)}»</b>.</p><p style="margin-top:8px">Sólo cambian los renglones del NOI de corporativo; los estados de cuenta y lo de Carga de archivos no se tocan.</p>`,
            showCancelButton: true,
            confirmButtonText: 'Sí, sustituir',
            cancelButtonText: 'Conservar el actual',
            focusCancel: true,
        });

        if (!r.isConfirmed) {
            return;
        }
    }

    emit('upload', 'noi_corporativo', file);
}

function onDrop(e: DragEvent): void {
    dragging.value = false;

    if (props.canUpload && !props.lockReason && !props.uploading) {
        pick(e.dataTransfer?.files);
    }
}
</script>

<template>
    <div
        id="er-slot-noi_corporativo"
        class="group relative flex scroll-mt-24 flex-col overflow-hidden rounded-2xl border bg-card shadow-soft-xs transition hover:shadow-soft-md"
        :class="
            !source.upload
                ? 'border-amber-300 ring-1 ring-amber-200 dark:border-amber-900 dark:ring-amber-950'
                : ''
        "
    >
        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2.5">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300"
                    >
                        <Users class="size-4.5" />
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">NOI de corporativo</p>
                        <p class="text-xs text-muted-foreground">
                            Nómina del personal de Corporativo ·
                            {{
                                source.required ? 'obligatoria' : 'recomendada'
                            }}
                        </p>
                    </div>
                </div>
                <StatusBadge :status="source.status" />
            </div>

            <div v-if="source.upload" class="space-y-2 text-xs">
                <p class="truncate font-medium" :title="source.upload.name">
                    {{ source.upload.name }}
                </p>
                <div class="grid grid-cols-3 gap-2">
                    <div class="rounded-xl bg-muted/50 p-2">
                        <p class="text-[11px] text-muted-foreground">
                            Colaboradores
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ source.upload.employees }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-muted/50 p-2">
                        <p class="text-[11px] text-muted-foreground">
                            Percepciones
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ mxn(source.upload.total_percepciones) }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-muted/50 p-2">
                        <p class="text-[11px] text-muted-foreground">
                            Suma a nómina corp.
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ mxn(source.recognized) }}
                        </p>
                    </div>
                </div>
                <p
                    v-if="source.overlap.count > 0"
                    class="flex items-start gap-1 text-amber-800 dark:text-amber-300"
                >
                    <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                    {{ source.overlap.count }} colaborador(es) ({{
                        mxn(source.overlap.amount)
                    }}) ya están en el NOI de sucursales: no se suman otra vez.
                </p>
                <ul
                    v-if="source.upload.warnings.length"
                    class="space-y-1 rounded-xl bg-muted/60 p-2 text-muted-foreground"
                >
                    <li v-for="(w, i) in source.upload.warnings" :key="i">
                        · {{ w }}
                    </li>
                </ul>
                <p class="text-muted-foreground">
                    {{ source.upload.uploaded_by ?? '—' }} ·
                    {{ source.upload.uploaded_at }}
                </p>
            </div>
            <p v-else class="text-xs text-muted-foreground">
                Sin este archivo, la nómina corporativa sólo incluye lo que
                llega por ERP o bancos ({{ mxn(source.other_sources_amount) }}).
                Usa el mismo reporte de nómina del NOI que el de sucursales.
            </p>

            <Collapsible v-model:open="showRule">
                <CollapsibleTrigger
                    class="flex items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground"
                >
                    <Info class="size-3.5" /> Cómo se evita contarla dos veces
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ source.rule }}
                    </p>
                </CollapsibleContent>
            </Collapsible>

            <p
                v-if="canUpload && lockReason"
                class="mt-auto flex items-start gap-2 rounded-xl border border-dashed p-3 text-xs text-muted-foreground"
            >
                <Lock class="mt-0.5 size-3.5 shrink-0" />
                No se pueden cargar archivos: {{ lockReason }}
            </p>
            <label
                v-else-if="canUpload"
                class="mt-auto flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed p-4 text-center text-xs transition focus-within:ring-3 focus-within:ring-sky-500/30"
                :class="
                    dragging
                        ? 'border-sky-500 bg-sky-50/70 dark:bg-sky-950/30'
                        : 'border-border hover:border-sky-400 hover:bg-sky-50/40 dark:hover:bg-sky-950/20'
                "
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <Loader2
                    v-if="uploading"
                    class="size-5 animate-spin text-sky-600"
                />
                <span class="font-semibold">{{
                    uploading
                        ? 'Validando NOI…'
                        : 'Arrastra el Excel del NOI aquí o elígelo'
                }}</span>
                <span
                    v-if="!uploading"
                    class="mt-1 inline-flex items-center gap-1.5 rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-bold text-white shadow-sm transition group-hover:bg-sky-700"
                    aria-hidden="true"
                >
                    <UploadCloud class="size-3.5" />
                    {{
                        source.upload
                            ? 'Elegir NOI nuevo'
                            : 'Subir NOI de corporativo'
                    }}
                </span>
                <span class="text-muted-foreground"
                    >{{
                        uploadRules
                            ? uploadRules.extensions
                                  .map((x) => '.' + x)
                                  .join(' / ')
                            : '.xlsx / .xls / .xlsm'
                    }}
                    · sólo se agrega este archivo</span
                >
                <input
                    id="er-slot-input-noi_corporativo"
                    ref="input"
                    type="file"
                    class="sr-only"
                    :accept="uploadRules?.accept ?? '.xlsx,.xls,.xlsm'"
                    :disabled="uploading"
                    @change="pick(($event.target as HTMLInputElement).files)"
                />
            </label>

            <Collapsible
                v-if="source.history.length"
                v-model:open="showHistory"
            >
                <CollapsibleTrigger
                    class="flex w-full items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground"
                >
                    <History class="size-3.5" /> Historial de cargas ({{
                        source.history.length
                    }})
                    <ChevronDown
                        class="ml-auto size-3.5 transition"
                        :class="showHistory ? 'rotate-180' : ''"
                    />
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <ul class="mt-2 space-y-1 text-[11px]">
                        <li
                            v-for="h in source.history"
                            :key="h.id"
                            class="flex items-center justify-between gap-2 rounded-lg bg-muted/40 px-2 py-1"
                        >
                            <span class="truncate" :title="h.name"
                                >{{ h.name }} · {{ h.movements }} colab.</span
                            >
                            <span
                                class="shrink-0 font-semibold"
                                :class="
                                    h.status === 'reemplazado'
                                        ? 'text-muted-foreground line-through'
                                        : 'text-emerald-700 dark:text-emerald-400'
                                "
                                >{{
                                    h.status === 'reemplazado'
                                        ? 'Sustituido'
                                        : 'Vigente'
                                }}</span
                            >
                        </li>
                    </ul>
                </CollapsibleContent>
            </Collapsible>
        </div>
    </div>
</template>
