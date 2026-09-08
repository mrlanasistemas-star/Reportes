<script setup lang="ts">
// Card ejecutiva para las cifras del dashboard/detalle (sección M del pedido):
// icono + valor grande + subtítulo + tono semántico suave + hover sutil.
import { computed } from 'vue'

const props = withDefaults(defineProps<{
    icon: any
    label: string
    value: string | number
    hint?: string | null
    tone?: 'default' | 'primary' | 'success' | 'warning' | 'danger'
    global?: boolean
}>(), {
    hint: null,
    tone: 'default',
    global: false,
})

const toneClasses = computed(() => ({
    default: { wrap: 'border-border bg-card', icon: 'bg-muted text-muted-foreground', value: 'text-foreground' },
    primary: { wrap: 'border-primary/15 bg-primary/5', icon: 'bg-primary/10 text-primary', value: 'text-foreground' },
    success: { wrap: 'border-emerald-500/15 bg-emerald-500/5', icon: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400', value: 'text-foreground' },
    warning: { wrap: 'border-amber-500/15 bg-amber-500/5', icon: 'bg-amber-500/10 text-amber-600 dark:text-amber-400', value: 'text-foreground' },
    danger:  { wrap: 'border-rose-500/15 bg-rose-500/5', icon: 'bg-rose-500/10 text-rose-600 dark:text-rose-400', value: 'text-foreground' },
}[props.tone]))
</script>

<template>
    <div
        class="group relative overflow-hidden rounded-2xl border p-5 shadow-sm transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg"
        :class="toneClasses.wrap"
    >
        <span
            v-if="global"
            class="absolute right-3 top-3 rounded-full bg-muted px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground"
        >
            Global
        </span>

        <div class="flex size-10 items-center justify-center rounded-xl transition-transform duration-300 group-hover:scale-110" :class="toneClasses.icon">
            <component :is="icon" class="size-5" />
        </div>

        <p class="mt-4 text-2xl font-bold tracking-tight tabular-nums" :class="toneClasses.value">
            {{ value }}
        </p>
        <p class="mt-1 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
            {{ label }}
        </p>
        <p v-if="hint" class="mt-1.5 text-xs text-muted-foreground/80">
            {{ hint }}
        </p>
    </div>
</template>
