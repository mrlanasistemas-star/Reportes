<script setup lang="ts">
import { ref } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { ArrowLeft } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    objectives: { data: any[]; links: any[] }
    filters: { branches: { id: number; name: string }[]; employees: { id: number; full_name: string }[] }
    query: { branch_id?: string; employee_id?: string; final_status?: string }
}>()

const branchId = ref(props.query.branch_id ?? '')
const employeeId = ref(props.query.employee_id ?? '')
const finalStatus = ref(props.query.final_status ?? '')

function applyFilters() {
    router.get('/okr/history', {
        branch_id: branchId.value || undefined, employee_id: employeeId.value || undefined, final_status: finalStatus.value || undefined,
    }, { preserveState: true, replace: true })
}

const finalStatusLabel: Record<string, string> = { completed: 'Cumplido', partially_completed: 'Parcialmente cumplido', not_completed: 'No cumplido' }
const finalStatusColor: Record<string, string> = { completed: 'bg-emerald-100 text-emerald-700', partially_completed: 'bg-amber-100 text-amber-700', not_completed: 'bg-rose-100 text-rose-700' }
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-6 p-4 sm:p-6">
        <div class="flex items-center gap-3">
            <Link href="/okr" class="text-slate-400 hover:text-slate-700"><ArrowLeft class="size-5" /></Link>
            <h1 class="text-xl font-black text-slate-950">Histórico de OKR</h1>
        </div>

        <div class="flex flex-wrap gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <select v-model="branchId" @change="applyFilters" class="h-10 rounded-xl border border-slate-200 px-3 text-sm">
                <option value="">Todas las sucursales</option>
                <option v-for="b in filters.branches" :key="b.id" :value="b.id">{{ b.name }}</option>
            </select>
            <select v-model="employeeId" @change="applyFilters" class="h-10 rounded-xl border border-slate-200 px-3 text-sm">
                <option value="">Todos los colaboradores</option>
                <option v-for="e in filters.employees" :key="e.id" :value="e.id">{{ e.full_name }}</option>
            </select>
            <select v-model="finalStatus" @change="applyFilters" class="h-10 rounded-xl border border-slate-200 px-3 text-sm">
                <option value="">Cualquier resultado</option>
                <option value="completed">Cumplido</option>
                <option value="partially_completed">Parcialmente cumplido</option>
                <option value="not_completed">No cumplido</option>
            </select>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[800px] text-sm">
                <thead class="bg-slate-900 text-white">
                    <tr>
                        <th class="px-4 py-3 text-left font-bold">Objective</th>
                        <th class="px-4 py-3 text-left font-bold">Sucursal / Colaborador</th>
                        <th class="px-4 py-3 text-left font-bold">Responsable</th>
                        <th class="px-4 py-3 text-left font-bold">Cerrado</th>
                        <th class="px-4 py-3 text-left font-bold">Resultado</th>
                        <th class="px-4 py-3 text-left font-bold"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="o in objectives.data" :key="o.id" class="border-t border-slate-100 hover:bg-slate-50">
                        <td class="px-4 py-3 font-bold text-slate-900">{{ o.title }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ o.branch?.name ?? o.employee?.full_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ o.responsible_user?.name ?? '—' }}</td>
                        <td class="px-4 py-3 text-slate-600">{{ o.closed_at?.slice(0, 10) }}</td>
                        <td class="px-4 py-3"><span :class="finalStatusColor[o.final_status]" class="rounded-full px-2 py-0.5 text-xs font-bold">{{ finalStatusLabel[o.final_status] ?? '—' }}</span></td>
                        <td class="px-4 py-3"><Link :href="`/okr/${o.id}`" class="text-xs font-bold text-indigo-600 hover:underline">Ver</Link></td>
                    </tr>
                    <tr v-if="objectives.data.length === 0">
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-slate-400">No hay OKR cerrados con estos filtros.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
