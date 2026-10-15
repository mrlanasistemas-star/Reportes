<script setup lang="ts">
defineProps<{ eyebrow?: string; title: string; description?: string }>()
</script>

<template>
    <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <p v-if="eyebrow" class="app-kicker">{{ eyebrow }}</p>
            <h2 class="mt-1 text-xl font-semibold tracking-tight text-foreground">{{ title }}</h2>
            <p v-if="description" class="mt-1 max-w-3xl text-sm leading-6 text-muted-foreground">{{ description }}</p>
        </div>
        <slot />
    </div>
</template>
