<script setup lang="ts">
/**
 * OkrScopeFilters — filtros de seguimiento en pages/Okr/Show (15-oct-2026):
 * sucursal → solo sus OKR; colaborador → los OKR en los que REALMENTE
 * participa (incluye excolaboradores, marcados "(baja)", porque es consulta
 * histórica, nunca asignación); periodo y búsqueda de OKR. Al elegir otro OKR
 * navega a /okr/{id}. Siempre indica si se ve la sucursal completa o un
 * gestor individual.
 *
 * Props:
 * - objective: OKR que se está viendo.
 * - branches: sucursales para el filtro.
 * - periods: periodos para el filtro.
 */
import { router } from '@inertiajs/vue3';
import { Building2, Eye, UserRound, X } from 'lucide-vue-next';
import { onMounted, ref, watch } from 'vue';
import SearchableSelect from '@/components/forms/SearchableSelect.vue';
import SelectField from '@/components/forms/SelectField.vue';
import { Button } from '@/components/ui/button';
import { okrRequest } from '@/lib/okrHttp';

const props = defineProps<{
    objective: any;
    branches: { id: number; name: string }[];
    periods: { id: number; label: string }[];
}>();

/** Sucursal filtrada (inicia con la del OKR actual). */
const branchId = ref<number | null>(props.objective.branch?.id ?? null);
/** Colaborador filtrado. */
const employeeId = ref<number | null>(null);
/** Periodo filtrado ('' = todos). */
const periodId = ref<string>('');
/** OKR elegido en el selector. */
const objectiveId = ref<number>(props.objective.id);
/** Colaboradores participantes disponibles para el filtro. */
const employees = ref<{ id: number; full_name: string }[]>([]);
/** OKR que cumplen los filtros (inicia con el actual). */
const objectives = ref<{ id: number; title: string; label?: string }[]>([
    {
        id: props.objective.id,
        title: props.objective.title,
        label: props.objective.title,
    },
]);
/** true mientras se buscan OKR. */
const loadingObjectives = ref(false);
/** Texto de búsqueda de OKR; timer de espera; contadores para ignorar respuestas viejas. */
let objectiveSearch = '';
let timer: ReturnType<typeof setTimeout> | null = null;
let ev = 0;
let ov = 0;

/** Carga los colaboradores (modo histórico) de la sucursal filtrada. */
async function loadEmployees() {
    const mine = ++ev;
    const params = new URLSearchParams({ mode: 'history' });

    if (branchId.value) {
        params.set('branch_id', String(branchId.value));
    }

    const res = await okrRequest(`/okr/employees-lookup?${params.toString()}`);

    if (mine === ev) {
        employees.value = res.ok ? (res.data.employees ?? []) : [];
    }
}

/** Carga los OKR que cumplen los filtros y la búsqueda. */
async function loadObjectives() {
    const mine = ++ov;
    const params = new URLSearchParams();

    if (branchId.value) {
        params.set('branch_id', String(branchId.value));
    }

    if (employeeId.value) {
        params.set('employee_id', String(employeeId.value));
    }

    if (periodId.value) {
        params.set('period_id', periodId.value);
    }

    if (objectiveSearch) {
        params.set('search', objectiveSearch);
    }

    loadingObjectives.value = true;
    const res = await okrRequest(`/okr/objectives-lookup?${params.toString()}`);

    if (mine === ov) {
        loadingObjectives.value = false;
        objectives.value = res.ok ? (res.data.objectives ?? []) : [];
    }
}

/** Carga inicial de colaboradores y OKR. */
onMounted(() => {
    loadEmployees();
    loadObjectives();
});
/** Al cambiar la sucursal limpia el colaborador y recarga ambas listas. */
watch(branchId, () => {
    employeeId.value = null;
    loadEmployees();
    loadObjectives();
});
/** Al cambiar colaborador o periodo recarga los OKR. */
watch([employeeId, periodId], loadObjectives);

/**
 * Busca OKR con una espera de 250 ms tras dejar de escribir.
 * @param term texto de búsqueda.
 */
function onSearch(term: string) {
    objectiveSearch = term;

    if (timer) {
        clearTimeout(timer);
    }

    timer = setTimeout(loadObjectives, 250);
}

/**
 * Navega al OKR elegido si es distinto del actual.
 * @param id id del OKR elegido.
 */
function go(id: number | string | null) {
    if (id && Number(id) !== props.objective.id) {
        router.get(`/okr/${id}`);
    }
}

/** Limpia todos los filtros y recarga las listas. */
function clear() {
    branchId.value = null;
    employeeId.value = null;
    periodId.value = '';
    objectiveSearch = '';
    loadEmployees();
    loadObjectives();
}
</script>

<template>
    <section class="rounded-2xl border border-border bg-card p-3 shadow-sm">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
            <div
                class="grid flex-1 grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
            >
                <SelectField
                    v-model="branchId as any"
                    label="Sucursal"
                    placeholder="Todas"
                    :options="[
                        { value: '', label: 'Todas' },
                        ...branches.map((b) => ({
                            value: b.id,
                            label: b.name,
                        })),
                    ]"
                />
                <SearchableSelect
                    v-model="employeeId as any"
                    label="Colaborador (participantes)"
                    placeholder="Todos"
                    :options="employees"
                    label-key="full_name"
                    secondary-key="__none"
                    allow-null
                    null-label="Todos"
                />
                <SearchableSelect
                    v-model="objectiveId as any"
                    label="Objective / OKR"
                    :options="objectives"
                    label-key="label"
                    secondary-key="__none"
                    @update:search="onSearch"
                    @change="go"
                />
                <SelectField
                    v-model="periodId"
                    label="Periodo"
                    placeholder="Todos"
                    :options="[
                        { value: '', label: 'Todos' },
                        ...periods.map((p) => ({
                            value: String(p.id),
                            label: p.label,
                        })),
                    ]"
                />
            </div>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="h-11 shrink-0 gap-1.5 rounded-xl text-muted-foreground"
                @click="clear"
                ><X class="size-3.5" aria-hidden="true" /> Limpiar</Button
            >
        </div>
        <!-- "Estás viendo": sucursal completa o gestor individual -->
        <div
            class="mt-2.5 flex flex-wrap items-center gap-2 rounded-xl bg-muted/40 px-3 py-2 text-xs"
        >
            <Eye class="size-3.5 text-muted-foreground" aria-hidden="true" />
            <span class="text-muted-foreground">Estás viendo:</span>
            <span
                v-if="objective.scope_type === 'branch'"
                class="flex items-center gap-1 font-bold text-foreground"
                ><Building2 class="size-3.5 text-primary" /> Sucursal completa —
                {{ objective.branch?.name }}</span
            >
            <span
                v-else
                class="flex items-center gap-1 font-bold text-foreground"
                ><UserRound class="size-3.5 text-primary" /> Gestor individual —
                {{ objective.employee?.full_name }}</span
            >
            <span
                v-if="employeeId && objectives.length"
                class="text-muted-foreground"
                >· {{ objectives.length }} OKR donde participa el colaborador
                elegido (elige uno para abrirlo)</span
            >
            <span v-if="loadingObjectives" class="text-muted-foreground"
                >· buscando…</span
            >
        </div>
    </section>
</template>
