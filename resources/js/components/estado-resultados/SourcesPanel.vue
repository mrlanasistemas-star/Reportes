<script setup lang="ts">
import { Activity, Calculator, Database, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import AccountUploadCard from './AccountUploadCard.vue';
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
}>();

defineEmits<{ upload: [slot: string, file: File] }>();

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
        feeds: 'Gastos registrados; el banco se cruza contra él',
    },
    {
        src: props.sources.nomina,
        icon: Users,
        feeds: 'Nómina e IMSS por colaborador',
    },
    {
        src: props.sources.ajustes,
        icon: Calculator,
        feeds: 'Depreciación, intereses e ISR (EBIT, EBT y utilidad)',
    },
]);
</script>

<template>
    <div class="grid gap-4 xl:grid-cols-[minmax(0,0.9fr)_minmax(0,1.6fr)]">
        <section class="rounded-2xl border bg-card shadow-soft-xs">
            <header class="border-b px-5 py-4">
                <h3 class="text-base font-extrabold">Fuentes del sistema</h3>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Se cargan en sus propios módulos. Aquí ves si ya están
                    listas para este periodo.
                </p>
            </header>
            <ul class="divide-y">
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
                            s.src.detail
                        }}</span>
                        <span class="block text-[11px] text-muted-foreground/80"
                            >Alimenta: {{ s.feeds }}</span
                        >
                    </span>
                </li>
            </ul>
        </section>
        <div class="grid content-start gap-4 lg:grid-cols-2">
            <AccountUploadCard
                v-for="acc in sources.cuentas"
                :key="acc.key"
                :account="acc"
                :can-upload="canUpload"
                :uploading="uploadingSlot === acc.key"
                :upload-rules="uploadRules"
                :lock-reason="lockReason"
                @upload="(slot, file) => $emit('upload', slot, file)"
            />
        </div>
        <UploadAttemptsPanel
            v-if="attemptsUrl"
            class="xl:col-span-2"
            :url="attemptsUrl"
            :refresh-key="refreshKey ?? 0"
        />
    </div>
</template>
