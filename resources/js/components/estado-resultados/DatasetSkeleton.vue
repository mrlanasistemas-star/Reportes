<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

// Alturas fijas de la cascada de carga: sólo decoran mientras llega el dataset.
const BARS = [70, 52, 44, 58, 40, 34, 46, 28, 40, 24, 36, 20, 30, 22];
</script>

<template>
    <div
        class="space-y-4"
        aria-busy="true"
        aria-label="Calculando el Estado de Resultados"
    >
        <Skeleton class="h-28 rounded-2xl" />
        <div class="flex overflow-hidden rounded-2xl border bg-card">
            <div
                v-for="i in 7"
                :key="`k${i}`"
                class="flex-1 space-y-2 border-r p-4 last:border-r-0"
            >
                <Skeleton class="h-3 w-24" />
                <Skeleton class="h-7 w-28" />
                <Skeleton class="h-1 w-full" />
            </div>
        </div>
        <Skeleton class="h-11 w-full max-w-3xl rounded-xl" />
        <div
            class="grid gap-4 rounded-2xl border bg-card p-5 lg:grid-cols-[minmax(0,1fr)_320px]"
        >
            <div class="flex h-72 items-end gap-2">
                <Skeleton
                    v-for="(h, i) in BARS"
                    :key="i"
                    class="flex-1 rounded-md"
                    :style="{ height: `${h}%` }"
                />
            </div>
            <Skeleton class="h-72 rounded-2xl" />
        </div>
        <p class="text-center text-xs text-muted-foreground">
            Calculando con Radiografía, ERP y estados de cuenta…
        </p>
    </div>
</template>
