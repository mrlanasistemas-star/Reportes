<script setup lang="ts">
import { Download } from 'lucide-vue-next'
import { usePwaInstall } from '@/composables/usePwaInstall'

const { deferredPrompt, isInstalled, promptInstall } = usePwaInstall()
</script>

<template>
    <button
        v-if="deferredPrompt && !isInstalled"
        type="button"
        class="hidden items-center gap-2 rounded-xl border border-border bg-background px-3.5 py-1.5 text-xs font-semibold text-foreground shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700 hover:shadow-md active:translate-y-0 lg:inline-flex dark:hover:border-indigo-900 dark:hover:bg-indigo-950/40 dark:hover:text-indigo-300"
        @click="promptInstall"
    >
        <Download class="size-3.5" />
        Descargar aplicación
    </button>
</template>
