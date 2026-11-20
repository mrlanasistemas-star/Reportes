<script setup lang="ts">
import {
    AlertTriangle,
    Download,
    FileSpreadsheet,
    History,
    Loader2,
    Upload,
} from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { PERIODICITY_LABEL, dmy } from './financialExpenses';
import type { CreditPeriod } from './financialExpenses';
import { mxn } from './types';

const props = defineProps<{
    credit: CreditPeriod;
    accept: string;
    canUpload: boolean;
    uploading: boolean;
}>();

const emit = defineEmits<{
    upload: [file: File];
    template: [];
}>();

const input = ref<HTMLInputElement | null>(null);
const showHistory = ref(false);

function pick(): void {
    input.value?.click();
}

function onFile(e: Event): void {
    const el = e.target as HTMLInputElement;
    const file = el.files?.[0];

    if (file) {
        emit('upload', file);
    }

    el.value = '';
}

const up = () => props.credit.upload;
</script>

<template>
    <div class="space-y-3 rounded-2xl border bg-card p-4 shadow-soft-xs">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="flex items-center gap-2 text-sm font-extrabold">
                    <FileSpreadsheet
                        class="size-4 text-emerald-600 dark:text-emerald-400"
                    />
                    {{ credit.credit.name }}
                </p>
                <p class="text-xs text-muted-foreground">
                    {{
                        PERIODICITY_LABEL[credit.credit.periodicity] ??
                        credit.credit.periodicity
                    }}
                    · alimenta «{{ credit.credit.concept_label }}»
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8"
                    @click="emit('template')"
                >
                    <Download class="size-3.5" /> Plantilla
                </Button>
                <Button
                    v-if="canUpload"
                    size="sm"
                    class="h-8 bg-emerald-700 text-white hover:bg-emerald-800"
                    :disabled="uploading"
                    @click="pick"
                >
                    <Loader2 v-if="uploading" class="size-3.5 animate-spin" />
                    <Upload v-else class="size-3.5" />
                    {{ up() ? 'Sustituir tabla' : 'Subir tabla' }}
                </Button>
                <input
                    ref="input"
                    type="file"
                    class="hidden"
                    :accept="accept"
                    :aria-label="`Tabla de amortización de ${credit.credit.name}`"
                    @change="onFile"
                />
            </div>
        </div>

        <div
            v-if="!up()"
            class="rounded-xl border border-dashed border-amber-300 bg-amber-50/60 p-3 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200"
        >
            Aún no hay tabla de amortización. Mientras no la subas (o captures
            el interés en Ajustes), el interés queda <b>sin información</b>: no
            se asume $0.
        </div>
        <dl
            v-else
            class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4"
        >
            <div class="col-span-2">
                <dt class="text-muted-foreground">Tabla vigente</dt>
                <dd class="truncate font-semibold" :title="up()!.name">
                    {{ up()!.name }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Pagos</dt>
                <dd class="font-semibold tabular-nums">{{ up()!.rows }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Vigencia</dt>
                <dd class="font-semibold tabular-nums">
                    {{ dmy(up()!.first_date) }} – {{ dmy(up()!.last_date) }}
                </dd>
            </div>
            <div class="col-span-2 sm:col-span-4">
                <dt class="sr-only">Carga</dt>
                <dd class="text-[11px] text-muted-foreground">
                    Subida por {{ up()!.uploaded_by ?? '—' }} ·
                    {{ up()!.uploaded_at }} · huella {{ up()!.hash }} · interés
                    total de la tabla {{ mxn(up()!.total_interest) }}
                </dd>
            </div>
        </dl>

        <p
            v-if="up()?.warnings.length"
            class="flex items-start gap-1.5 text-[11px] font-medium text-amber-800 dark:text-amber-300"
        >
            <AlertTriangle class="mt-px size-3.5 shrink-0" />
            {{ up()!.warnings.join(' ') }}
        </p>

        <Collapsible
            v-if="credit.history.length > 1"
            v-model:open="showHistory"
        >
            <CollapsibleTrigger
                class="flex items-center gap-1.5 text-xs font-semibold text-emerald-700 hover:underline dark:text-emerald-400"
            >
                <History class="size-3.5" /> Bitácora de cargas ({{
                    credit.history.length
                }})
            </CollapsibleTrigger>
            <CollapsibleContent>
                <ul class="mt-2 space-y-1 text-[11px]">
                    <li
                        v-for="h in credit.history"
                        :key="h.id"
                        class="flex flex-wrap gap-x-2 rounded-lg bg-muted/40 px-2 py-1"
                    >
                        <span class="font-semibold">{{ h.name }}</span>
                        <span>{{ h.rows }} pagos</span>
                        <span
                            >{{ h.uploaded_by ?? '—' }} ·
                            {{ h.uploaded_at }}</span
                        >
                        <span
                            :class="
                                h.status === 'vigente'
                                    ? 'font-bold text-emerald-700 dark:text-emerald-400'
                                    : 'text-muted-foreground'
                            "
                            >{{
                                h.status === 'vigente'
                                    ? 'Vigente'
                                    : `Sustituida ${h.superseded_at ?? ''}`
                            }}</span
                        >
                    </li>
                </ul>
            </CollapsibleContent>
        </Collapsible>
    </div>
</template>
