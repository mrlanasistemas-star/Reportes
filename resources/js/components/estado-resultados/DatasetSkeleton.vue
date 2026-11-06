<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
</script>

<template>
    <div
        class="space-y-6"
        aria-busy="true"
        aria-label="Calculando Estado de Resultados"
    >
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <Skeleton v-for="i in 4" :key="`s${i}`" class="h-28 rounded-2xl" />
        </div>
        <div class="grid gap-3 sm:grid-cols-3 xl:grid-cols-6">
            <Skeleton v-for="i in 6" :key="`k${i}`" class="h-24 rounded-2xl" />
        </div>
        <Skeleton class="h-10 w-full max-w-xl rounded-xl" />
        <Skeleton class="h-96 rounded-3xl" />
    </div>
</template>
