<script setup lang="ts">
// Paso 1 — selección de gestores con OKR individual (14-oct-2026). Solo
// muestra colaboradores VIGENTES de la sucursal (nómina procesada, ver
// OkrEmployeeBranchResolver). No obliga a elegir a todos: solo quien se
// marque recibe un OKR individual; el resto sigue aportando a la sucursal.
import { AlertTriangle, CheckCircle2, Info, MapPin, Search, UserPlus, UserX, X } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import { Input } from '@/components/ui/input'
import OkrBranchConflictNotice from './OkrBranchConflictNotice.vue'
import type { BranchConflict } from './OkrBranchConflictNotice.vue'
import { avatarTone, initialsOf } from './okrWizard'
import type { GestorPlan } from './okrWizard'

type Candidate = {
    id: number
    full_name: string
    position?: string | null
    employee_code?: string | null
    route?: string | null
    membership_source?: string | null
    flags?: { code: string; label: string }[]
    branch_conflict?: BranchConflict | null
}

const props = defineProps<{
    employees: Candidate[]
    branchName?: string | null
    selected: GestorPlan[]
    loading: boolean
    rosterPeriod: string | null
    branchSelected: boolean
    canReconcile?: boolean
}>()
const emit = defineEmits<{ toggle: [employee: { id: number; full_name: string; position?: string | null }]; remove: [index: number]; search: [term: string]; reconciled: [] }>()

const term = ref('')
const selectedIds = computed(() => new Set(props.selected.map((g) => g.employee_id)))

function onSearch(value: string) {
    term.value = value
    emit('search', value)
}
</script>

<template>
    <div class="space-y-3 animate-in fade-in slide-in-from-top-2 duration-300">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <p class="text-sm font-semibold text-foreground">¿Quiénes tendrán OKR individual?</p>
                <p class="text-xs text-muted-foreground">Marca solo a los gestores con meta propia. Los demás siguen sumando a la meta de sucursal.</p>
            </div>
            <span class="rounded-full bg-primary/10 px-2.5 py-1 text-[11px] font-bold text-primary">{{ selected.length }} seleccionado(s)</span>
        </div>

        <div class="relative">
            <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input :model-value="term" :disabled="!branchSelected" placeholder="Buscar gestor por nombre..." class="h-10 rounded-lg pl-9 text-sm" @update:model-value="(v) => onSearch(String(v))" />
        </div>

        <div v-if="!branchSelected" class="rounded-xl border border-dashed border-border p-6 text-center text-xs text-muted-foreground">Primero elige la sucursal.</div>

        <div v-else-if="loading" class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <div v-for="n in 4" :key="n" class="h-14 app-skeleton rounded-xl bg-muted/60" />
        </div>

        <div v-else class="grid max-h-64 grid-cols-1 gap-2 overflow-y-auto pr-1 sm:grid-cols-2">
            <div v-for="e in employees" :key="e.id" class="flex flex-col gap-1">
            <button
                type="button" :disabled="!!e.branch_conflict"
                class="group flex items-center gap-3 rounded-xl border p-2.5 text-left transition-all duration-200 enabled:hover:-translate-y-0.5 enabled:hover:shadow-md disabled:cursor-not-allowed disabled:opacity-80"
                :class="e.branch_conflict ? 'border-amber-300/70 bg-card' : selectedIds.has(e.id) ? 'border-primary/50 bg-primary/5 ring-1 ring-primary/30' : 'border-border bg-card hover:border-primary/30'"
                @click="!e.branch_conflict && emit('toggle', e)"
            >
                <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-[11px] font-bold text-white shadow-sm transition-transform duration-200 group-hover:scale-105" :class="avatarTone(e.id)">
                    {{ initialsOf(e.full_name) }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-xs font-semibold text-foreground">{{ e.full_name }}</span>
                    <span class="block truncate text-[10px] text-muted-foreground">{{ e.position ?? 'Puesto no registrado' }}<template v-if="e.employee_code"> · {{ e.employee_code }}</template></span>
                    <span class="flex items-center gap-1 truncate text-[10px] text-muted-foreground" :title="e.membership_source ?? ''">
                        <MapPin class="size-3 shrink-0" /> <template v-if="e.route">Ruta {{ e.route }} → </template>{{ branchName ?? '' }}
                    </span>
                    <span
                        v-for="f in (e.flags ?? []).filter((x) => x.code !== 'branch_conflict')" :key="f.code"
                        class="mt-0.5 flex items-center gap-1 truncate text-[10px] font-semibold"
                        :class="f.code === 'baja_sin_noi' ? 'text-rose-700 dark:text-rose-300' : 'text-amber-700 dark:text-amber-300'"
                    >
                        <UserX v-if="f.code === 'baja_sin_noi'" class="size-3 shrink-0" />
                        <AlertTriangle v-else class="size-3 shrink-0" />
                        {{ f.label }}
                    </span>
                    <span class="block text-[10px] font-semibold" :class="selectedIds.has(e.id) ? 'text-primary' : 'text-muted-foreground/70'">{{ e.branch_conflict ? 'Sucursal por conciliar' : selectedIds.has(e.id) ? 'Con OKR individual' : 'Clic para asignar meta propia' }}</span>
                </span>
                <CheckCircle2 v-if="selectedIds.has(e.id)" class="size-5 shrink-0 text-primary animate-in zoom-in duration-200" />
                <UserPlus v-else class="size-4 shrink-0 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100" />
            </button>
            <OkrBranchConflictNotice
                v-if="e.branch_conflict" :conflict="e.branch_conflict" :employee-id="e.id" :employee-name="e.full_name" :can-reconcile="!!canReconcile"
                @reconciled="emit('reconciled')"
            />
            </div>
            <p v-if="!employees.length" class="col-span-full rounded-xl border border-dashed border-border p-5 text-center text-xs text-muted-foreground">
                No hay colaboradores vigentes que coincidan en esta sucursal.
            </p>
        </div>

        <p class="flex items-start gap-1.5 text-[11px] text-muted-foreground">
            <Info class="mt-0.5 size-3.5 shrink-0" />
            <span>Aparecen los colaboradores vigentes de esta sucursal (nómina<template v-if="rosterPeriod"> {{ rosterPeriod }}</template>, directorio y cartera Lendus; rutas traducidas con el catálogo oficial). No aparecen vacantes ni bajas confirmadas. Quien no recibió pago de nómina NOI en el periodo se marca en rojo como <strong>Baja</strong> del mes, pero puedes seleccionarlo igual.</span>
        </p>

        <TransitionGroup v-if="selected.length" tag="div" class="space-y-2" enter-active-class="transition duration-300" enter-from-class="opacity-0 -translate-y-1" leave-active-class="transition duration-200" leave-to-class="opacity-0 translate-x-2">
            <div v-for="(row, i) in selected" :key="row.client_key" class="flex items-start gap-2.5 rounded-xl border border-border bg-muted/20 p-2.5 transition hover:border-primary/40">
                <span class="mt-1 flex size-8 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-[10px] font-bold text-white" :class="avatarTone(row.employee_id)">{{ initialsOf(row.employee_name) }}</span>
                <div class="min-w-0 flex-1">
                    <p class="mb-1 truncate text-xs font-semibold text-foreground">{{ row.employee_name }}</p>
                    <Input v-model="row.title" maxlength="500" placeholder="Objetivo individual (mín. 10 caracteres)" class="h-9 rounded-lg text-xs" />
                    <p v-if="row.title.trim().length > 0 && row.title.trim().length < 10" class="mt-0.5 text-[10px] font-semibold text-amber-600">Mínimo 10 caracteres.</p>
                </div>
                <button type="button" class="mt-1 shrink-0 rounded-full p-1 text-muted-foreground transition hover:rotate-90 hover:bg-destructive/10 hover:text-destructive" :aria-label="`Quitar a ${row.employee_name}`" @click="emit('remove', i)"><X class="size-4" /></button>
            </div>
        </TransitionGroup>
    </div>
</template>
