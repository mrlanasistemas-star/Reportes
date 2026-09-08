<script setup lang="ts">
// Módulo OKR — Histórico de OKR cerrados (rediseño 08-sep-2026). Ancho
// completo del contenedor, igual que el resto del módulo — nunca max-w-6xl.
import { onMounted, reactive, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { ArrowLeft, History as HistoryIcon } from 'lucide-vue-next'
import AppLayout from '@/layouts/AppLayout.vue'
import AppEmptyState from '@/components/app/AppEmptyState.vue'
import SelectField from '@/components/forms/SelectField.vue'
import SearchableSelect from '@/components/forms/SearchableSelect.vue'
import OkrStatusBadge from '@/components/okr/OkrStatusBadge.vue'
import { formatFriendlyDate } from '@/lib/okrFormat'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    objectives: { data: any[]; links: any[] }
    filters: { branches: { id: number; name: string }[] }
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

const employeeOptions = ref<{ id: number; full_name: string }[]>([])
async function loadEmployees() {
    const res = await fetch(`/okr/employees-lookup${branchId.value ? `?branch_id=${branchId.value}` : ''}`, { headers: { Accept: 'application/json' } })
    const data = await res.json()
    employeeOptions.value = data.employees ?? []
}
onMounted(loadEmployees)
watch(branchId, () => { employeeId.value = ''; loadEmployees() })
</script>

<template>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <div class="flex items-center gap-3">
            <Link href="/okr" class="text-muted-foreground transition hover:text-foreground"><ArrowLeft class="size-5" /></Link>
            <div>
                <h1 class="flex items-center gap-2 text-xl font-bold tracking-tight text-foreground"><HistoryIcon class="size-5 text-primary" /> Histórico de OKR</h1>
                <p class="text-sm text-muted-foreground">Objetivos ya cerrados, con su resultado final.</p>
            </div>
        </div>

        <div class="app-card grid gap-4 p-5 sm:grid-cols-3">
            <SelectField v-model="branchId" label="Sucursal" placeholder="Todas" :options="[{ value: '', label: 'Todas' }, ...filters.branches.map((b) => ({ value: String(b.id), label: b.name }))]" @update:model-value="applyFilters" />
            <SearchableSelect v-model="employeeId as any" label="Colaborador" placeholder="Todos" :options="employeeOptions" label-key="full_name" secondary-key="__none" allow-null null-label="Todos" @change="applyFilters" />
            <SelectField
                v-model="finalStatus"
                label="Resultado"
                placeholder="Cualquiera"
                :options="[
                    { value: '', label: 'Cualquiera' },
                    { value: 'completed', label: 'Cumplido' },
                    { value: 'partially_completed', label: 'Parcialmente cumplido' },
                    { value: 'not_completed', label: 'No cumplido' },
                ]"
                @update:model-value="applyFilters"
            />
        </div>

        <div class="app-table-wrap">
            <div class="app-table-content">
                <table v-if="objectives.data.length" class="w-full min-w-[860px] text-sm">
                    <thead class="border-b border-border bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">Objective</th>
                            <th class="px-4 py-3 text-left font-semibold">Sucursal / Colaborador</th>
                            <th class="px-4 py-3 text-left font-semibold">Responsable</th>
                            <th class="px-4 py-3 text-left font-semibold">Cerrado</th>
                            <th class="px-4 py-3 text-left font-semibold">Resultado</th>
                            <th class="px-4 py-3 text-left font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="o in objectives.data" :key="o.id" class="border-t border-border transition-colors hover:bg-muted/30">
                            <td class="px-4 py-3 font-semibold text-foreground">{{ o.title }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ o.branch?.name ?? o.employee?.full_name ?? '—' }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ o.responsible_user?.name ?? '—' }}</td>
                            <td class="px-4 py-3 text-muted-foreground">{{ formatFriendlyDate(o.closed_at?.slice(0, 10)) }}</td>
                            <td class="px-4 py-3"><OkrStatusBadge kind="final" :value="o.final_status" /></td>
                            <td class="px-4 py-3"><Link :href="`/okr/${o.id}`" class="text-xs font-bold text-primary hover:underline">Ver detalle →</Link></td>
                        </tr>
                    </tbody>
                </table>

                <AppEmptyState v-else title="No hay OKR cerrados" message="Cuando un OKR termine su plazo y se cierre, aparecerá aquí con su resultado final." />
            </div>

            <div v-if="objectives.links?.length > 3" class="flex flex-wrap gap-1 border-t border-border p-4">
                <Link
                    v-for="(link, i) in objectives.links" :key="i"
                    :href="link.url ?? '#'"
                    class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                    :class="[link.active ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted', !link.url ? 'pointer-events-none opacity-40' : '']"
                    v-html="link.label"
                />
            </div>
        </div>
    </div>
</template>
