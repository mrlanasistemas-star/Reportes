<script setup lang="ts">
import { Inbox } from 'lucide-vue-next'

defineProps<{ title: string; description?: string }>()
</script>

<template>
    <div class="rounded-2xl border border-dashed border-slate-300 bg-slate-50/80 p-8 text-center dark:border-slate-700 dark:bg-slate-800/40">
        <div class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-white text-slate-500 shadow-sm dark:bg-slate-900 dark:text-slate-400">
            <Inbox class="size-6" />
        </div>
        <h3 class="mt-4 text-sm font-black text-slate-900 dark:text-slate-100">{{ title }}</h3>
        <p v-if="description" class="mx-auto mt-2 max-w-md text-sm leading-6 text-slate-500 dark:text-slate-400">{{ description }}</p>
        <slot />
    </div>
</template>
