<script setup lang="ts">
// "Centro de atención" (sección 16 del pedido) — compacto, solo aparece si
// hay algo que atender (nunca ocupa media pantalla vacío).
//
// LIMITACIÓN: "check-in pendiente" no se incluye todavía — requeriría saber,
// por Objective, si YA se registró el check-in de la semana en curso, dato
// que hoy no viaja en el payload del dashboard (evita una query extra por
// fila). Documentado como pendiente, no fingido.
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'
import { AlertTriangle, Clock, ShieldAlert } from 'lucide-vue-next'
import { formatFriendlyDate } from '@/lib/okrFormat'

const props = defineProps<{ objectives: any[] }>()

const DUE_SOON_DAYS = 14

const items = computed(() => {
    const today = new Date().toISOString().slice(0, 10)
    const dueSoonLimit = new Date()
    dueSoonLimit.setDate(dueSoonLimit.getDate() + DUE_SOON_DAYS)
    const dueSoonLimitStr = dueSoonLimit.toISOString().slice(0, 10)

    const list: { id: number; kind: 'off_track' | 'risk' | 'due_soon'; title: string; sub: string; weeksLeft: number }[] = []

    for (const o of props.objectives) {
        if (o.lifecycle_status !== 'active') continue
        const weeksLeft = Math.max(0, (o.duration_weeks ?? 0) - (o.current_week ?? 0))

        if (o.health_status === 'off_track') {
            list.push({ id: o.id, kind: 'off_track', title: o.title, sub: o.branch ?? o.employee ?? '—', weeksLeft })
        } else if (o.health_status === 'risk') {
            list.push({ id: o.id, kind: 'risk', title: o.title, sub: o.branch ?? o.employee ?? '—', weeksLeft })
        } else if (o.end_date && o.end_date >= today && o.end_date <= dueSoonLimitStr) {
            list.push({ id: o.id, kind: 'due_soon', title: o.title, sub: o.branch ?? o.employee ?? '—', weeksLeft })
        }
    }

    const order = { off_track: 0, risk: 1, due_soon: 2 }

    return list.sort((a, b) => order[a.kind] - order[b.kind]).slice(0, 8)
})

const meta = {
    off_track: { icon: AlertTriangle, label: 'Fuera de trayectoria', class: 'text-rose-600 dark:text-rose-400' },
    risk:      { icon: ShieldAlert, label: 'En riesgo', class: 'text-amber-600 dark:text-amber-400' },
    due_soon:  { icon: Clock, label: 'Próximo a vencer', class: 'text-sky-600 dark:text-sky-400' },
} as const
</script>

<template>
    <section v-if="items.length" class="app-card p-5">
        <p class="mb-3 flex items-center gap-1.5 text-sm font-bold text-foreground">
            <ShieldAlert class="size-4 text-primary" /> Atención requerida
        </p>
        <div class="space-y-2">
            <div v-for="item in items" :key="`${item.kind}-${item.id}`" class="flex items-center justify-between gap-3 rounded-xl border border-border bg-muted/20 p-3 text-sm transition hover:bg-muted/40">
                <div class="flex min-w-0 items-center gap-3">
                    <component :is="meta[item.kind].icon" class="size-4 shrink-0" :class="meta[item.kind].class" />
                    <div class="min-w-0">
                        <p class="truncate font-semibold text-foreground">{{ item.title }}</p>
                        <p class="text-xs text-muted-foreground">{{ item.sub }} · {{ meta[item.kind].label }} · {{ item.weeksLeft }} semana(s) restantes</p>
                    </div>
                </div>
                <Link :href="`/okr/${item.id}`" class="shrink-0 text-xs font-bold text-primary hover:underline">Ver →</Link>
            </div>
        </div>
    </section>
</template>
