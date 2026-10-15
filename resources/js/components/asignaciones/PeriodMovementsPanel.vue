<script setup lang="ts">
import { AlertTriangle, ArrowLeftRight, PenSquare, RotateCcw, UserMinus, UserPlus } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import RosterMovementCard from '@/components/asignaciones/RosterMovementCard.vue'
import type { Assignment, RosterMovementItem } from '@/types/asignaciones'

const props = withDefaults(
    defineProps<{
        incidences: Assignment[]
        hires: RosterMovementItem[]
        reentries?: RosterMovementItem[]
        /** Solo bajas CONFIRMADAS con evidencia — las ausencias sin confirmar van a "Pendientes de conciliación". */
        leavers: RosterMovementItem[]
        branchChanges?: RosterMovementItem[]
        returns?: RosterMovementItem[]
        rosterCalculado: boolean
    }>(),
    { reentries: () => [], branchChanges: () => [], returns: () => [] },
)

defineEmits<{ assign: [item: Assignment] }>()

// Un solo panel con tabs (una lista visible a la vez, altura tope + scroll) para
// que periodos grandes no "desparramen" la página.
type TabKey = 'incidencias' | 'altas' | 'reingresos' | 'bajas' | 'cambios' | 'regresos'

const tabs = computed(() => [
    { key: 'altas' as TabKey, label: 'Altas confirmadas', icon: UserPlus, count: props.hires.length, tone: 'emerald' },
    { key: 'reingresos' as TabKey, label: 'Reingresos', icon: RotateCcw, count: props.reentries.length, tone: 'emerald' },
    { key: 'bajas' as TabKey, label: 'Bajas confirmadas', icon: UserMinus, count: props.leavers.length, tone: 'rose' },
    { key: 'cambios' as TabKey, label: 'Cambios de sucursal', icon: ArrowLeftRight, count: props.branchChanges.length, tone: 'sky' },
    { key: 'regresos' as TabKey, label: 'Regresos sin baja', icon: RotateCcw, count: props.returns.length, tone: 'sky' },
    { key: 'incidencias' as TabKey, label: 'Incidencias de sucursal', icon: AlertTriangle, count: props.incidences.length, tone: 'amber' },
])

const activeTab = ref<TabKey>('altas')

watch(
    () => tabs.value.map((t) => t.count).join(','),
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
    amber: { active: 'bg-amber-500 text-white shadow-amber-500/30', badge: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' },
    emerald: { active: 'bg-emerald-500 text-white shadow-emerald-500/30', badge: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' },
    rose: { active: 'bg-rose-500 text-white shadow-rose-500/30', badge: 'bg-rose-100 text-rose-700 dark:bg-rose-500/15 dark:text-rose-300' },
    sky: { active: 'bg-sky-500 text-white shadow-sky-500/30', badge: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300' },
}

const listByTab = computed<RosterMovementItem[]>(() => {
    switch (activeTab.value) {
        case 'altas':
            return props.hires
        case 'reingresos':
            return props.reentries
        case 'bajas':
            return props.leavers
        case 'cambios':
            return props.branchChanges
        case 'regresos':
            return props.returns
        default:
            return []
    }
})

const emptyMessage = computed(() => {
    if (activeTab.value === 'incidencias') {
        return 'Sin incidencias: todo el periodo tiene sucursal asignada.'
    }

    if (!props.rosterCalculado) {
        return 'La plantilla de este periodo aún no se ha calculado: ejecuta "Actualizar BD" en Histórico General primero.'
    }

    return 'Sin movimientos de este tipo en el periodo.'
})
</script>

<template>
    <section class="app-card overflow-hidden">
        <div class="border-b px-4 py-4 sm:px-6 sm:py-5">
            <h2 class="text-lg font-bold tracking-tight">Movimientos laborales</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Hechos confirmados respecto al periodo anterior. Las ausencias sin evidencia están en "Pendientes de conciliación".
            </p>

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

        <!-- Incidencias de asignación de sucursal -->
        <div v-if="activeTab === 'incidencias'" class="max-h-[26rem] space-y-3 overflow-y-auto p-4 sm:p-6">
            <article v-for="item in incidences" :key="`inc-${item.id}`" class="rounded-2xl border border-border/70 bg-background px-4 py-4">
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

        <div v-else class="max-h-[26rem] space-y-3 overflow-y-auto p-4 sm:p-6">
            <RosterMovementCard
                v-for="item in listByTab"
                :key="`${activeTab}-${item.nombre_normalizado ?? item.id}`"
                :item="item"
                :branch-prefix="activeTab === 'bajas' ? 'Última sucursal conocida' : undefined"
            />
            <p v-if="!listByTab.length" class="py-10 text-center text-sm text-muted-foreground">{{ emptyMessage }}</p>
        </div>
    </section>
</template>
