<script setup lang="ts">
import {
    CalendarRange,
    ChevronDown,
    FileSpreadsheet,
    FileText,
    Fingerprint,
    Loader2,
    Lock,
    MapPin,
    RefreshCw,
} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import ReportStatusPill from './ReportStatusPill.vue';
import type { Dataset, Permissions } from './types';

defineProps<{
    periods: Array<{ id: number; code: string; label: string }>;
    scopes: Array<{ key: string; label: string }>;
    dataset: Dataset | null;
    loading: boolean;
    processing: boolean;
    can: Permissions;
    complete: boolean;
    blockers: string[];
    lockReason?: string | null;
}>();

const periodId = defineModel<number>('periodId', { required: true });
const scope = defineModel<string>('scope', { required: true });

defineEmits<{ process: []; export: [kind: 'excel' | 'pdf'] }>();

const selectCls =
    'peer h-11 w-full cursor-pointer appearance-none rounded-xl border border-transparent bg-transparent pr-9 pl-10 text-sm font-semibold text-foreground transition outline-none hover:bg-muted/60 focus-visible:border-emerald-500 focus-visible:ring-3 focus-visible:ring-emerald-500/20';
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-2 rounded-2xl border bg-card p-1.5 shadow-soft-xs"
    >
        <div
            class="flex max-w-full flex-[1_1_26rem] flex-col gap-1 sm:flex-row"
        >
            <label
                class="relative block min-w-0 sm:min-w-[19rem] sm:flex-[1.4]"
            >
                <span class="sr-only">Periodo</span>
                <CalendarRange
                    class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-emerald-700 dark:text-emerald-400"
                />
                <select v-model.number="periodId" :class="selectCls">
                    <option v-for="p in periods" :key="p.id" :value="p.id">
                        {{ p.label }} · {{ p.code }}
                    </option>
                </select>
                <ChevronDown
                    class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
            </label>
            <label class="relative block min-w-0 sm:min-w-[14rem] sm:flex-1">
                <span class="sr-only">Alcance</span>
                <MapPin
                    class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-cyan-600 dark:text-cyan-400"
                />
                <select v-model="scope" :class="selectCls">
                    <option v-for="s in scopes" :key="s.key" :value="s.key">
                        {{ s.label }}
                    </option>
                </select>
                <ChevronDown
                    class="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted-foreground"
                />
            </label>
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
            <span
                v-if="dataset"
                class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                :title="`Huella del dataset: ${dataset.meta.dataset_hash}. Es la misma en la web, el Excel y el PDF.`"
            >
                <Fingerprint class="size-3.5" />
                <span class="tabular-nums">{{
                    dataset.meta.dataset_hash.slice(0, 8)
                }}</span>
                <span class="hidden 2xl:inline"
                    >· calculado {{ dataset.meta.generated_at }}</span
                >
            </span>
            <Loader2
                v-if="loading"
                class="size-4 animate-spin text-emerald-600"
                aria-label="Recalculando"
            />
        </div>

        <div class="ml-auto flex flex-wrap items-center gap-1.5 px-1">
            <span
                v-if="can.procesar"
                class="inline-flex"
                :title="lockReason ?? undefined"
            >
                <Button
                    class="h-10 rounded-xl bg-emerald-700 px-4 text-white shadow-[0_6px_16px_-8px_rgb(4_120_87/0.8)] transition hover:-translate-y-px hover:bg-emerald-800 active:translate-y-0 dark:bg-emerald-500 dark:text-emerald-950 dark:hover:bg-emerald-400"
                    :disabled="
                        processing || loading || !dataset || !!lockReason
                    "
                    @click="$emit('process')"
                >
                    <Loader2 v-if="processing" class="animate-spin" />
                    <Lock v-else-if="lockReason" />
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
