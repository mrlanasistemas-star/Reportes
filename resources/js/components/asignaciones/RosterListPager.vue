<script setup lang="ts">
import { ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { computed } from 'vue'

const props = defineProps<{
    total: number
    pageSize: number
    totalPages: number
}>()

const page = defineModel<number>({ required: true })

const range = computed(() => {
    if (!props.total) {
        return 'Sin registros'
    }

    const start = (page.value - 1) * props.pageSize + 1
    const end = Math.min(page.value * props.pageSize, props.total)

    return `${start}–${end} de ${props.total}`
})
</script>

<template>
    <div class="flex items-center justify-between gap-3 border-t border-border px-4 py-2.5 sm:px-5">
        <p class="text-xs text-muted-foreground tabular-nums">{{ range }}</p>
        <div v-if="totalPages > 1" class="flex items-center gap-1.5">
            <button
                type="button"
                class="inline-flex size-8 items-center justify-center rounded-lg border border-border text-muted-foreground transition hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-40"
                :disabled="page === 1"
                aria-label="Página anterior"
                @click="page--"
            >
                <ChevronLeft class="size-4" />
            </button>
            <span class="min-w-16 text-center text-xs font-semibold text-muted-foreground tabular-nums">{{ page }} / {{ totalPages }}</span>
            <button
                type="button"
                class="inline-flex size-8 items-center justify-center rounded-lg border border-border text-muted-foreground transition hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-40"
                :disabled="page === totalPages"
                aria-label="Página siguiente"
                @click="page++"
            >
                <ChevronRight class="size-4" />
            </button>
        </div>
    </div>
</template>
