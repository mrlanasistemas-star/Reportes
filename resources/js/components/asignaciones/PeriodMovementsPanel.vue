<script setup lang="ts">
import { AlertTriangle, ArrowLeftRight, PenSquare, RotateCcw, UserMinus, UserPlus } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import AppSectionCard from '@/components/app/AppSectionCard.vue'
import RosterListPager from '@/components/asignaciones/RosterListPager.vue'
import RosterListToolbar from '@/components/asignaciones/RosterListToolbar.vue'
import RosterMovementCard from '@/components/asignaciones/RosterMovementCard.vue'
import { useRosterList } from '@/components/asignaciones/useRosterList'
import type { Assignment, RosterMovementItem } from '@/types/asignaciones'

const props = withDefaults(
    defineProps<{
        incidences: Assignment[]
        hires: RosterMovementItem[]
        reentries?: RosterMovementItem[]
        /** Solo bajas CONFIRMADAS con evidencia — las ausencias sin confirmar van a "Por conciliar". */
        leavers: RosterMovementItem[]
        branchChanges?: RosterMovementItem[]
        returns?: RosterMovementItem[]
        rosterCalculado: boolean
    }>(),
    { reentries: () => [], branchChanges: () => [], returns: () => [] },
)

defineEmits<{ assign: [item: Assignment] }>()

// Un solo panel con tabs (una lista visible a la vez, paginada) para que
// periodos grandes no "desparramen" la página.
type TabKey = 'incidencias' | 'altas' | 'reingresos' | 'bajas' | 'cambios' | 'regresos'

const tabs = computed(() => [
    { key: 'altas' as TabKey, label: 'Altas confirmadas', icon: UserPlus, count: props.hires.length },
    { key: 'reingresos' as TabKey, label: 'Reingresos', icon: RotateCcw, count: props.reentries.length },
    { key: 'bajas' as TabKey, label: 'Bajas confirmadas', icon: UserMinus, count: props.leavers.length },
    { key: 'cambios' as TabKey, label: 'Cambios de sucursal', icon: ArrowLeftRight, count: props.branchChanges.length },
    { key: 'regresos' as TabKey, label: 'Regresos sin baja', icon: RotateCcw, count: props.returns.length },
    { key: 'incidencias' as TabKey, label: 'Registros sin sucursal', icon: AlertTriangle, count: props.incidences.length },
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

const list = useRosterList(listByTab, 8)
const { query, branch, page } = list

// Registros de asignación sin sucursal: misma paginación, filtrados por la búsqueda.
const incidencePage = ref(1)
const INCIDENCE_PAGE = 8
const filteredIncidences = computed(() => {
    const q = query.value.trim().toLowerCase()

    return q ? props.incidences.filter((i) => `${i.employee_name} ${i.branch_name ?? ''}`.toLowerCase().includes(q)) : props.incidences
})
const incidenceTotalPages = computed(() => Math.max(1, Math.ceil(filteredIncidences.value.length / INCIDENCE_PAGE)))
const pagedIncidences = computed(() => filteredIncidences.value.slice((incidencePage.value - 1) * INCIDENCE_PAGE, incidencePage.value * INCIDENCE_PAGE))

watch([activeTab, query], () => {
    page.value = 1
    incidencePage.value = 1
})

const emptyMessage = computed(() => {
    if (activeTab.value === 'incidencias') {
        return 'Sin registros pendientes: todo el periodo tiene sucursal asignada.'
    }

    if (!props.rosterCalculado) {
        return 'La plantilla de este periodo aún no se ha calculado: ejecuta "Actualizar BD" en Histórico General primero.'
    }

    return 'Sin movimientos de este tipo en el periodo.'
})
</script>

<template>
    <AppSectionCard
        title="Movimientos laborales"
        subtitle="Hechos confirmados respecto al periodo anterior. Las ausencias sin evidencia están en «Por conciliar»."
        :padded="false"
    >
        <div class="space-y-3 p-4 sm:p-5">
            <div class="flex flex-wrap gap-1.5" role="tablist">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === tab.key"
                    class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                    :class="
                        activeTab === tab.key
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border bg-background text-muted-foreground hover:bg-muted hover:text-foreground'
                    "
                    @click="activeTab = tab.key"
                >
                    <component :is="tab.icon" class="size-3.5" />
                    {{ tab.label }}
                    <span class="tabular-nums opacity-80">{{ tab.count }}</span>
                </button>
            </div>
            <RosterListToolbar
                v-model:query="query"
                v-model:branch="branch"
                placeholder="Buscar en esta lista..."
                :branch-options="activeTab === 'incidencias' ? [] : list.branchOptions.value"
            />
        </div>

        <!-- Registros de asignación sin sucursal (no son personas del roster; son asignaciones por revisar) -->
        <template v-if="activeTab === 'incidencias'">
            <div class="space-y-3 border-t border-border p-4 sm:p-5">
                <article v-for="item in pagedIncidences" :key="`inc-${item.id}`" class="rounded-xl border border-border bg-background px-4 py-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ item.employee_name }}</p>
                            <p class="mt-1 text-sm text-muted-foreground">{{ item.branch_name || 'Sin sucursal asignada' }}</p>
                            <p class="mt-2 text-xs text-muted-foreground">{{ item.notes || item.match_explanation }}</p>
                        </div>
                        <button
                            type="button"
                            class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg bg-primary px-3 text-xs font-semibold text-primary-foreground transition hover:bg-primary/90"
                            @click="$emit('assign', item)"
                        >
                            <PenSquare class="size-3" />
                            Asignar
                        </button>
                    </div>
                </article>
                <p v-if="!pagedIncidences.length" class="py-8 text-center text-sm text-muted-foreground">{{ emptyMessage }}</p>
            </div>
            <RosterListPager v-model="incidencePage" :total="filteredIncidences.length" :page-size="INCIDENCE_PAGE" :total-pages="incidenceTotalPages" />
        </template>

        <template v-else>
            <div class="space-y-3 border-t border-border p-4 sm:p-5">
                <RosterMovementCard
                    v-for="item in list.paged.value"
                    :key="`${activeTab}-${item.identity_key ?? item.nombre_normalizado ?? item.id}`"
                    :item="item"
                    :branch-prefix="activeTab === 'bajas' ? 'Última sucursal conocida' : undefined"
                />
                <p v-if="!list.paged.value.length" class="py-8 text-center text-sm text-muted-foreground">
                    {{ listByTab.length ? 'Sin coincidencias con la búsqueda.' : emptyMessage }}
                </p>
            </div>
            <RosterListPager v-model="page" :total="list.filtered.value.length" :page-size="list.pageSize" :total-pages="list.totalPages.value" />
        </template>
    </AppSectionCard>
</template>
