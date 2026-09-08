<script setup lang="ts">
// Card ejecutiva compacta — reproduce docs/imagenesOKR/1.png y 9.png (nunca
// las cards grandes/verticales del rediseño anterior): ícono circular +
// etiqueta pequeña + valor grande, densidad baja, sin exceso de padding.
import { computed } from 'vue'

const props = withDefaults(defineProps<{
    icon: any
    label: string
    value: string | number
    hint?: string | null
    tone?: 'default' | 'primary' | 'success' | 'warning' | 'danger'
}>(), {
    hint: null,
    tone: 'default',
})

const toneClasses = computed(() => ({
    default: { wrap: 'border-border bg-card', icon: 'bg-muted text-muted-foreground' },
    primary: { wrap: 'border-border bg-card', icon: 'bg-primary/10 text-primary' },
    success: { wrap: 'border-border bg-card', icon: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
    warning: { wrap: 'border-border bg-card', icon: 'bg-amber-500/10 text-amber-600 dark:text-amber-400' },
    danger:  { wrap: 'border-border bg-card', icon: 'bg-rose-500/10 text-rose-600 dark:text-rose-400' },
}[props.tone]))
</script>

<template>
    <div class="flex items-start gap-3 rounded-xl border p-3.5 shadow-sm" :class="toneClasses.wrap">
        <div class="flex size-9 shrink-0 items-center justify-center rounded-full" :class="toneClasses.icon">
            <component :is="icon" class="size-4.5" />
        </div>
        <div class="min-w-0">
            <p class="text-lg font-bold leading-tight tabular-nums text-foreground">{{ value }}</p>
            <p class="truncate text-xs font-medium text-muted-foreground">{{ label }}</p>
            <p v-if="hint" class="truncate text-[11px] text-muted-foreground/70">{{ hint }}</p>
        </div>
    </div>
</template>
