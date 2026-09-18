<script setup lang="ts">
defineProps<{ categoria: string }>()

const styles: Record<string, string> = {
    DIAMANTE:  'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
    MASTER:    'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
    SENIOR:    'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    JUNIOR:    'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    MANTENIDO: 'bg-red-100 text-red-700 dark:bg-red-500/15 dark:text-red-300',
}
</script>

<template>
    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[10.5px] font-black tracking-wide" :class="styles[categoria] ?? styles.MANTENIDO">
        {{ categoria }}
    </span>
</template>
