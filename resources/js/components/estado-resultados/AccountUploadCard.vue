<script setup lang="ts">
import { ChevronDown, FileSpreadsheet, History } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import AccountFilePart from './AccountFilePart.vue';
import StatusBadge from './StatusBadge.vue';
import type {
    AccountFilePart as AccountFilePartData,
    AccountSource,
    UploadRules,
} from './types';

const props = defineProps<{
    account: AccountSource;
    canUpload: boolean;
    /** Archivo de esta cuenta que se está cargando o quitando (1 | 2) o null. */
    uploadingPart: number | null;
    uploadRules?: UploadRules | null;
    /** Motivo de solo lectura (versión aprobada / histórica). */
    lockReason?: string | null;
}>();

const emit = defineEmits<{
    upload: [slot: string, file: File, part: number];
    remove: [slot: string, part: number];
}>();

const showHistory = ref(false);

/** Archivo 1 (obligatorio) y archivo 2 (opcional). Datasets viejos sólo traen `upload`. */
const parts = computed<AccountFilePartData[]>(
    () =>
        props.account.parts ?? [
            {
                part: 1,
                label: 'Archivo 1 (obligatorio)',
                required: true,
                status: props.account.status,
                upload: props.account.upload,
            },
            {
                part: 2,
                label: 'Archivo 2 (opcional)',
                required: false,
                status: 'opcional',
                upload: null,
            },
        ],
);

/** Estados crudos del historial en español claro. */
const STATUS_LABEL: Record<string, string> = {
    reemplazado: 'Sustituido o quitado',
    activo: 'Vigente',
    vigente: 'Vigente',
    procesado: 'Procesado',
    cargado: 'Cargado',
    rechazado: 'Rechazado',
};
const statusLabel = (s: string) =>
    STATUS_LABEL[s] ??
    s.charAt(0).toUpperCase() + s.slice(1).replace(/_/g, ' ');
</script>

<template>
    <div
        :id="`er-slot-${account.key}`"
        class="group relative flex scroll-mt-24 flex-col overflow-hidden rounded-2xl border bg-card shadow-soft-xs transition hover:shadow-soft-md"
        :class="
            account.status === 'pendiente'
                ? 'border-rose-300 ring-1 ring-rose-200 dark:border-rose-900 dark:ring-rose-950'
                : ''
        "
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
                        <p
                            class="truncate text-sm font-semibold md:line-clamp-2 md:whitespace-normal"
                            :title="account.label"
                        >
                            {{ account.label }}
                        </p>
                        <p
                            class="truncate text-xs text-muted-foreground md:line-clamp-2 md:whitespace-normal"
                            :title="
                                account.upload?.account ??
                                'Estado de cuenta complementario'
                            "
                        >
                            {{
                                account.upload?.account ??
                                'Estado de cuenta complementario'
                            }}
                        </p>
                    </div>
                </div>
                <StatusBadge :status="account.status" />
            </div>

            <AccountFilePart
                v-for="p in parts"
                :key="p.part"
                :slot-key="account.key"
                :account-label="account.label"
                :file="p"
                :can-upload="canUpload"
                :uploading="uploadingPart === p.part"
                :busy="uploadingPart !== null && uploadingPart !== p.part"
                :upload-rules="uploadRules"
                :lock-reason="lockReason"
                :pending-review="p.part === 1 ? account.pending_review : 0"
                @upload="(file) => emit('upload', account.key, file, p.part)"
                @remove="emit('remove', account.key, p.part)"
            />

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
                            <span
                                class="truncate"
                                :title="`${h.name} · huella del archivo ${h.hash}`"
                                ><b class="font-semibold"
                                    >Archivo {{ h.part ?? 1 }}</b
                                >
                                · {{ h.name }} · {{ h.movements }} mov.</span
                            >
                            <span
                                class="shrink-0 font-semibold"
                                :class="
                                    h.status === 'reemplazado'
                                        ? 'text-muted-foreground line-through'
                                        : 'text-emerald-700 dark:text-emerald-400'
                                "
                                >{{ statusLabel(h.status) }}</span
                            >
                        </li>
                    </ul>
                </CollapsibleContent>
            </Collapsible>
        </div>
    </div>
</template>
