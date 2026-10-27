<script setup lang="ts">
import type { Component } from 'vue'
import { Skeleton } from '@/components/ui/skeleton'

// Tarjeta KPI del Dashboard (Fase 8.5). `hero` = importe principal (EBITDA/margen)
// con más jerarquía visual; el resto, tarjeta compacta. Tonos con los tokens
// semánticos del sistema visual (success/danger/info/warning), nunca colores sueltos.
type Tone = 'success' | 'danger' | 'info' | 'warning' | 'neutral'

withDefaults(defineProps<{
    label: string
    value: string
    icon: Component
    tone?: Tone
    hint?: string | null
    hero?: boolean
    loading?: boolean
}>(), {
    tone: 'neutral',
    hint: null,
    hero: false,
    loading: false,
})

const toneClasses: Record<Tone, { icon: string; value: string }> = {
    success: { icon: 'bg-success-soft text-success', value: 'text-success' },
    danger: { icon: 'bg-danger-soft text-danger', value: 'text-danger' },
    info: { icon: 'bg-info-soft text-info', value: 'text-foreground' },
    warning: { icon: 'bg-warning-soft text-warning', value: 'text-foreground' },
    neutral: { icon: 'bg-muted text-muted-foreground', value: 'text-foreground' },
}
</script>

<template>
    <article
        :class="[
            'app-hover-lift relative flex flex-col rounded-2xl border border-border bg-card shadow-soft-xs',
            hero ? 'p-5 sm:p-6' : 'p-4',
        ]"
        :aria-busy="loading"
    >
        <div class="flex items-center gap-2.5">
            <span :class="['flex shrink-0 items-center justify-center rounded-xl', hero ? 'size-9' : 'size-8', toneClasses[tone].icon]" aria-hidden="true">
                <component :is="icon" :class="hero ? 'size-[18px]' : 'size-4'" />
            </span>
            <h3 class="text-[11px] font-bold tracking-wide text-muted-foreground uppercase">{{ label }}</h3>
        </div>
        <Skeleton v-if="loading" :class="['mt-3 rounded-lg', hero ? 'h-9 w-3/4' : 'h-6 w-2/3']" />
        <p
            v-else
            :class="[
                'mt-2 font-black tracking-tight tabular-nums',
                hero ? 'text-3xl sm:text-[2rem]' : 'text-xl',
                hero ? toneClasses[tone].value : 'text-foreground',
            ]"
        >
            {{ value }}
        </p>
        <p v-if="hint && !loading" class="mt-1 text-[11px] text-muted-foreground">{{ hint }}</p>
    </article>
</template>
