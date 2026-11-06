<script setup lang="ts">
import {
    AlertTriangle,
    CheckCircle2,
    ChevronDown,
    FileSpreadsheet,
    History,
    Loader2,
    Lock,
    UploadCloud,
} from 'lucide-vue-next';
import { ref } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import StatusBadge from './StatusBadge.vue';
import { mxn } from './types';
import type { AccountSource, UploadRules } from './types';

const props = defineProps<{
    account: AccountSource;
    canUpload: boolean;
    uploading: boolean;
    uploadRules?: UploadRules | null;
    /** Motivo de solo lectura (versión aprobada / histórica). */
    lockReason?: string | null;
}>();

const emit = defineEmits<{ upload: [slot: string, file: File] }>();

const dragging = ref(false);
const input = ref<HTMLInputElement | null>(null);
const showHistory = ref(false);

function pick(files: FileList | null | undefined): void {
    const file = files?.[0];

    if (file) {
        emit('upload', props.account.key, file);
    }

    if (input.value) {
        input.value.value = '';
    }
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
        class="group relative flex flex-col overflow-hidden rounded-2xl border bg-card shadow-soft-xs transition hover:shadow-soft-md"
    >
        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2.5">
                    <div
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300"
                    >
                        <FileSpreadsheet class="size-4.5" />
                    </div>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold">
                            {{ account.label }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{
                                account.upload?.account ??
                                'Estado de cuenta complementario'
                            }}
                        </p>
                    </div>
                </div>
                <StatusBadge :status="account.status" />
            </div>

            <div v-if="account.upload" class="space-y-2 text-xs">
                <p class="truncate font-medium" :title="account.upload.name">
                    {{ account.upload.name }}
                </p>
                <div class="grid grid-cols-3 gap-2">
                    <div class="rounded-xl bg-muted/50 p-2">
                        <p class="text-[11px] text-muted-foreground">
                            Movimientos
                        </p>
                        <p class="font-semibold tabular-nums">
                            {{ account.upload.movements }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-muted/50 p-2">
                        <p class="text-[11px] text-muted-foreground">Cargos</p>
                        <p class="font-semibold tabular-nums">
                            {{ mxn(account.upload.total_cargos) }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-muted/50 p-2">
                        <p class="text-[11px] text-muted-foreground">Abonos</p>
                        <p class="font-semibold tabular-nums">
                            {{ mxn(account.upload.total_abonos) }}
                        </p>
                    </div>
                </div>
                <p
                    v-if="account.upload.control_ok === true"
                    class="flex items-center gap-1 text-emerald-700 dark:text-emerald-400"
                >
                    <CheckCircle2 class="size-3.5" /> Totales cuadran con el
                    banco ("TOTAL EXCEL")
                </p>
                <p
                    v-else-if="account.upload.control_ok === false"
                    class="flex items-center gap-1 font-semibold text-rose-600"
                >
                    <AlertTriangle class="size-3.5" /> Los totales NO cuadran
                    con los totales de control del archivo
                </p>
                <p class="text-muted-foreground">
                    {{ account.upload.first_date }} a
                    {{ account.upload.last_date }} ·
                    {{ account.upload.uploaded_by ?? '—' }} ·
                    {{ account.upload.uploaded_at }}
                </p>
                <p
                    v-if="account.pending_review > 0"
                    class="flex items-center gap-1 font-medium text-amber-700 dark:text-amber-400"
                >
                    <AlertTriangle class="size-3.5" />
                    {{ account.pending_review }} movimiento(s) requieren
                    revisión
                </p>
                <ul
                    v-if="account.upload.warnings.length"
                    class="space-y-1 rounded-xl border border-amber-200 bg-amber-50/70 p-2 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200"
                >
                    <li v-for="(w, i) in account.upload.warnings" :key="i">
                        · {{ w }}
                    </li>
                </ul>
            </div>

            <p
                v-if="canUpload && lockReason"
                class="mt-auto flex items-start gap-2 rounded-xl border border-dashed p-3 text-xs text-muted-foreground"
            >
                <Lock class="mt-0.5 size-3.5 shrink-0" />
                No se pueden cargar archivos: {{ lockReason }}
            </p>
            <label
                v-else-if="canUpload"
                class="mt-auto flex cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed p-4 text-center text-xs transition focus-within:ring-3 focus-within:ring-emerald-500/30"
                :class="
                    dragging
                        ? 'border-emerald-500 bg-emerald-50/70 dark:bg-emerald-950/30'
                        : 'border-border hover:border-emerald-400 hover:bg-emerald-50/40 dark:hover:bg-emerald-950/20'
                "
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <Loader2
                    v-if="uploading"
                    class="size-5 animate-spin text-emerald-600"
                />
                <UploadCloud
                    v-else
                    class="size-5 text-emerald-600 transition group-hover:-translate-y-0.5"
                />
                <span class="font-semibold">{{
                    uploading
                        ? 'Validando archivo…'
                        : account.upload
                          ? 'Reemplazar archivo'
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
                <input
                    ref="input"
                    type="file"
                    class="sr-only"
                    :accept="uploadRules?.accept ?? '.xlsx,.xls,.xlsm'"
                    :disabled="uploading"
                    @change="pick(($event.target as HTMLInputElement).files)"
                />
            </label>

            <Collapsible
                v-if="account.history.length"
                v-model:open="showHistory"
            >
                <CollapsibleTrigger
                    class="flex w-full items-center gap-1 text-xs font-medium text-muted-foreground hover:text-foreground"
                >
                    <History class="size-3.5" /> Historial de cargas ({{
                        account.history.length
                    }})
                    <ChevronDown
                        class="ml-auto size-3.5 transition"
                        :class="showHistory ? 'rotate-180' : ''"
                    />
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <ul class="mt-2 space-y-1 text-[11px]">
                        <li
                            v-for="h in account.history"
                            :key="h.id"
                            class="flex items-center justify-between gap-2 rounded-lg bg-muted/40 px-2 py-1"
                        >
                            <span class="truncate"
                                >{{ h.name }} · {{ h.movements }} mov. · sha
                                {{ h.hash }}</span
                            >
                            <span
                                class="shrink-0 font-semibold"
                                :class="
                                    h.status === 'reemplazado'
                                        ? 'text-muted-foreground line-through'
                                        : 'text-emerald-700 dark:text-emerald-400'
                                "
                                >{{ h.status }}</span
                            >
                        </li>
                    </ul>
                </CollapsibleContent>
            </Collapsible>
        </div>
    </div>
</template>
