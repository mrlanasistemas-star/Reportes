<script setup lang="ts">
import { AlertTriangle, PenSquare, UserMinus, UserPlus } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import type { Assignment, RosterMovementItem } from '@/types/asignaciones'

const props = defineProps<{
    incidences: Assignment[]
    hires: RosterMovementItem[]
    leavers: RosterMovementItem[]
    rosterCalculado: boolean
}>()

defineEmits<{ assign: [item: Assignment] }>()

// Antes esto era 3 columnas apiladas mostrando TODA la lista sin límite — con
// periodos grandes (muchas incidencias/altas/bajas) la sección se volvía
// gigantesca ("se desparrama toda la lista"). Ahora es un solo panel con tabs
// (una lista visible a la vez) y cada lista tiene altura tope + scroll propio.
type TabKey = 'incidencias' | 'altas' | 'bajas'

const tabs = computed(() => [
    { key: 'incidencias' as TabKey, label: 'Incidencias', icon: AlertTriangle, count: props.incidences.length, tone: 'amber' },
    { key: 'altas' as TabKey, label: 'Altas del periodo', icon: UserPlus, count: props.hires.length, tone: 'emerald' },
    { key: 'bajas' as TabKey, label: 'Bajas del periodo', icon: UserMinus, count: props.leavers.length, tone: 'rose' },
])

const activeTab = ref<TabKey>('incidencias')

// Si la pestaña activa se queda sin datos al cambiar de periodo/filtro, salta a
// la primera que sí tenga algo — nunca deja al usuario viendo una lista vacía
// por accidente si hay otra con contenido real.
watch(
    () => [props.incidences.length, props.hires.length, props.leavers.length],
    () => {
        const current = tabs.value.find((t) => t.key === activeTab.value)

        if (current && current.count > 0) {
return
}

        const firstWithData = tabs.value.find((t) => t.count > 0)

        if (firstWithData) {
activeTab.value = firstWithData.key
}
    },
    { immediate: true },
)

const toneClasses: Record<string, { active: string; badge: string }> = {
    amber: {
        active: 'bg-amber-500 text-white shadow-amber-500/30',
        badge: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    },
    emerald: {
        active: 'bg-emerald-500 text-white shadow-emerald-500/30',
        badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    },
    rose: {
        active: 'bg-rose-500 text-white shadow-rose-500/30',
        badge: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300',
    },
}

const emptyMessage = computed(() => {
    if (activeTab.value === 'incidencias') {
return 'Sin incidencias — todo el periodo tiene sucursal asignada.'
}

    if (!props.rosterCalculado) {
        return 'El roster de colaboradores de este periodo aún no se ha calculado — corre "Actualizar BD" en Histórico General primero.'
    }

    return activeTab.value === 'altas' ? 'Sin altas detectadas.' : 'Sin bajas detectadas.'
})
</script>

<template>
    <section class="app-card overflow-hidden">
        <div class="border-b px-4 py-4 sm:px-6 sm:py-5">
            <h2 class="text-lg font-bold tracking-tight">Movimientos del periodo</h2>
            <p class="mt-1 text-sm text-muted-foreground">Incidencias que necesitan atención, y quién entró o salió respecto al periodo anterior.</p>

            <div class="mt-4 flex flex-wrap gap-2">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-2xl px-3.5 py-2 text-xs font-bold transition duration-200"
                    :class="
                        activeTab === tab.key
                            ? ['shadow-lg', toneClasses[tab.tone].active]
                            : 'border border-border/70 bg-background text-muted-foreground hover:-translate-y-0.5 hover:text-foreground hover:shadow-sm'
                    "
                    @click="activeTab = tab.key"
                >
                    <component :is="tab.icon" class="size-3.5" />
                    {{ tab.label }}
                    <span
                        class="inline-flex min-w-5 items-center justify-center rounded-full px-1.5 py-0.5 text-[10px]"
                        :class="activeTab === tab.key ? 'bg-white/20 text-white' : toneClasses[tab.tone].badge"
                    >
                        {{ tab.count }}
                    </span>
                </button>
            </div>
        </div>

        <!-- Incidencias -->
        <div v-if="activeTab === 'incidencias'" class="max-h-[26rem] space-y-3 overflow-y-auto p-4 sm:p-6">
            <article
                v-for="item in incidences"
                :key="`inc-${item.id}`"
                class="rounded-2xl border border-border/70 bg-background px-4 py-4"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="truncate font-semibold">{{ item.employee_name }}</p>
                        <p class="mt-1 text-sm text-muted-foreground">{{ item.branch_name || 'Sin sucursal asignada' }}</p>
                        <p class="mt-2 text-xs text-muted-foreground">{{ item.notes || item.match_explanation }}</p>
                    </div>
                    <button
                        type="button"
                        class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-xl bg-sky-500 px-3 text-xs font-bold text-white transition duration-200 hover:-translate-y-0.5 hover:bg-sky-400"
                        @click="$emit('assign', item)"
                    >
                        <PenSquare class="size-3" />
                        Asignar
                    </button>
                </div>
            </article>
            <p v-if="!incidences.length" class="py-10 text-center text-sm text-muted-foreground">{{ emptyMessage }}</p>
        </div>

        <!-- Altas -->
        <div v-else-if="activeTab === 'altas'" class="max-h-[26rem] space-y-3 overflow-y-auto p-4 sm:p-6">
            <article
                v-for="item in hires"
                :key="`hire-${item.id}`"
                class="rounded-2xl border border-border/70 bg-background px-4 py-4"
            >
                <p class="font-semibold">{{ item.employee_name }}</p>
                <p class="mt-1 text-sm text-muted-foreground">{{ item.branch_name || 'Sin sucursal asignada' }}</p>
            </article>
            <p v-if="!hires.length" class="py-10 text-center text-sm text-muted-foreground">{{ emptyMessage }}</p>
        </div>

        <!-- Bajas -->
        <div v-else class="max-h-[26rem] space-y-3 overflow-y-auto p-4 sm:p-6">
            <article
                v-for="item in leavers"
                :key="`leave-${item.id}`"
                class="rounded-2xl border border-border/70 bg-background px-4 py-4"
            >
                <p class="font-semibold">{{ item.employee_name }}</p>
                <p class="mt-1 text-sm text-muted-foreground">Última sucursal conocida: {{ item.branch_name || 'Sin sucursal asignada' }}</p>
                <p class="mt-2 text-xs text-muted-foreground">Último periodo: {{ item.period_label || '—' }}</p>
            </article>
            <p v-if="!leavers.length" class="py-10 text-center text-sm text-muted-foreground">{{ emptyMessage }}</p>
        </div>
    </section>
</template>
