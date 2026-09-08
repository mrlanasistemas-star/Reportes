<script setup lang="ts">
import { computed, ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Target, AlertTriangle, XCircle, Gauge, Building2, Users, Plus, History, Search, X } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    objectives: any[]
    cards: {
        active: number; risk: number; not_met: number; avg_compliance: number
        branches_with_okr: number; employees_with_okr: number; total_branches: number; total_employees: number
    }
    filters: { branches: { id: number; name: string }[]; employees: { id: number; full_name: string }[]; statuses: string[] }
    query: { branch_id?: string; employee_id?: string; status?: string; search?: string }
}>()

const branchId   = ref(props.query.branch_id ?? '')
const employeeId = ref(props.query.employee_id ?? '')
const status     = ref(props.query.status ?? '')
const search     = ref(props.query.search ?? '')

function applyFilters() {
    router.get('/okr', {
        branch_id: branchId.value || undefined,
        employee_id: employeeId.value || undefined,
        status: status.value || undefined,
        search: search.value || undefined,
    }, { preserveState: true, replace: true })
}

function clearFilters() {
    branchId.value = ''; employeeId.value = ''; status.value = ''; search.value = ''
    router.get('/okr', {}, { replace: true })
}

const healthLabel: Record<string, string> = { ahead: 'Adelantado', on_track: 'En trayectoria', risk: 'En riesgo', off_track: 'Fuera de trayectoria' }
const healthColor: Record<string, string> = {
    ahead: 'bg-emerald-100 text-emerald-700', on_track: 'bg-sky-100 text-sky-700',
    risk: 'bg-amber-100 text-amber-700', off_track: 'bg-rose-100 text-rose-700',
}
const statusLabel: Record<string, string> = { draft: 'Borrador', active: 'Activo', closed: 'Cerrado', cancelled: 'Cancelado' }

const sortedObjectives = computed(() => [...props.objectives].sort((a, b) => (a.health_status === 'off_track' ? -1 : 1)))
</script>

<template>
    <div class="mx-auto max-w-7xl space-y-6 p-4 sm:p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-black text-slate-950">OKR <span class="font-normal text-slate-400">(Objective Key Result)</span></h1>
                <p class="mt-1 text-sm text-slate-500">Gestiona objetivos, resultados clave y seguimiento por sucursal y colaborador.</p>
            </div>
            <div class="flex gap-2">
                <Link href="/okr/history" class="inline-flex h-10 items-center gap-2 rounded-xl border border-slate-200 bg-white px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-50">
                    <History class="size-4" /> Histórico
                </Link>
                <Link href="/okr/create" class="inline-flex h-10 items-center gap-2 rounded-xl bg-indigo-700 px-4 text-sm font-black text-white shadow transition hover:bg-indigo-600">
                    <Plus class="size-4" /> Asignar OKR
                </Link>
            </div>
        </div>

        <!-- Filtros -->
        <div class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Sucursal</label>
                <select v-model="branchId" @change="applyFilters" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    <option value="">Todas</option>
                    <option v-for="b in filters.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Colaborador</label>
                <select v-model="employeeId" @change="applyFilters" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    <option value="">Todos</option>
                    <option v-for="e in filters.employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Estado</label>
                <select v-model="status" @change="applyFilters" class="h-10 rounded-xl border border-slate-200 bg-white px-3 text-sm">
                    <option value="">Todos</option>
                    <option v-for="s in filters.statuses" :key="s" :value="s">{{ statusLabel[s] ?? s }}</option>
                </select>
            </div>
            <div class="min-w-[220px] flex-1">
                <label class="block text-xs font-bold uppercase tracking-wider text-slate-500 mb-1">Buscar objetivo / KPI</label>
                <div class="relative">
                    <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input v-model="search" @keyup.enter="applyFilters" placeholder="Ej. colocación, EBITDA…"
                           class="h-10 w-full rounded-xl border border-slate-200 bg-white pl-9 pr-3 text-sm" />
                </div>
            </div>
            <button type="button" @click="clearFilters" class="inline-flex h-10 items-center gap-1 rounded-xl border border-slate-200 px-3 text-xs font-bold text-slate-500 hover:bg-slate-50">
                <X class="size-3.5" /> Limpiar filtros
            </button>
        </div>

        <!-- Cards ejecutivas -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <Target class="size-5 text-indigo-600" />
                <p class="mt-2 text-2xl font-black text-slate-950">{{ cards.active }}</p>
                <p class="text-xs font-bold text-slate-500">OKR activos</p>
            </div>
            <div class="rounded-2xl border border-amber-200 bg-amber-50/60 p-4 shadow-sm">
                <AlertTriangle class="size-5 text-amber-600" />
                <p class="mt-2 text-2xl font-black text-amber-700">{{ cards.risk }}</p>
                <p class="text-xs font-bold text-amber-700">OKR en riesgo</p>
            </div>
            <div class="rounded-2xl border border-rose-200 bg-rose-50/60 p-4 shadow-sm">
                <XCircle class="size-5 text-rose-600" />
                <p class="mt-2 text-2xl font-black text-rose-700">{{ cards.not_met }}</p>
                <p class="text-xs font-bold text-rose-700">No cumplidos</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <Gauge class="size-5 text-emerald-600" />
                <p class="mt-2 text-2xl font-black text-slate-950">{{ cards.avg_compliance }}%</p>
                <p class="text-xs font-bold text-slate-500">Cumplimiento promedio</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <Building2 class="size-5 text-indigo-600" />
                <p class="mt-2 text-2xl font-black text-slate-950">{{ cards.branches_with_okr }}/{{ cards.total_branches }}</p>
                <p class="text-xs font-bold text-slate-500">Sucursales con OKR</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                <Users class="size-5 text-indigo-600" />
                <p class="mt-2 text-2xl font-black text-slate-950">{{ cards.employees_with_okr }}/{{ cards.total_employees }}</p>
                <p class="text-xs font-bold text-slate-500">Colaboradores con OKR</p>
            </div>
        </div>

        <!-- Listado -->
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[900px] text-sm">
                <thead class="bg-slate-900 text-white">
                    <tr>
                        <th class="px-4 py-3 text-left font-bold">Objective</th>
                        <th class="px-4 py-3 text-left font-bold">Sucursal / Colaborador</th>
                        <th class="px-4 py-3 text-left font-bold">Responsable</th>
                        <th class="px-4 py-3 text-left font-bold">Plazo</th>
                        <th class="px-4 py-3 text-left font-bold">Progreso</th>
                        <th class="px-4 py-3 text-left font-bold">Estado</th>
                        <th class="px-4 py-3 text-left font-bold">Semáforo</th>
                        <th class="px-4 py-3 text-left font-bold">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="o in sortedObjectives" :key="o.id" class="border-t border-slate-100 hover:bg-slate-50">
                        <td class="px-4 py-3 font-bold text-slate-900">{{ o.title }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ o.branch ?? o.employee ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ o.responsible ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">Semana {{ o.current_week }}/{{ o.duration_weeks }}</td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <div class="h-2 w-24 overflow-hidden rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-indigo-600" :style="{ width: Math.min(100, o.compliance) + '%' }" />
                                </div>
                                <span class="text-xs font-bold text-slate-600">{{ o.compliance }}%</span>
                            </div>
                        </td>
                        <td class="px-4 py-3"><span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-600">{{ statusLabel[o.lifecycle_status] ?? o.lifecycle_status }}</span></td>
                        <td class="px-4 py-3">
                            <span v-if="o.health_status" :class="healthColor[o.health_status]" class="rounded-full px-2 py-0.5 text-xs font-bold">{{ healthLabel[o.health_status] }}</span>
                            <span v-else class="text-xs text-slate-400">—</span>
                        </td>
                        <td class="px-4 py-3">
                            <Link :href="`/okr/${o.id}`" class="text-xs font-bold text-indigo-600 hover:underline">Ver</Link>
                        </td>
                    </tr>
                    <tr v-if="sortedObjectives.length === 0">
                        <td colspan="8" class="px-4 py-10 text-center text-sm text-slate-400">No hay OKR que coincidan con los filtros.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
