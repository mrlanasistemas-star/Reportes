<script setup lang="ts">
/**
 * Pantalla: CATÁLOGO DE KPI (/okr/kpis) — módulo OKR (rediseño 08-sep-2026,
 * sección AF del pedido). Ancho completo del contenedor — nunca max-w-5xl.
 *
 * Para qué sirve: listar las métricas disponibles para armar Key Results,
 * crear KPI nuevos y activar/desactivar los existentes.
 *
 * Acceso: el controlador exige el gate `okr.kpi.manage` (definido en
 * OkrServiceProvider; pensado solo para administradores).
 *
 * Origen de los datos: `App\Http\Controllers\Okr\KpiController@index`
 * renderiza `Okr/KpiCatalog` con `kpis` (tabla okr_kpis ordenada por nombre)
 * y `availableProviders` (claves de OkrKpiProviderRegistry).
 *
 * Rutas que usa: POST /okr/kpis (crear) y PUT /okr/kpis/{id} (activar/desactivar).
 */
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowDown, ArrowLeft, ArrowUp, Plus, Sparkles } from 'lucide-vue-next';
import { ref } from 'vue';
import AppEmptyState from '@/components/app/AppEmptyState.vue';
import AppPageHeader from '@/components/app/AppPageHeader.vue';
import { appSwal as Swal } from '@/components/feedback/ConfirmDialog';
import SelectField from '@/components/forms/SelectField.vue';
import TextField from '@/components/forms/TextField.vue';
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

/**
 * Props de KpiController@index.
 * - kpis: registros del catálogo okr_kpis.
 * - availableProviders: provider_key registrados (únicos valores válidos para
 *   KPI automáticos/híbridos; nunca texto libre).
 */
defineProps<{ kpis: any[]; availableProviders: string[] }>();

/** Muestra/oculta el formulario de "Nuevo KPI". */
const showCreate = ref(false);
/** Formulario Inertia del KPI nuevo (valores por defecto: moneda, acumulado, incrementar, manual). */
const form = useForm({
    code: '',
    name: '',
    description: '',
    unit: 'currency',
    type: 'cumulative',
    direction: 'increase',
    automation: 'manual',
    provider_key: '',
    is_active: true,
});

/**
 * Envía el KPI nuevo a POST /okr/kpis. Si el servidor lo acepta, limpia el
 * formulario, lo cierra y muestra un aviso breve de éxito. Los errores de
 * validación (StoreKpiRequest) quedan en form.errors.
 */
function submit() {
    form.post('/okr/kpis', {
        onSuccess: () => {
            form.reset();
            showCreate.value = false;
            Swal.fire({
                icon: 'success',
                title: 'KPI creado',
                timer: 1800,
                showConfirmButton: false,
            });
        },
    });
}

/** Id del KPI cuyo estado se está guardando; evita doble clic (todos los botones quedan deshabilitados). */
const togglingId = ref<number | null>(null);

/**
 * Activa/desactiva un KPI: reenvía TODOS sus campos con is_active invertido
 * vía PUT /okr/kpis/{id} (el update exige el registro completo).
 * Ignora el clic si ya hay otro cambio en curso.
 */
function toggleActive(kpi: any) {
    if (togglingId.value !== null) {
        return;
    }

    togglingId.value = kpi.id;
    useForm({
        code: kpi.code,
        name: kpi.name,
        description: kpi.description,
        unit: kpi.unit,
        type: kpi.type,
        direction: kpi.direction,
        automation: kpi.automation,
        provider_key: kpi.provider_key,
        is_active: !kpi.is_active,
    }).put(`/okr/kpis/${kpi.id}`, {
        preserveScroll: true,
        onFinish: () => (togglingId.value = null),
    });
}
</script>

<template>
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <!-- Encabezado con regreso a OKR y botón "Nuevo KPI" -->
        <AppPageHeader
            kicker="OKR"
            title="Catálogo de KPI"
            subtitle="Métricas disponibles para armar Key Results — automáticas (Reportería) o manuales."
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
                <Button
                    type="button"
                    class="h-11 gap-2 rounded-2xl"
                    :aria-expanded="showCreate ? 'true' : 'false'"
                    @click="showCreate = !showCreate"
                >
                    <Plus class="size-4" aria-hidden="true" /> Nuevo KPI
                </Button>
            </template>
        </AppPageHeader>

        <div
            v-if="showCreate"
            class="app-card animate-in space-y-4 p-5 duration-200 fade-in slide-in-from-top-2"
        >
            <!-- Formulario de alta de KPI -->
            <p
                class="flex items-center gap-1.5 text-sm font-bold text-foreground"
            >
                <Sparkles class="size-4 text-primary" aria-hidden="true" />
                Nuevo KPI
                <OkrHelpTooltip
                    text="El 'code' es la llave técnica estable (nunca cambia). 'provider' solo puede ser una fuente ya registrada en el sistema — nunca texto libre."
                />
            </p>
            <div class="grid gap-4 sm:grid-cols-2">
                <TextField
                    id="kpi-code"
                    v-model="form.code"
                    label="Code"
                    placeholder="Ej. new_metric"
                    autocomplete="off"
                    :maxlength="60"
                    description="Solo minúsculas, números y guion bajo."
                    :error="form.errors.code"
                    required
                />
                <TextField
                    id="kpi-name"
                    v-model="form.name"
                    label="Nombre visible"
                    placeholder="Ej. Margen operativo"
                    autocomplete="off"
                    :error="form.errors.name"
                    required
                />
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <SelectField
                    v-model="form.unit"
                    label="Unidad"
                    :options="[
                        { value: 'currency', label: 'Moneda' },
                        { value: 'percentage', label: 'Porcentaje' },
                        { value: 'integer', label: 'Entero' },
                        { value: 'decimal', label: 'Decimal' },
                    ]"
                />
                <SelectField
                    v-model="form.type"
                    label="Tipo"
                    :options="[
                        { value: 'cumulative', label: 'Acumulado' },
                        { value: 'balance', label: 'Saldo' },
                        { value: 'percentage', label: 'Porcentaje' },
                    ]"
                />
                <SelectField
                    v-model="form.direction"
                    label="Dirección"
                    :options="[
                        { value: 'increase', label: 'Incrementar ↑' },
                        { value: 'decrease', label: 'Disminuir ↓' },
                    ]"
                />
                <SelectField
                    v-model="form.automation"
                    label="Automatización"
                    :options="[
                        { value: 'manual', label: 'Manual' },
                        { value: 'automatic', label: 'Automático' },
                        { value: 'hybrid', label: 'Híbrido' },
                    ]"
                />
            </div>
            <div v-if="form.automation !== 'manual'">
                <SelectField
                    v-model="form.provider_key"
                    label="Provider (fuente registrada — nunca texto libre)"
                    placeholder="— Ninguno —"
                    :options="
                        availableProviders.map((p) => ({ value: p, label: p }))
                    "
                    :error="form.errors.provider_key"
                />
            </div>
            <div class="flex gap-2">
                <Button
                    type="button"
                    class="h-10 rounded-2xl"
                    :disabled="form.processing"
                    @click="submit"
                    >{{
                        form.processing ? 'Guardando…' : 'Guardar KPI'
                    }}</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    class="h-10 rounded-2xl"
                    :disabled="form.processing"
                    @click="showCreate = false"
                    >Cancelar</Button
                >
            </div>
        </div>

        <!-- Tabla del catálogo (o estado vacío) -->
        <div class="app-table-wrap">
            <div class="app-table-content">
                <table v-if="kpis.length" class="w-full min-w-[860px] text-sm">
                    <thead
                        class="border-b border-border bg-muted/40 text-xs tracking-wide text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="px-3 py-3 text-left font-semibold">
                                Code
                            </th>
                            <th class="px-3 py-3 text-left font-semibold">
                                Nombre
                            </th>
                            <th class="px-3 py-3 text-left font-semibold">
                                Tipo
                            </th>
                            <th class="px-3 py-3 text-left font-semibold">
                                Dirección
                            </th>
                            <th class="px-3 py-3 text-left font-semibold">
                                Fuente
                            </th>
                            <th class="px-3 py-3 text-left font-semibold">
                                Estado
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="k in kpis"
                            :key="k.id"
                            class="border-t border-border transition-colors hover:bg-muted/30"
                        >
                            <td
                                class="px-3 py-3 font-mono text-xs text-muted-foreground"
                            >
                                {{ k.code }}
                            </td>
                            <td class="px-3 py-3 font-semibold text-foreground">
                                {{ k.name }}
                            </td>
                            <td class="px-3 py-3 text-muted-foreground">
                                {{ k.type }}
                            </td>
                            <td class="px-3 py-3">
                                <span
                                    class="inline-flex items-center gap-1 text-muted-foreground"
                                >
                                    <ArrowUp
                                        v-if="k.direction === 'increase'"
                                        class="size-3.5"
                                    />
                                    <ArrowDown v-else class="size-3.5" />
                                    {{
                                        k.direction === 'increase'
                                            ? 'Incrementar'
                                            : 'Disminuir'
                                    }}
                                </span>
                            </td>
                            <td class="px-3 py-3">
                                <span
                                    v-if="k.automation === 'automatic'"
                                    class="rounded-full bg-emerald-500/10 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300"
                                    >Automático — {{ k.provider_key }}</span
                                >
                                <span
                                    v-else-if="k.automation === 'hybrid'"
                                    class="rounded-full bg-sky-500/10 px-2.5 py-1 text-xs font-semibold text-sky-700 dark:text-sky-300"
                                    >Híbrido</span
                                >
                                <span
                                    v-else
                                    class="rounded-full bg-muted px-2.5 py-1 text-xs font-semibold text-muted-foreground"
                                    >Manual</span
                                >
                            </td>
                            <td class="px-3 py-3">
                                <button
                                    type="button"
                                    :disabled="togglingId !== null"
                                    :aria-pressed="
                                        k.is_active ? 'true' : 'false'
                                    "
                                    :title="
                                        k.is_active
                                            ? 'Clic para desactivar este KPI'
                                            : 'Clic para activar este KPI'
                                    "
                                    class="rounded-full px-2.5 py-1 text-xs font-semibold transition disabled:cursor-wait disabled:opacity-60"
                                    :class="
                                        k.is_active
                                            ? 'bg-emerald-500/10 text-emerald-700 hover:bg-emerald-500/20 dark:text-emerald-300'
                                            : 'bg-muted text-muted-foreground hover:bg-muted/70'
                                    "
                                    @click="toggleActive(k)"
                                >
                                    {{ k.is_active ? 'Activo' : 'Inactivo' }}
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <AppEmptyState
                    v-else
                    title="Sin KPI en el catálogo"
                    message="Agrega el primero para poder usarlo en un Key Result."
                />
            </div>
        </div>
    </div>
</template>
