<script setup lang="ts">
import {
    Activity,
    Calculator,
    Database,
    FileSearch,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AccountUploadCard from './AccountUploadCard.vue';
import StatusBadge from './StatusBadge.vue';
import type { Dataset, SimpleSource } from './types';

const props = defineProps<{
    sources: Dataset['sources'];
    canUpload: boolean;
    uploadingSlot: string | null;
}>();

defineEmits<{ upload: [slot: string, file: File] }>();

const simple = computed<Array<{ src: SimpleSource; icon: typeof Database }>>(
    () => [
        { src: props.sources.radiografia, icon: Activity },
        { src: props.sources.erp, icon: Database },
        { src: props.sources.nomina, icon: Users },
        { src: props.sources.ajustes, icon: Calculator },
    ],
);
</script>

<template>
    <section class="space-y-3">
        <div class="flex items-center gap-2">
            <FileSearch class="size-4 text-teal-700 dark:text-teal-400" />
            <h2
                class="text-sm font-bold tracking-wide text-muted-foreground uppercase"
            >
                Estado de las fuentes
            </h2>
        </div>
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div
                v-for="s in simple"
                :key="s.src.label"
                class="flex items-start gap-3 rounded-2xl border bg-card p-3.5 shadow-soft-xs"
            >
                <div
                    class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-teal-50 text-teal-700 dark:bg-teal-950/40 dark:text-teal-300"
                >
                    <component :is="s.icon" class="size-4.5" />
                </div>
                <div class="min-w-0 space-y-1">
                    <p class="text-sm leading-tight font-semibold">
                        {{ s.src.label }}
                    </p>
                    <StatusBadge :status="s.src.status" />
                    <p class="text-xs leading-snug text-muted-foreground">
                        {{ s.src.detail }}
                    </p>
                </div>
            </div>
        </div>
        <div class="grid gap-3 lg:grid-cols-2">
            <AccountUploadCard
                v-for="acc in sources.cuentas"
                :key="acc.key"
                :account="acc"
                :can-upload="canUpload"
                :uploading="uploadingSlot === acc.key"
                @upload="(slot, file) => $emit('upload', slot, file)"
            />
        </div>
    </section>
</template>
