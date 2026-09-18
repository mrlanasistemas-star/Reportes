<script setup lang="ts">
import { ArrowRightLeft, Building2, ClipboardList, PenSquare } from 'lucide-vue-next'
import { computed } from 'vue'
import type { Assignment } from '@/types/asignaciones'

const props = defineProps<{
    item: Assignment
}>()

defineEmits<{ assign: [] }>()

const initials = computed(() => {
    const parts = props.item.employee_name.trim().split(/\s+/).filter(Boolean)

    if (!parts.length) {
return '?'
}

    return (parts[0][0] + (parts[1]?.[0] ?? '')).toUpperCase()
})

const statusTone: Record<Assignment['ui_status'], { badge: string; bar: string; avatar: string }> = {
    matched: {
        badge: 'border border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300',
        bar: 'bg-emerald-400',
        avatar: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    },
    manual: {
        badge: 'border border-sky-200 bg-sky-50 text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300',
        bar: 'bg-sky-400',
        avatar: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
    },
    unmatched: {
        badge: 'border border-rose-200 bg-rose-50 text-rose-700 dark:border-rose-500/20 dark:bg-rose-500/10 dark:text-rose-300',
        bar: 'bg-rose-400',
        avatar: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
    },
    pending: {
        badge: 'border border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300',
        bar: 'bg-amber-400',
        avatar: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    },
}

const statusLabel: Record<Assignment['ui_status'], string> = {
    matched: 'Match correcto',
    manual: 'Manual',
    unmatched: 'Sin match',
    pending: 'Pendiente',
}

const tone = computed(() => statusTone[props.item.ui_status] ?? statusTone.pending)

function formatConfidence(value?: number | null) {
    if (value === null || value === undefined) {
return '—'
}

    return `${Math.round(value * 100)}%`
}
</script>

<template>
    <article
        class="group relative overflow-hidden rounded-[28px] border border-border/70 bg-background px-4 py-4 shadow-sm transition-all duration-200 hover:-translate-y-1 hover:shadow-lg"
    >
        <span class="absolute inset-y-0 left-0 w-1 rounded-r-full opacity-0 transition-opacity duration-200 group-hover:opacity-100" :class="tone.bar" />

        <!-- Header row -->
        <div class="flex items-start justify-between gap-3">
            <div class="flex min-w-0 items-center gap-3">
                <div :class="['flex size-10 shrink-0 items-center justify-center rounded-2xl text-sm font-black', tone.avatar]">
                    {{ initials }}
                </div>
                <div class="min-w-0">
                    <h3 class="truncate text-base font-bold tracking-tight">
                        {{ item.employee_name }}
                    </h3>
                    <p
                        v-if="item.aliases && item.aliases.length > 0"
                        class="mt-0.5 truncate text-xs text-amber-600 dark:text-amber-400"
                        :title="`Variantes fusionadas: ${item.aliases.map((a) => a.employee_name).join(', ')}`"
                    >
                        También: {{ item.aliases.map((a) => a.employee_name).join(' · ') }}
                    </p>
                    <p v-else class="mt-0.5 truncate text-xs text-muted-foreground">
                        {{ item.normalized_name || 'Sin nombre normalizado' }}
                    </p>
                </div>
            </div>

            <div class="flex shrink-0 flex-col items-end gap-1.5">
                <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-semibold" :class="tone.badge">
                    {{ statusLabel[item.ui_status] }}
                </span>
                <span
                    v-if="item.was_manual_reviewed"
                    class="inline-flex rounded-full border border-sky-200 bg-sky-50 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-300"
                >
                    Ajuste manual
                </span>
            </div>
        </div>

        <!-- Details -->
        <div class="mt-4 space-y-2.5">
            <div class="flex items-center gap-2 text-sm">
                <Building2 class="size-4 shrink-0 text-muted-foreground" />
                <span class="font-medium">{{ item.branch_name || 'Sin sucursal asignada' }}</span>
            </div>

            <div class="flex items-center gap-2 text-sm">
                <ArrowRightLeft class="size-4 shrink-0 text-muted-foreground" />
                <span>{{ item.match_label || 'Pendiente' }}</span>
                <span class="text-muted-foreground">·</span>
                <span class="text-muted-foreground">{{ formatConfidence(item.confidence) }}</span>
            </div>

            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                <ClipboardList class="size-4 shrink-0" />
                <span>{{ item.match_explanation || 'Sin explicación' }}</span>
            </div>

            <div
                v-if="item.notes"
                class="rounded-2xl border border-border/70 bg-muted/30 px-3 py-3 text-sm text-muted-foreground"
            >
                {{ item.notes }}
            </div>
        </div>

        <!-- Footer -->
        <div class="mt-4 flex items-center justify-between border-t pt-4">
            <p class="text-xs text-muted-foreground">{{ item.updated_at ?? '—' }}</p>
            <button
                type="button"
                class="inline-flex h-9 items-center gap-1.5 rounded-2xl bg-sky-500 px-4 text-xs font-bold text-white transition duration-200 hover:-translate-y-0.5 hover:bg-sky-400 hover:shadow-md active:translate-y-0"
                @click="$emit('assign')"
            >
                <PenSquare class="size-3.5" />
                Asignar sucursal
            </button>
        </div>
    </article>
</template>
