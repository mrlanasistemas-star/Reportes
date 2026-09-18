<script setup lang="ts">
import { Inbox } from 'lucide-vue-next'

defineProps<{ title: string; description?: string }>()
</script>

<template>
    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 p-8 text-center dark:border-slate-700 dark:bg-slate-800/40">
        <div class="mx-auto flex size-11 items-center justify-center rounded-2xl bg-white text-slate-400 shadow-sm dark:bg-card dark:text-slate-500">
            <Inbox class="size-5" />
        </div>
        <h3 class="mt-3 text-sm font-black text-slate-900 dark:text-slate-50">{{ title }}</h3>
        <p v-if="description" class="mx-auto mt-1.5 max-w-md text-xs leading-5 text-slate-500 dark:text-slate-400">{{ description }}</p>
    </div>
</template>
