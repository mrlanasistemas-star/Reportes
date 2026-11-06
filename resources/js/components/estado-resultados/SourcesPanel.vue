<script setup lang="ts">
import { Activity, Database, ExternalLink, Users } from 'lucide-vue-next';
import { computed, nextTick } from 'vue';
import AccountUploadCard from './AccountUploadCard.vue';
import PayrollUploadCard from './PayrollUploadCard.vue';
import SourceChecklist from './SourceChecklist.vue';
import { cargaUrl } from './sourceLinks';
import StatusBadge from './StatusBadge.vue';
import type { Dataset, SimpleSource, UploadRules } from './types';
import UploadAttemptsPanel from './UploadAttemptsPanel.vue';

const props = defineProps<{
    sources: Dataset['sources'];
    canUpload: boolean;
    uploadingSlot: string | null;
    uploadRules?: UploadRules | null;
    lockReason?: string | null;
    /** URL de intentos de carga (sólo con permiso de cargar). */
    attemptsUrl?: string | null;
    refreshKey?: number;
    periodId?: number | null;
    periodLabel?: string | null;
    canProcess?: boolean;
}>();

const emit = defineEmits<{
    upload: [slot: string, file: File];
    tab: [tab: string];
    process: [];
}>();

const simple = computed<
    Array<{ src: SimpleSource; icon: typeof Database; feeds: string }>
>(() => [
    {
        src: props.sources.radiografia,
        icon: Activity,
        feeds: 'Ingresos y OPEX de sucursales (incluye su nómina)',
    },
    {
        src: props.sources.erp,
        icon: Database,
        feeds: 'Gastos corporativos; el banco se cruza contra él',
    },
    {
        src: props.sources.nomina,
        icon: Users,
        feeds: 'Nómina e IMSS por colaborador',
    },
]);

/** Frases técnicas del cálculo traducidas a lenguaje de negocio (sólo presentación). */
const PLAIN: Array<[RegExp, string]> = [
    [
        /el nivel 1 se calcula en vivo con las mismas reglas\.?/i,
        'los resultados de sucursales se calculan al momento con las mismas reglas de la Radiografía.',
    ],
    [
        /el nivel 1 usa sus mismas reglas\.?/i,
        'los resultados de sucursales usan sus mismas reglas.',
    ],
    [/\bnivel 1\b/gi, 'resultado de sucursales'],
];
const plain = (text: string | null | undefined) =>
    PLAIN.reduce((t, [re, to]) => t.replace(re, to), text ?? '');

/** «Súbelo aquí»: lleva a la tarjeta y abre el selector de archivo. */
async function goUpload(slot: string): Promise<void> {
    await nextTick();
    document
        .getElementById(`er-slot-${slot}`)
        ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    document.getElementById(`er-slot-input-${slot}`)?.click();
}
</script>

<template>
    <div class="space-y-4">
        <SourceChecklist
            v-if="sources.checklist"
            :checklist="sources.checklist"
            :period-id="periodId ?? null"
            :period-label="periodLabel"
            :can-upload="canUpload"
            :can-process="canProcess"
            :lock-reason="lockReason"
            @upload="goUpload"
            @tab="(t) => emit('tab', t)"
            @process="emit('process')"
        />

        <section aria-labelledby="er-uploads-title" class="space-y-3">
            <div>
                <h3 id="er-uploads-title" class="text-base font-extrabold">
                    Se suben aquí
                </h3>
                <p class="text-xs text-muted-foreground">
                    Estados de cuenta del banco y NOI de corporativo. Cada
                    archivo se agrega solo: no se vuelve a cargar ni a
                    recalcular lo que ya está.
                </p>
            </div>
            <div class="grid content-start gap-4 md:grid-cols-2 xl:grid-cols-3">
                <AccountUploadCard
                    v-for="acc in sources.cuentas"
                    :key="acc.key"
                    :account="acc"
                    :can-upload="canUpload"
                    :uploading="uploadingSlot === acc.key"
                    :upload-rules="uploadRules"
                    :lock-reason="lockReason"
                    @upload="(slot, file) => emit('upload', slot, file)"
                />
                <PayrollUploadCard
                    v-if="sources.noi_corporativo"
                    :source="sources.noi_corporativo"
                    :can-upload="canUpload"
                    :uploading="uploadingSlot === 'noi_corporativo'"
                    :upload-rules="uploadRules"
                    :lock-reason="lockReason"
                    @upload="(slot, file) => emit('upload', slot, file)"
                />
            </div>
        </section>

        <section class="rounded-2xl border bg-card shadow-soft-xs">
            <header
                class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4"
            >
                <div>
                    <h3 class="text-base font-extrabold">
                        Se cargan en Carga de archivos
                    </h3>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        El Estado de Resultados sólo las lee (no se suben otra
                        vez aquí). Si una falta, entra a Carga de archivos, sube
                        el archivo del mes y pulsa «Cargar registros».
                    </p>
                </div>
                <a
                    :href="cargaUrl(periodId)"
                    class="inline-flex items-center gap-1 text-xs font-bold text-emerald-700 hover:underline dark:text-emerald-400"
                >
                    Abrir Carga de archivos <ExternalLink class="size-3.5" />
                </a>
            </header>
            <ul class="grid divide-y md:grid-cols-3 md:divide-y-0">
                <li
                    v-for="s in simple"
                    :key="s.src.label"
                    class="flex items-start gap-3 px-5 py-4"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-muted text-foreground/80"
                    >
                        <component :is="s.icon" class="size-4.5" />
                    </span>
                    <span class="min-w-0 flex-1 space-y-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-bold">{{
                                s.src.label
                            }}</span>
                            <StatusBadge :status="s.src.status" />
                        </span>
                        <span class="block text-xs text-muted-foreground">{{
                            plain(s.src.detail)
                        }}</span>
                        <span class="block text-[11px] text-muted-foreground/80"
                            >Alimenta: {{ s.feeds }}</span
                        >
                    </span>
                </li>
            </ul>
        </section>

        <UploadAttemptsPanel
            v-if="attemptsUrl"
            :url="attemptsUrl"
            :refresh-key="refreshKey ?? 0"
        />
    </div>
</template>
