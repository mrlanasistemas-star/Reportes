<script setup lang="ts">
import { Download } from 'lucide-vue-next'
import { usePwaInstall } from '@/composables/usePwaInstall'

const { deferredPrompt, isInstalled, promptInstall } = usePwaInstall()
</script>

<template>
    <button
        v-if="deferredPrompt && !isInstalled"
        type="button"
        class="hidden items-center gap-2 rounded-xl border border-border bg-background px-3.5 py-1.5 text-xs font-semibold text-foreground shadow-sm transition duration-200 hover:border-primary/30 hover:bg-accent hover:text-accent-foreground lg:inline-flex"
        @click="promptInstall"
    >
        <Download class="size-3.5" />
        Descargar aplicación
    </button>
</template>
