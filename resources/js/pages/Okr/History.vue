<script setup lang="ts">
/**
 * Pantalla: HISTÓRICO DE OKR (/okr/history) — módulo OKR (rediseño 08-sep-2026).
 * Ancho completo del contenedor, igual que el resto del módulo — nunca max-w-6xl.
 *
 * Para qué sirve: lista paginada (20 por página) de objetivos YA CERRADOS
 * con su resultado final, filtrable por sucursal, colaborador y resultado.
 *
 * Origen de los datos: `App\Http\Controllers\Okr\HistoryController@index`
 * renderiza `Okr/History` con `objectives` (paginador Laravel, ya filtrado por
 * el alcance del usuario con applyScope), `filters.branches` y `query` (filtros
 * vigentes en la URL).
 *
 * Rutas que usa: GET /okr/history (recarga con filtros), GET
 * /okr/employees-lookup (colaboradores bajo demanda) y /okr/{id} (detalle).
 */
import { Link, router } from '@inertiajs/vue3';
import { ArrowLeft, History as HistoryIcon } from 'lucide-vue-next';
import { onMounted, ref, watch } from 'vue';
import AppEmptyState from '@/components/app/AppEmptyState.vue';
import AppPageHeader from '@/components/app/AppPageHeader.vue';
import SearchableSelect from '@/components/forms/SearchableSelect.vue';
import SelectField from '@/components/forms/SelectField.vue';
import OkrStatusBadge from '@/components/okr/OkrStatusBadge.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatFriendlyDate } from '@/lib/okrFormat';

defineOptions({ layout: AppLayout });

/**
 * Props de HistoryController@index.
 * - objectives: página actual del paginador (data + links de paginación).
 * - filters.branches: sucursales disponibles para el filtro.
 * - query: filtros que vienen en la URL (para restaurar los selects).
 */
const props = defineProps<{
    objectives: { data: any[]; links: any[] };
    filters: { branches: { id: number; name: string }[] };
    query: { branch_id?: string; employee_id?: string; final_status?: string };
}>();

/** Filtro de sucursal (id como texto; '' = todas). */
const branchId = ref(props.query.branch_id ?? '');
/** Filtro de colaborador (id como texto; '' = todos). */
const employeeId = ref(props.query.employee_id ?? '');
/** Filtro de resultado final (completed / partially_completed / not_completed; '' = cualquiera). */
const finalStatus = ref(props.query.final_status ?? '');

/**
 * Cambio de sucursal: el colaborador elegido ya no aplica, así que se limpia
 * ANTES de consultar (el watch de abajo corre después y la petición viajaba
 * con el colaborador de la sucursal anterior → tabla vacía).
 */
function onBranchChange() {
    employeeId.value = '';
    applyFilters();
}

/**
 * Recarga la página con los filtros actuales (omite los vacíos), conservando
 * el estado local y reemplazando la entrada del historial.
 */
function applyFilters() {
    router.get(
        '/okr/history',
        {
            branch_id: branchId.value || undefined,
            employee_id: employeeId.value || undefined,
            final_status: finalStatus.value || undefined,
        },
        { preserveState: true, replace: true },
    );
}

/** Opciones del buscador de colaboradores (se cargan bajo demanda). */
const employeeOptions = ref<{ id: number; full_name: string }[]>([]);
/**
 * Parte C del cierre (17-sep-2026): token de versión — un cambio rápido de
 * sucursal no debe dejar que una respuesta vieja pise a la más nueva.
 */
let employeesVersion = 0;
/**
 * Consulta /okr/employees-lookup (filtrado por la sucursal elegida, sin caché)
 * y llena las opciones de colaborador. Descarta respuestas obsoletas comparando
 * el token de versión antes y después de leer el JSON.
 */
async function loadEmployees() {
    const myVersion = ++employeesVersion;
    const res = await fetch(
        `/okr/employees-lookup${branchId.value ? `?branch_id=${branchId.value}` : ''}`,
        { cache: 'no-store', headers: { Accept: 'application/json' } },
    );

    if (myVersion !== employeesVersion) {
        return;
    }

    const data = await res.json();

    if (myVersion !== employeesVersion) {
        return;
    }

    employeeOptions.value = data.employees ?? [];
}
// Carga inicial de colaboradores al montar la pantalla.
onMounted(loadEmployees);
/** Al cambiar la sucursal: limpia el colaborador y recarga la lista de colaboradores de esa sucursal. */
watch(branchId, () => {
    employeeId.value = '';
    loadEmployees();
});
</script>

<template>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <!-- Encabezado con regreso a OKR -->
        <AppPageHeader
            kicker="OKR"
            title="Histórico de OKR"
            subtitle="Objetivos ya cerrados, con su resultado final."
        >
            <template #icon>
                <Link
                    href="/okr"
                    class="flex size-full items-center justify-center rounded-xl text-muted-foreground transition hover:text-foreground"
                    aria-label="Volver a OKR"
                    title="Volver a OKR"
                    ><ArrowLeft class="size-5" aria-hidden="true"
                /></Link>
            </template>
            <template #actions>
                <span
                    class="inline-flex items-center gap-1.5 text-sm text-muted-foreground"
                    ><HistoryIcon
                        class="size-4 text-primary"
                        aria-hidden="true"
                    />
                    Solo OKR cerrados</span
                >
            </template>
        </AppPageHeader>

        <!-- Filtros: sucursal, colaborador y resultado -->
        <div class="app-card grid grid-cols-1 gap-4 p-5 sm:grid-cols-3">
            <SelectField
                v-model="branchId"
                label="Sucursal"
                placeholder="Todas"
                :options="[
                    { value: '', label: 'Todas' },
                    ...filters.branches.map((b) => ({
                        value: String(b.id),
                        label: b.name,
                    })),
                ]"
                @update:model-value="onBranchChange"
            />
            <SearchableSelect
                v-model="employeeId as any"
                label="Colaborador"
                placeholder="Todos"
                :options="employeeOptions"
                label-key="full_name"
                secondary-key="__none"
                allow-null
                null-label="Todos"
                @change="applyFilters"
            />
            <SelectField
                v-model="finalStatus"
                label="Resultado"
                placeholder="Cualquiera"
                :options="[
                    { value: '', label: 'Cualquiera' },
                    { value: 'completed', label: 'Cumplido' },
                    {
                        value: 'partially_completed',
                        label: 'Parcialmente cumplido',
                    },
                    { value: 'not_completed', label: 'No cumplido' },
                ]"
                @update:model-value="applyFilters"
            />
        </div>

        <!-- Tabla de OKR cerrados (o estado vacío) y paginación -->
        <div class="app-table-wrap">
            <div class="app-table-content">
                <table
                    v-if="objectives.data.length"
                    class="w-full min-w-[860px] text-sm"
                >
                    <thead
                        class="border-b border-border bg-muted/40 text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-4 py-3 text-left font-semibold">
                                Objective
                            </th>
                            <th class="px-4 py-3 text-left font-semibold">
                                Sucursal / Colaborador
                            </th>
                            <th class="px-4 py-3 text-left font-semibold">
                                Responsable
                            </th>
                            <th class="px-4 py-3 text-left font-semibold">
                                Cerrado
                            </th>
                            <th class="px-4 py-3 text-left font-semibold">
                                Resultado
                            </th>
                            <th class="px-4 py-3 text-left font-semibold"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="o in objectives.data"
                            :key="o.id"
                            class="border-t border-border transition-colors hover:bg-muted/30"
                        >
                            <td class="px-4 py-3 font-semibold text-foreground">
                                {{ o.title }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{
                                    o.branch?.name ??
                                    o.employee?.full_name ??
                                    '—'
                                }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ o.responsible_user?.name ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-muted-foreground">
                                {{ formatFriendlyDate(o.closed_at) }}
                            </td>
                            <td class="px-4 py-3">
                                <OkrStatusBadge
                                    kind="final"
                                    :value="o.final_status"
                                />
                            </td>
                            <td class="px-4 py-3">
                                <Link
                                    :href="`/okr/${o.id}`"
                                    class="text-xs font-bold text-primary hover:underline"
                                    >Ver detalle →</Link
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>

                <AppEmptyState
                    v-else
                    title="No hay OKR cerrados"
                    message="Cuando un OKR termine su plazo y se cierre, aparecerá aquí con su resultado final."
                />
            </div>

            <div
                v-if="objectives.links?.length > 3"
                class="flex flex-wrap gap-1 border-t border-border p-4"
            >
                <Link
                    v-for="(link, i) in objectives.links"
                    :key="i"
                    :href="link.url ?? '#'"
                    :aria-current="link.active ? 'page' : undefined"
                    :aria-disabled="!link.url ? 'true' : undefined"
                    :tabindex="!link.url ? -1 : undefined"
                    preserve-scroll
                    class="rounded-lg px-3 py-1.5 text-xs font-semibold transition"
                    :class="[
                        link.active
                            ? 'bg-primary text-primary-foreground'
                            : 'text-muted-foreground hover:bg-muted',
                        !link.url ? 'pointer-events-none opacity-40' : '',
                    ]"
                >
                    <span v-html="link.label" />
                </Link>
            </div>
        </div>
    </div>
</template>
