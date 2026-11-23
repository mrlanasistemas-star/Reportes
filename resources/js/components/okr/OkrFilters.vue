<script setup lang="ts">
/**
 * OkrFilters — barra de filtros del tablero OKR (reproduce
 * docs/imagenesOKR/1.png): UNA fila (Sucursal, Colaborador, Estatus, Periodo,
 * Buscar, Limpiar filtros). Los filtros adicionales exigidos por el
 * PDF/backend (KPI, Responsable, fechas) viven detrás de "Más filtros", sin
 * romper esta composición. Se usa en pages/Okr/Dashboard.
 *
 * Props:
 * - branches, statuses, kpis, responsibles, periods: opciones de cada filtro.
 *
 * v-model:
 * - (por defecto) estado de los filtros (FilterState).
 *
 * Emits:
 * - apply: aplicar los filtros actuales.
 * - clear: limpiar todos los filtros.
 */
import { ChevronDown, Search, SlidersHorizontal, X } from 'lucide-vue-next';
import { onMounted, ref, watch } from 'vue';
import DatePickerField from '@/components/forms/DatePickerField.vue';
import SearchableSelect from '@/components/forms/SearchableSelect.vue';
import SelectField from '@/components/forms/SelectField.vue';
import { Button } from '@/components/ui/button';

/** Opción genérica de catálogo (sucursal, KPI, responsable). */
type Option = { id: number; name?: string; full_name?: string };
/** Valores de los filtros ('' = sin filtro). */
type FilterState = {
    branch_id: string;
    employee_id: string;
    status: string;
    search: string;
    period_id: string;
    start_date: string | null;
    end_date: string | null;
    kpi_id: string;
    responsible_user_id: string;
};

defineProps<{
    branches: Option[];
    statuses: string[];
    kpis: Option[];
    responsibles: Option[];
    periods: { id: number; label: string }[];
}>();

const emit = defineEmits<{ (e: 'apply'): void; (e: 'clear'): void }>();

const model = defineModel<FilterState>({ required: true });

/** Etiqueta en español de cada estatus. */
const statusLabel: Record<string, string> = {
    draft: 'Borrador',
    active: 'Activo',
    closed: 'Cerrado',
    cancelled: 'Cancelado',
};

/** Colaboradores que ofrece el buscador. */
const employeeOptions = ref<{ id: number; full_name: string }[]>([]);
let employeeSearchTimer: ReturnType<typeof setTimeout> | null = null;
// Parte C del cierre (17-sep-2026): token de versión — sin esto, una respuesta
// vieja que tarda más que una más nueva (ej. "Ana" tarda más que "Ana P") podía
// pisar el resultado correcto y dejar opciones equivocadas en el buscador.
let employeeSearchVersion = 0;

/**
 * Busca colaboradores (de la sucursal filtrada, si hay) en /okr/employees-lookup;
 * descarta respuestas que lleguen tarde.
 * @param search texto de búsqueda.
 */
async function loadEmployees(search = '') {
    const params = new URLSearchParams();

    if (model.value.branch_id) {
        params.set('branch_id', model.value.branch_id);
    }

    if (search) {
        params.set('search', search);
    }

    const myVersion = ++employeeSearchVersion;

    try {
        const res = await fetch(`/okr/employees-lookup?${params.toString()}`, {
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        });

        if (myVersion !== employeeSearchVersion) {
            return;
        } // llegó tarde — una búsqueda más nueva ya ganó

        if (!res.ok) {
            return;
        }

        const json = await res.json();

        if (myVersion !== employeeSearchVersion) {
            return;
        }

        employeeOptions.value = json.employees ?? [];
    } catch {
        // Búsqueda opcional — un fallo de red no debe romper el resto del dashboard.
    }
}
/**
 * Busca colaboradores con una espera de 250 ms tras dejar de escribir.
 * @param term texto de búsqueda.
 */
function onEmployeeSearch(term: string) {
    if (employeeSearchTimer) {
        clearTimeout(employeeSearchTimer);
    }

    employeeSearchTimer = setTimeout(() => loadEmployees(term), 250);
}

/** Carga inicial de colaboradores. */
onMounted(() => loadEmployees());
/** Al cambiar la sucursal limpia el colaborador y recarga la lista. */
watch(
    () => model.value.branch_id,
    () => {
        model.value.employee_id = '';
        loadEmployees();
    },
);

/** Pide al padre aplicar los filtros. */
function apply() {
    emit('apply');
}
/**
 * El colaborador elegido pertenece a la sucursal anterior: se limpia ANTES de
 * aplicar (el watch corre después y la consulta viajaba con ese colaborador).
 */
function onBranchChange() {
    model.value.employee_id = '';
    apply();
}
/** Pide al padre limpiar los filtros. */
function clear() {
    emit('clear');
}

/** Si el panel "Más filtros" está abierto. */
const showMore = ref(false);
</script>

<template>
    <div class="app-card p-3">
        <div class="flex flex-col gap-3 lg:flex-row lg:items-end">
            <div
                class="grid flex-1 grid-cols-1 gap-3 min-[480px]:grid-cols-2 sm:grid-cols-3 lg:grid-cols-5"
            >
                <SelectField
                    v-model="model.branch_id"
                    label="Sucursal"
                    placeholder="Todas las sucursales"
                    :options="[
                        { value: '', label: 'Todas las sucursales' },
                        ...branches.map((b) => ({
                            value: String(b.id),
                            label: b.name!,
                        })),
                    ]"
                    @update:model-value="onBranchChange"
                />
                <SearchableSelect
                    v-model="model.employee_id"
                    label="Colaborador"
                    placeholder="Todos los colaboradores"
                    search-placeholder="Buscar colaborador..."
                    :options="employeeOptions"
                    label-key="full_name"
                    secondary-key="__none"
                    allow-null
                    null-label="Todos los colaboradores"
                    @update:search="onEmployeeSearch"
                    @change="apply"
                />
                <SelectField
                    v-model="model.status"
                    label="Estatus"
                    placeholder="Todos los estatus"
                    :options="[
                        { value: '', label: 'Todos los estatus' },
                        ...statuses.map((s) => ({
                            value: s,
                            label: statusLabel[s] ?? s,
                        })),
                    ]"
                    @update:model-value="apply"
                />
                <SelectField
                    v-model="model.period_id"
                    label="Periodo"
                    placeholder="Periodo actual"
                    :options="[
                        { value: '', label: 'Periodo actual' },
                        ...periods.map((p) => ({
                            value: String(p.id),
                            label: p.label,
                        })),
                    ]"
                    @update:model-value="apply"
                />
                <div
                    class="w-full space-y-1.5 min-[480px]:col-span-2 sm:col-span-3 lg:col-span-1"
                >
                    <label
                        for="okr-filter-search"
                        class="text-sm font-semibold text-foreground"
                        >Buscar</label
                    >
                    <div class="relative">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <input
                            id="okr-filter-search"
                            v-model="model.search"
                            type="search"
                            autocomplete="off"
                            placeholder="Objetivo, colaborador o KPI..."
                            class="app-input pl-9"
                            @keyup.enter="apply"
                        />
                    </div>
                </div>
            </div>
            <div class="flex shrink-0 gap-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="h-11 gap-1.5 rounded-xl"
                    :aria-expanded="showMore ? 'true' : 'false'"
                    aria-controls="okr-more-filters"
                    @click="showMore = !showMore"
                >
                    <SlidersHorizontal class="size-3.5" aria-hidden="true" />
                    Más filtros
                    <ChevronDown
                        class="size-3.5 transition-transform"
                        :class="showMore ? 'rotate-180' : ''"
                        aria-hidden="true"
                    />
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="h-11 gap-1.5 rounded-xl text-muted-foreground"
                    @click="clear"
                >
                    <X class="size-3.5" aria-hidden="true" /> Limpiar filtros
                </Button>
            </div>
        </div>

        <!-- Más filtros: KPI, responsable y fechas -->
        <div
            v-if="showMore"
            id="okr-more-filters"
            class="mt-3 grid grid-cols-1 gap-3 border-t border-border pt-3 sm:grid-cols-2 lg:grid-cols-4"
        >
            <SelectField
                v-model="model.kpi_id"
                label="KPI"
                placeholder="Todos"
                :options="[
                    { value: '', label: 'Todos' },
                    ...kpis.map((k) => ({
                        value: String(k.id),
                        label: k.name!,
                    })),
                ]"
                @update:model-value="apply"
            />
            <SelectField
                v-model="model.responsible_user_id"
                label="Responsable"
                placeholder="Todos"
                :options="[
                    { value: '', label: 'Todos' },
                    ...responsibles.map((u) => ({
                        value: String(u.id),
                        label: u.name!,
                    })),
                ]"
                @update:model-value="apply"
            />
            <!-- Rango coherente: el calendario no deja elegir un término anterior al inicio (ni al revés). -->
            <DatePickerField
                v-model="model.start_date"
                label="Fecha inicio"
                :max="model.end_date"
                clearable
                @update:model-value="apply"
            />
            <DatePickerField
                v-model="model.end_date"
                label="Fecha término"
                :min="model.start_date"
                clearable
                @update:model-value="apply"
            />
        </div>
    </div>
</template>
