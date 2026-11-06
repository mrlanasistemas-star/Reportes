<script setup lang="ts">
import {
    Clock3,
    FileSpreadsheet,
    FileText,
    Loader2,
    Lock,
    RefreshCw,
} from 'lucide-vue-next';
import { computed, reactive, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import ErPeriodPicker from './ErPeriodPicker.vue';
import type { PeriodStatus } from './ErPeriodPicker.vue';
import ErScopePicker from './ErScopePicker.vue';
import ReportStatusPill from './ReportStatusPill.vue';
import type { Dataset, Permissions } from './types';

const props = defineProps<{
    periods: Array<{
        id: number;
        code: string;
        label: string;
        start?: string;
        end?: string;
    }>;
    scopes: Array<{ key: string; label: string }>;
    dataset: Dataset | null;
    loading: boolean;
    processing: boolean;
    can: Permissions;
    complete: boolean;
    blockers: string[];
    lockReason?: string | null;
    /** Versión aprobada o sustituida (congelada): no se procesa. */
    snapshot?: boolean;
}>();

const periodId = defineModel<number>('periodId', { required: true });
const scope = defineModel<string>('scope', { required: true });

defineEmits<{ process: []; export: [kind: 'excel' | 'pdf'] }>();

/** Estados de cuenta cargados: sin ninguno no hay nada que conciliar. */
const accounts = computed(() => {
    const list = props.dataset?.sources.cuentas ?? [];

    return {
        loaded: list.filter((c) => !!c.upload).length,
        total: list.length,
    };
});
/** Motivo por el que no se puede procesar (null = se puede). */
const processBlock = computed<string | null>(() => {
    if (props.lockReason) {
        return props.lockReason;
    }

    if (props.dataset && accounts.value.total && !accounts.value.loaded) {
        return `Primero carga los estados de cuenta (0 de ${accounts.value.total}) en la pestaña Fuentes.`;
    }

    return null;
});

/**
 * Estado de cada periodo ya consultado en esta pestaña (sale del dataset que llega; no se
 * pide nada extra al servidor). Solo se toma de la versión vigente, no de una consultada.
 */
const statuses = reactive<Record<number, PeriodStatus>>({});
watch(
    () => props.dataset?.meta,
    (m) => {
        const r = m?.report;

        if (m && r && r.current !== false) {
            statuses[m.period.id] = {
                status: r.status,
                label: r.status_label,
                preliminary: !!r.preliminary,
            };
        }
    },
    { immediate: true },
);
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-2 rounded-2xl border bg-card p-1.5 shadow-soft-xs"
    >
        <div
            class="flex max-w-full flex-[1_1_24rem] flex-col gap-1.5 sm:flex-row"
        >
            <ErPeriodPicker
                v-model="periodId"
                class="min-w-0 sm:min-w-[17rem] sm:flex-[1.15]"
                :periods="periods"
                :statuses="statuses"
                :loading="loading"
            />
            <ErScopePicker
                v-model="scope"
                class="min-w-0 sm:min-w-[15rem] sm:flex-1"
                :scopes="scopes"
                :loading="loading"
            />
        </div>

        <div
            class="flex min-w-0 flex-[1_1_auto] flex-wrap items-center gap-2 px-2"
        >
            <ReportStatusPill
                v-if="dataset"
                :report="dataset.meta.report ?? null"
                :complete="complete"
                :blockers="blockers"
            />
            <Tooltip v-if="dataset">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md px-1.5 py-1 text-xs text-muted-foreground transition hover:bg-muted hover:text-foreground focus-visible:ring-3 focus-visible:ring-cyan-500/40 focus-visible:outline-none"
                        aria-label="Cuándo se calcularon estas cifras"
                    >
                        <Clock3 class="size-3.5" />
                        <span class="hidden tabular-nums sm:inline"
                            >Calculado {{ dataset.meta.generated_at }}</span
                        >
                    </button>
                </TooltipTrigger>
                <TooltipContent class="max-w-xs">
                    <p>
                        Calculado el {{ dataset.meta.generated_at }}. La web, el
                        Excel y el PDF salen de los mismos datos.
                    </p>
                    <p class="mt-1 opacity-75">
                        Huella de datos:
                        <span class="tabular-nums">{{
                            dataset.meta.dataset_hash.slice(0, 12)
                        }}</span>
                    </p>
                </TooltipContent>
            </Tooltip>
            <Loader2
                v-if="loading"
                class="size-4 animate-spin text-emerald-600"
                aria-label="Recalculando"
            />
        </div>

        <div class="ml-auto flex flex-wrap items-center gap-1.5 px-1">
            <span
                v-if="can.procesar && !snapshot"
                class="inline-flex"
                :title="processBlock ?? undefined"
            >
                <Button
                    class="h-10 rounded-xl px-4 transition active:translate-y-0"
                    :class="
                        processBlock
                            ? ''
                            : 'bg-emerald-700 text-white shadow-[0_6px_16px_-8px_rgb(4_120_87/0.8)] hover:-translate-y-px hover:bg-emerald-800 dark:bg-emerald-500 dark:text-emerald-950 dark:hover:bg-emerald-400'
                    "
                    :variant="processBlock ? 'outline' : 'default'"
                    :disabled="
                        processing || loading || !dataset || !!processBlock
                    "
                    @click="$emit('process')"
                >
                    <Loader2 v-if="processing" class="animate-spin" />
                    <Lock v-else-if="processBlock" />
                    <RefreshCw v-else />
                    Procesar conciliación
                </Button>
            </span>
            <template v-if="can.exportar">
                <Button
                    variant="outline"
                    class="h-10 rounded-xl"
                    :disabled="!dataset || loading"
                    @click="$emit('export', 'excel')"
                >
                    <FileSpreadsheet class="text-emerald-600" /> Excel
                </Button>
                <Button
                    variant="outline"
                    class="h-10 rounded-xl"
                    :disabled="!dataset || loading"
                    @click="$emit('export', 'pdf')"
                >
                    <FileText class="text-rose-600" /> PDF
                </Button>
            </template>
        </div>
    </div>
</template>
