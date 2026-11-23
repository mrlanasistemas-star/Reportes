<script setup lang="ts">
/**
 * OkrAssignDialog — orquestador del asistente "Asignar OKR" (rehecho
 * 15-oct-2026). Se usa en pages/Okr/Dashboard. Pasos:
 *   1 Sucursal y objetivo · 2 Gestores · 3 KR generales · 4 Metas generales
 *   5 Metas y KR por gestor · 6 Resumen y guardar
 * El OKR general mide a la sucursal; cada gestor elegido tiene SUS propios KR,
 * SU línea base (CAPTURADA manualmente para ESE gestor), SU incremento y SU
 * meta; nunca se copian base ni meta de la sucursal. Todo se configura ANTES
 * de guardar; guardar un borrador con gestores pendientes es una decisión
 * explícita. Todo se guarda en borrador en una sola llamada (POST /okr).
 *
 * Props:
 * - branches: sucursales elegibles.
 * - kpis: catálogo de KPI.
 * - currentUser: usuario actual (responsable propuesto por defecto).
 * - users: usuarios elegibles como responsables.
 *
 * v-model:
 * - open: abre/cierra el asistente (al abrir se reinicia todo).
 */
import { useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    Building2,
    CalendarDays,
    CheckCircle2,
    Info,
    Loader2,
    Save,
    Sparkles,
    Users,
    X,
} from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';
import { appSwal as Swal } from '@/components/feedback/ConfirmDialog';
import DatePickerField from '@/components/forms/DatePickerField.vue';
import SearchableSelect from '@/components/forms/SearchableSelect.vue';
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useOkrBaselinePreview } from '@/composables/useOkrBaselinePreview';
import { formatFriendlyDate, toLocalIsoDate } from '@/lib/okrFormat';
import OkrGestorGoalsCard from './OkrGestorGoalsCard.vue';
import OkrGestorPicker from './OkrGestorPicker.vue';
import OkrGoalsTable from './OkrGoalsTable.vue';
import OkrKrEditor from './OkrKrEditor.vue';
import OkrTrajectoryPanel from './OkrTrajectoryPanel.vue';
import {
    avatarTone,
    cloneKrStructure,
    initialsOf,
    isAutomatic,
    krGoalIsComplete,
    krStructureIsComplete,
    newKrRow,
    serializeKr,
    totalWeight,
    uniqueKey,
} from './okrWizard';
import type { GestorPlan, KrRow } from './okrWizard';
import OkrWizardStepper from './OkrWizardStepper.vue';
import OkrWizardSummary from './OkrWizardSummary.vue';

const props = defineProps<{
    branches: { id: number; name: string }[];
    kpis: any[];
    currentUser: { id: number; full_name: string };
    users: { id: number; full_name: string }[];
}>();

const open = defineModel<boolean>('open', { default: false });

/** Etiquetas de los 6 pasos del asistente. */
const STEPS = [
    'Sucursal y objetivo',
    'Gestores',
    'KR generales',
    'Metas generales',
    'Metas por gestor',
    'Resumen y guardar',
];
/** Paso actual (1 a 6). */
const step = ref(1);
/** Sentido de la última navegación (para la animación de transición). */
const direction = ref<'forward' | 'back'>('forward');

/** Responsables posibles, con el usuario actual primero marcado "(Tú)". */
const responsibleOptions = computed(() => {
    const others = props.users
        .filter((u) => u.id !== props.currentUser.id)
        .map((u) => ({ id: u.id, full_name: u.full_name }));

    return [
        {
            id: props.currentUser.id,
            full_name: `${props.currentUser.full_name} (Tú)`,
        },
        ...others,
    ];
});

/** Datos generales del OKR de sucursal. */
const form = useForm({
    branch_id: null as number | null,
    title: '',
    responsible_user_id: props.currentUser.id as number | null,
    // Día LOCAL: toISOString() da la fecha UTC y, después de las 18:00 en
    // México, proponía como inicio el día de mañana.
    start_date: toLocalIsoDate(),
    duration_weeks: 8,
});

/** KR de la sucursal. */
const branchRows = ref<KrRow[]>([]);
/** Si se crearán OKR individuales por gestor. */
const individualsEnabled = ref(false);
/** Gestores elegidos con su plan individual. */
const gestores = reactive<GestorPlan[]>([]);
/** client_key de la tarjeta de gestor desplegada en el paso 5. */
const openGestor = ref<string | null>(null);

/** Reinicia el asistente: paso 1, formulario, KR, gestores y opciones. */
function resetAll() {
    step.value = 1;
    form.reset();
    form.clearErrors();
    form.responsible_user_id = props.currentUser.id;
    // reset() vuelve al valor de cuando se montó la página: si quedó abierta de
    // un día para otro, el inicio propuesto sería ayer.
    form.start_date = toLocalIsoDate();
    branchRows.value = [];
    individualsEnabled.value = false;
    gestores.splice(0, gestores.length);
    // Lo guardado aparte al apagar "OKR individuales" y la nómina consultada
    // pertenecen al asistente anterior: no deben reaparecer al reabrirlo.
    stashedGestores.splice(0, stashedGestores.length);
    employeeOptions.value = [];
    branchEmployeeCount.value = 0;
    roster.value = null;
    canReconcileBranch.value = false;
    openGestor.value = null;
    requestedGestor = null;
}

/** Cada vez que se abre el asistente empieza desde cero. */
watch(open, (isOpen) => {
    if (isOpen) {
        resetAll();
    }
});

// 4.3 (19-oct-2026): apagar "OKR individuales" por error ya no borra lo
// capturado — se guarda aparte y se restaura al volver a encenderlo. Mientras
// está apagado, ningún gestor viaja al guardar.
/** Gestores guardados aparte mientras "OKR individuales" está apagado. */
const stashedGestores: GestorPlan[] = [];
/** Guarda o restaura los gestores al apagar/encender los OKR individuales. */
watch(individualsEnabled, (enabled) => {
    if (!enabled) {
        stashedGestores.splice(0, stashedGestores.length, ...gestores);
        gestores.splice(0, gestores.length);
    } else if (!gestores.length && stashedGestores.length) {
        gestores.push(...stashedGestores);
        stashedGestores.splice(0, stashedGestores.length);
    }
});

// ── Colaboradores VIGENTES de la sucursal (búsqueda remota). ──
/** Candidatos vigentes de la sucursal. */
const employeeOptions = ref<
    { id: number; full_name: string; position?: string | null }[]
>([]);
/** Total de colaboradores vigentes en la sucursal. */
const branchEmployeeCount = ref(0);
/** Nómina usada para la lista (etiqueta y antigüedad). */
const roster = ref<{
    label: string | null;
    is_stale: boolean;
    days_old: number | null;
} | null>(null);
/** El usuario puede conciliar sucursales en conflicto. */
const canReconcileBranch = ref(false);
/** true mientras se cargan los candidatos. */
const loadingEmployees = ref(false);
/** Temporizador de búsqueda y contador para descartar respuestas viejas. */
let employeeSearchTimer: ReturnType<typeof setTimeout> | null = null;
let employeeSearchVersion = 0;

/**
 * Carga los colaboradores vigentes de la sucursal (con búsqueda opcional)
 * desde /okr/employees-lookup; descarta respuestas que lleguen tarde.
 * @param search texto de búsqueda.
 */
async function loadEmployees(search = '') {
    if (!form.branch_id) {
        employeeOptions.value = [];
        branchEmployeeCount.value = 0;

        return;
    }

    const params = new URLSearchParams({ branch_id: String(form.branch_id) });

    if (search) {
        params.set('search', search);
    }

    const myVersion = ++employeeSearchVersion;
    loadingEmployees.value = true;

    try {
        const res = await fetch(`/okr/employees-lookup?${params.toString()}`, {
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        });
        const data = res.ok
            ? await res.json()
            : { employees: [], total_in_branch: 0, roster: null };

        if (myVersion !== employeeSearchVersion) {
            return;
        }

        employeeOptions.value = data.employees ?? [];
        branchEmployeeCount.value = data.total_in_branch ?? 0;
        roster.value = data.roster ?? null;
        canReconcileBranch.value = !!data.can_reconcile_branch;
    } finally {
        if (myVersion === employeeSearchVersion) {
            loadingEmployees.value = false;
        }
    }
}

/**
 * Busca candidatos con una espera de 300 ms tras dejar de escribir.
 * @param term texto de búsqueda.
 */
function onEmployeeSearch(term: string) {
    if (employeeSearchTimer) {
        clearTimeout(employeeSearchTimer);
    }

    employeeSearchTimer = setTimeout(() => loadEmployees(term), 300);
}

/**
 * Al cambiar de sucursal quita los gestores elegidos (avisando), invalida la
 * línea base consultada y recarga los candidatos.
 */
watch(
    () => form.branch_id,
    () => {
        // Los gestores elegidos pertenecen a la sucursal anterior: nunca se guarda un
        // gestor de otra sucursal (contradicción). Se avisa en vez de quitarlos en silencio.
        const removed = gestores.length + stashedGestores.length;

        if (removed) {
            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'info',
                timer: 4200,
                showConfirmButton: false,
                title: 'Cambiaste de sucursal',
                text: `Se quitaron ${removed} gestor(es) de la sucursal anterior; elige los de la nueva sucursal.`,
            });
        }

        stashedGestores.splice(0, stashedGestores.length);
        gestores.splice(0, gestores.length);
        // La línea base consultada pertenece a la sucursal anterior — se invalida.
        branchRows.value.forEach((kr) => {
            kr.preview = null;

            if (isAutomatic(kpiOf(kr.kpi_id))) {
                kr.baseline_value = '';
            }
        });
        loadEmployees();
    },
);

/**
 * Marca o desmarca un gestor; al marcarlo propone un objetivo individual
 * basado en el objetivo general.
 * @param employee colaborador elegido.
 */
function toggleGestor(employee: {
    id: number;
    full_name: string;
    position?: string | null;
}) {
    const idx = gestores.findIndex((g) => g.employee_id === employee.id);

    if (idx >= 0) {
        gestores.splice(idx, 1);

        return;
    }

    gestores.push({
        client_key: uniqueKey(),
        employee_id: employee.id,
        employee_name: employee.full_name,
        position: employee.position ?? null,
        title: form.title.trim()
            ? `${form.title.trim()} — ${employee.full_name}`.slice(0, 500)
            : '',
        key_results: [],
    });
}

/**
 * Quita un gestor seleccionado.
 * @param i índice en la lista.
 */
function removeGestor(i: number) {
    gestores.splice(i, 1);
}

/** Nombre de la sucursal elegida. */
const selectedBranchName = computed(
    () => props.branches.find((b) => b.id === form.branch_id)?.name ?? null,
);
/** Nombre del responsable elegido. */
const selectedResponsibleName = computed(
    () =>
        responsibleOptions.value.find((u) => u.id === form.responsible_user_id)
            ?.full_name ?? '—',
);
/** Fecha de término calculada: inicio + semanas × 7 − 1 día. */
const endDatePreview = computed(() => {
    const start = new Date(`${form.start_date}T00:00:00`);

    if (Number.isNaN(start.getTime())) {
        return null;
    }

    start.setDate(start.getDate() + form.duration_weeks * 7 - 1);

    return toLocalIsoDate(start);
});
/** Duraciones disponibles en semanas. */
const weekOptions = [4, 6, 8, 10, 12, 16, 20, 24, 36, 48, 52];

/**
 * Busca un KPI del catálogo por id.
 * @param id id del KPI.
 */
function kpiOf(id: number | null) {
    return props.kpis.find((k) => k.id === id) ?? null;
}

// ── Consulta de línea base de la SUCURSAL (paso 4); la de cada gestor es manual (paso 5) ──
const { consult } = useOkrBaselinePreview();
/** Sucursal y fecha de inicio para consultar líneas base. */
const scopeBase = computed(() => ({
    branch_id: form.branch_id,
    start_date: form.start_date,
}));

/**
 * Consulta en Reportería la línea base de los KR automáticos que aún no la tienen.
 * @param rows filas de KR.
 * @param scope alcance (siempre sucursal).
 */
function consultPending(rows: KrRow[], scope: { scope_type: 'branch' }) {
    rows.filter(
        (kr) =>
            isAutomatic(kpiOf(kr.kpi_id)) &&
            !kr.preview &&
            !kr.loading &&
            kr.baseline_mode !== 'manual_authorized',
    ).forEach((kr) => consult(kr, { ...scopeBase.value, ...scope }));
}

/**
 * Copia a un gestor la estructura de los KR generales (KPI, descripción y peso).
 * @param g plan del gestor.
 */
function copyStructureTo(g: GestorPlan) {
    g.key_results = cloneKrStructure(branchRows.value);
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        timer: 2400,
        showConfirmButton: false,
        title: 'Estructura copiada',
        text: 'Solo KPI, descripción y peso. La base y la meta son de cada gestor.',
    });
}

/**
 * Gestor que se pidió abrir al volver al paso 5 con "Completar" (paso 6); si es
 * null, el paso 5 abre el primero incompleto.
 */
let requestedGestor: string | null = null;

/**
 * "Completar" del resumen: vuelve al paso 5 con la tarjeta de ESE gestor abierta.
 * (Antes el watch de step la reemplazaba por el primer pendiente.)
 * @param key client_key del gestor.
 */
function goToGestor(key: string) {
    requestedGestor = key;
    openGestor.value = key;
    step.value = 5;
}

/**
 * Prepara cada paso al entrar: crea el primer KR (paso 3), consulta la base de
 * la sucursal (paso 4) y crea el KR inicial de cada gestor y abre el elegido
 * con "Completar" o, si no, el primero incompleto (paso 5).
 */
watch(step, (s) => {
    if (s === 3 && branchRows.value.length === 0) {
        branchRows.value.push(newKrRow({ weight: '100' }));
    }

    if (s === 4) {
        consultPending(branchRows.value, { scope_type: 'branch' });
    }

    if (s === 5) {
        gestores.forEach((g) => {
            if (g.key_results.length === 0) {
                g.key_results = [newKrRow({ weight: '100' })];
            }
        });
        const requested =
            requestedGestor &&
            gestores.some((g) => g.client_key === requestedGestor)
                ? requestedGestor
                : null;
        requestedGestor = null;
        openGestor.value =
            requested ??
            gestores.find((g) => !gestorComplete(g))?.client_key ??
            gestores[0]?.client_key ??
            null;
    }
});

// Regla definitiva (16-oct-2026): la línea base de cada gestor se CAPTURA
// manualmente en su tarjeta — nunca se consulta en Reportería.

/**
 * true si el gestor tiene KR completos con su línea base y meta.
 * @param g plan del gestor.
 */
function gestorComplete(g: GestorPlan): boolean {
    return (
        krStructureIsComplete(g.key_results) &&
        g.key_results.every((kr) =>
            krGoalIsComplete(kr, kpiOf(kr.kpi_id), 'employee'),
        )
    );
}

/** true si todos los KR de la sucursal tienen base y meta. */
const branchGoalsComplete = computed(
    () =>
        branchRows.value.length > 0 &&
        branchRows.value.every((kr) => krGoalIsComplete(kr, kpiOf(kr.kpi_id))),
);
/** Gestores a los que aún les faltan metas. */
const incompleteGestores = computed(() =>
    gestores.filter((g) => !gestorComplete(g)),
);

/** Motivo que impide avanzar desde el paso actual (null si se puede avanzar). */
const blocker = computed<string | null>(() => {
    switch (step.value) {
        case 1:
            if (!form.branch_id) {
                return 'Elige la sucursal.';
            }

            if (form.title.trim().length < 10) {
                return 'Escribe el objetivo general (mínimo 10 caracteres).';
            }

            return form.start_date ? null : 'Indica la fecha de inicio.';
        case 2: {
            if (!individualsEnabled.value) {
                return null;
            }

            if (!gestores.length) {
                return 'Selecciona al menos un gestor o desactiva los OKR individuales.';
            }

            const noTitle = gestores.find((g) => g.title.trim().length < 10);

            return noTitle
                ? `Escribe el objetivo individual de ${noTitle.employee_name}.`
                : null;
        }
        case 3:
            return krStructureIsComplete(branchRows.value)
                ? null
                : `Completa los KR generales (KPI, descripción y pesos al 100% — hoy ${totalWeight(branchRows.value)}%).`;
        case 4:
            return branchGoalsComplete.value
                ? null
                : 'Completa la línea base y la meta de la sucursal.';
        case 5:
            // Se puede continuar al resumen aunque falte algún gestor: ahí se decide
            // si se guarda como borrador incompleto (de forma explícita).
            return null;
        default:
            return null;
    }
});

/** Avanza al siguiente paso (sin gestores salta del 4 al 6). */
function next() {
    if (blocker.value || step.value >= STEPS.length) {
        return;
    }

    direction.value = 'forward';
    step.value = step.value === 4 && !gestores.length ? 6 : step.value + 1;
}

/** Regresa al paso anterior (sin gestores salta del 6 al 4). */
function back() {
    if (step.value <= 1) {
        return;
    }

    direction.value = 'back';
    step.value = step.value === 6 && !gestores.length ? 4 : step.value - 1;
}

/**
 * Regresa a un paso anterior desde el indicador de pasos.
 * @param target paso destino.
 */
function goTo(target: number) {
    if (target < step.value) {
        direction.value = 'back';
        step.value = target;
    }
}

/**
 * Guarda el OKR de sucursal y los individuales en borrador.
 * @param saveIncomplete true para guardar aunque falten metas de algún gestor
 *   (esos gestores viajan sin KR).
 */
function submit(saveIncomplete = false) {
    form.transform((data) => ({
        scope_type: 'branch',
        branch_id: data.branch_id,
        title: data.title,
        responsible_user_id: data.responsible_user_id,
        start_date: data.start_date,
        duration_weeks: data.duration_weeks,
        save_incomplete: saveIncomplete,
        key_results: branchRows.value.map((kr) =>
            serializeKr(kr, kpiOf(kr.kpi_id)),
        ),
        individual_objectives: gestores.map((g) => ({
            employee_id: g.employee_id,
            title: g.title,
            // Borrador incompleto: un gestor sin metas terminadas viaja SIN KR (queda
            // marcado "Configurar KR"); nunca con KR a medias.
            key_results: gestorComplete(g)
                ? g.key_results.map((kr) =>
                      serializeKr(kr, kpiOf(kr.kpi_id), 'employee'),
                  )
                : [],
        })),
    })).post('/okr', {
        onSuccess: () => {
            open.value = false;
            Swal.fire({
                icon:
                    saveIncomplete && incompleteGestores.value.length
                        ? 'info'
                        : 'success',
                title:
                    saveIncomplete && incompleteGestores.value.length
                        ? 'Borrador guardado con pendientes'
                        : 'OKR guardado en borrador',
                text:
                    saveIncomplete && incompleteGestores.value.length
                        ? `Faltan las metas de: ${incompleteGestores.value.map((g) => g.employee_name).join(', ')}. Complétalas con "Configurar KR" antes de activar.`
                        : gestores.length
                          ? `Sucursal + ${gestores.length} OKR individual(es), cada uno con su propia meta.`
                          : 'Revisa la línea base y actívalo desde el detalle.',
            });
        },
        onError: (errors) => {
            Swal.fire({
                icon: 'error',
                title: 'No se pudo guardar el OKR',
                html: Object.values(errors)
                    .slice(0, 6)
                    .map((e) => `• ${e}`)
                    .join('<br>'),
                confirmButtonText: 'Revisar',
            });
        },
    });
}

/** KR principal de la sucursal (para la gráfica de trayectoria). */
const primaryBranchKr = computed(() => branchRows.value[0] ?? null);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="flex max-h-[94vh] w-[96vw] max-w-[96vw] flex-col gap-0 overflow-hidden p-0 sm:max-w-[1400px]"
            :show-close-button="false"
            @pointer-down-outside="(e) => e.preventDefault()"
            @interact-outside="(e) => e.preventDefault()"
        >
            <DialogTitle class="sr-only">Asignar OKR</DialogTitle>

            <!-- Encabezado con título y botón de cerrar -->
            <div class="relative border-b border-border bg-card px-6 py-4">
                <div class="relative flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span
                            class="flex size-10 items-center justify-center rounded-xl bg-accent text-primary"
                            aria-hidden="true"
                            ><Sparkles class="size-5"
                        /></span>
                        <div>
                            <p class="text-base font-semibold text-foreground">
                                Asignar OKR
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Paso {{ step }} de {{ STEPS.length }} ·
                                {{ STEPS[step - 1] }}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="rounded-full p-1.5 text-muted-foreground transition hover:bg-muted hover:text-foreground"
                        aria-label="Cerrar"
                        @click="open = false"
                    >
                        <X class="size-4" />
                    </button>
                </div>
            </div>

            <OkrWizardStepper
                class="border-b border-border"
                :steps="STEPS"
                :current="step"
                @go="goTo"
            />

            <!-- Errores de validación del servidor -->
            <div
                v-if="Object.keys(form.errors).length"
                class="mx-6 mt-3 rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-xs font-semibold text-destructive"
            >
                <p v-for="(err, key) in form.errors" :key="key">{{ err }}</p>
            </div>

            <div class="flex-1 overflow-y-auto px-6 py-5">
                <Transition
                    mode="out-in"
                    :enter-from-class="
                        direction === 'forward'
                            ? 'opacity-0 translate-x-6'
                            : 'opacity-0 -translate-x-6'
                    "
                    enter-active-class="transition duration-300 ease-out"
                    leave-active-class="transition duration-150 ease-in"
                    leave-to-class="opacity-0"
                >
                    <!-- 1 · SUCURSAL Y OBJETIVO -->
                    <div
                        v-if="step === 1"
                        key="s1"
                        class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_300px]"
                    >
                        <div class="space-y-4">
                            <div class="space-y-1.5">
                                <label
                                    for="okr-assign-branch"
                                    class="text-sm font-semibold text-foreground"
                                    >Sucursal</label
                                >
                                <SearchableSelect
                                    id="okr-assign-branch"
                                    v-model="form.branch_id as any"
                                    placeholder="Selecciona una sucursal"
                                    search-placeholder="Buscar sucursal..."
                                    :options="branches"
                                    label-key="name"
                                    secondary-key="__none"
                                    :error="form.errors.branch_id"
                                />
                            </div>
                            <div class="space-y-1.5">
                                <label
                                    for="okr-assign-title"
                                    class="text-sm font-semibold text-foreground"
                                    >Objetivo general de la sucursal</label
                                >
                                <textarea
                                    id="okr-assign-title"
                                    v-model="form.title"
                                    :aria-invalid="
                                        form.errors.title ? 'true' : undefined
                                    "
                                    rows="3"
                                    maxlength="500"
                                    placeholder="AUMENTAR LA COLOCACIÓN DE CARTERA"
                                    class="app-textarea"
                                />
                                <p
                                    class="text-right text-[11px] text-muted-foreground tabular-nums"
                                >
                                    {{ form.title.length }}/500
                                </p>
                            </div>
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div class="space-y-1.5">
                                    <label
                                        for="okr-assign-start"
                                        class="text-sm font-semibold text-foreground"
                                        >Fecha de inicio</label
                                    >
                                    <DatePickerField
                                        id="okr-assign-start"
                                        v-model="form.start_date"
                                        placeholder="Selecciona la fecha"
                                        :error="form.errors.start_date"
                                    />
                                </div>
                                <div class="space-y-1.5">
                                    <label
                                        for="okr-assign-duration"
                                        class="text-sm font-semibold text-foreground"
                                        >Duración</label
                                    >
                                    <Select
                                        :model-value="
                                            String(form.duration_weeks)
                                        "
                                        @update:model-value="
                                            (v) =>
                                                (form.duration_weeks =
                                                    Number(v))
                                        "
                                    >
                                        <SelectTrigger
                                            id="okr-assign-duration"
                                            class="h-11 w-full rounded-2xl"
                                        >
                                            <SelectValue
                                                placeholder="Selecciona la duración"
                                            />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem
                                                v-for="w in weekOptions"
                                                :key="w"
                                                :value="String(w)"
                                                >{{ w }} semanas</SelectItem
                                            >
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div class="space-y-1.5">
                                    <p
                                        id="okr-assign-end-label"
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        Fecha de término
                                    </p>
                                    <div
                                        class="flex h-11 items-center rounded-2xl bg-muted/50 px-3 text-sm font-semibold text-foreground"
                                        role="status"
                                        aria-labelledby="okr-assign-end-label"
                                    >
                                        {{
                                            endDatePreview
                                                ? formatFriendlyDate(
                                                      endDatePreview,
                                                  )
                                                : '—'
                                        }}
                                    </div>
                                </div>
                            </div>
                            <p
                                class="flex items-center gap-1.5 text-[11px] text-muted-foreground"
                            >
                                <CalendarDays
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Seguimiento semanal: cada semana se carga un
                                solo reporte de colocación de la sucursal.
                            </p>
                            <div class="space-y-1.5">
                                <div class="flex items-center gap-1.5">
                                    <label
                                        for="okr-assign-responsible"
                                        class="text-sm font-semibold text-foreground"
                                        >Responsable general</label
                                    >
                                    <OkrHelpTooltip
                                        text="Quién da seguimiento administrativo (check-ins, cargas). No es la persona evaluada."
                                    />
                                </div>
                                <SearchableSelect
                                    id="okr-assign-responsible"
                                    v-model="form.responsible_user_id as any"
                                    placeholder="Selecciona un responsable"
                                    :options="responsibleOptions"
                                    label-key="full_name"
                                    secondary-key="__none"
                                />
                            </div>
                            <label
                                class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 transition hover:border-primary/40 hover:bg-primary/5"
                                :class="
                                    individualsEnabled
                                        ? 'border-primary/40 bg-primary/5'
                                        : 'border-border bg-muted/20'
                                "
                            >
                                <Switch
                                    v-model="individualsEnabled"
                                    class="mt-0.5"
                                />
                                <div>
                                    <p
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        Agregar OKR individuales por gestor
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Elige qué gestores tendrán compromiso
                                        propio; cada uno tendrá su base,
                                        incremento, meta, seguimiento y
                                        documentos.
                                    </p>
                                </div>
                            </label>
                        </div>
                        <aside
                            class="h-fit space-y-3 rounded-2xl border border-border bg-gradient-to-b from-muted/40 to-background p-4"
                        >
                            <p
                                class="text-xs font-bold tracking-wide text-muted-foreground uppercase"
                            >
                                Resumen
                            </p>
                            <div
                                class="flex items-center gap-2.5 rounded-xl bg-background p-2.5 shadow-sm"
                            >
                                <span
                                    class="flex size-8 items-center justify-center rounded-full bg-blue-500/10 text-blue-600"
                                    ><Building2 class="size-4"
                                /></span>
                                <div>
                                    <p
                                        class="text-[11px] text-muted-foreground"
                                    >
                                        Sucursal
                                    </p>
                                    <p
                                        class="text-sm font-semibold text-foreground"
                                    >
                                        {{ selectedBranchName ?? '—' }}
                                    </p>
                                </div>
                            </div>
                            <div
                                class="flex items-center gap-2.5 rounded-xl bg-background p-2.5 shadow-sm"
                            >
                                <span
                                    class="flex size-8 items-center justify-center rounded-full bg-emerald-500/10 text-emerald-600"
                                    ><Users class="size-4"
                                /></span>
                                <div>
                                    <p
                                        class="text-[11px] text-muted-foreground"
                                    >
                                        Colaboradores vigentes
                                    </p>
                                    <p
                                        class="text-sm font-semibold text-foreground tabular-nums"
                                    >
                                        {{
                                            form.branch_id
                                                ? branchEmployeeCount
                                                : '—'
                                        }}
                                    </p>
                                </div>
                            </div>
                            <p
                                v-if="roster?.label"
                                class="text-[11px]"
                                :class="
                                    roster.is_stale
                                        ? 'font-semibold text-amber-700 dark:text-amber-300'
                                        : 'text-muted-foreground'
                                "
                            >
                                Nómina vigente: {{ roster.label
                                }}<template v-if="roster.is_stale">
                                    — tiene {{ roster.days_old }} días; altas y
                                    bajas recientes pueden no
                                    reflejarse.</template
                                >
                            </p>
                        </aside>
                    </div>

                    <!-- 2 · GESTORES -->
                    <div v-else-if="step === 2" key="s2" class="space-y-4">
                        <OkrGestorPicker
                            v-if="individualsEnabled"
                            :employees="employeeOptions"
                            :selected="gestores"
                            :loading="loadingEmployees"
                            :roster-period="roster?.label ?? null"
                            :branch-selected="!!form.branch_id"
                            :branch-name="selectedBranchName"
                            :can-reconcile="canReconcileBranch"
                            @toggle="toggleGestor"
                            @remove="removeGestor"
                            @search="onEmployeeSearch"
                            @reconciled="loadEmployees()"
                        />
                        <div
                            v-else
                            class="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-border p-10 text-center"
                        >
                            <Users class="size-8 text-muted-foreground" />
                            <p class="text-sm font-semibold text-foreground">
                                Solo OKR general de la sucursal
                            </p>
                            <p class="max-w-md text-xs text-muted-foreground">
                                No se crearán compromisos individuales. Si
                                quieres que algún gestor tenga su propia meta,
                                actívalo aquí.
                            </p>
                            <Button
                                variant="outline"
                                class="rounded-xl"
                                @click="individualsEnabled = true"
                                >Agregar OKR individuales</Button
                            >
                        </div>
                    </div>

                    <!-- 3 · KR GENERALES -->
                    <div v-else-if="step === 3" key="s3" class="space-y-3">
                        <div>
                            <p class="text-sm font-bold text-foreground">
                                Key Results del objetivo general —
                                {{ selectedBranchName }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                Miden a TODA la sucursal (incluye gestores sin
                                OKR individual). Los pesos suman 100%.
                            </p>
                        </div>
                        <OkrKrEditor v-model:rows="branchRows" :kpis="kpis" />
                    </div>

                    <!-- 4 · METAS GENERALES -->
                    <div v-else-if="step === 4" key="s4" class="space-y-4">
                        <div class="flex items-center gap-2">
                            <span
                                class="flex size-8 items-center justify-center rounded-lg bg-blue-500/10 text-blue-600"
                                ><Building2 class="size-4"
                            /></span>
                            <div>
                                <p class="text-sm font-bold text-foreground">
                                    Meta general — {{ selectedBranchName }}
                                </p>
                                <p class="text-[11px] text-muted-foreground">
                                    Meta final = Línea base congelada +
                                    Incremento esperado. Aquí solo se configura
                                    la sucursal.
                                </p>
                            </div>
                            <CheckCircle2
                                v-if="branchGoalsComplete"
                                class="ml-auto size-5 text-emerald-500"
                            />
                        </div>
                        <OkrGoalsTable
                            v-model:rows="branchRows"
                            :kpis="kpis"
                            :scope="{ ...scopeBase, scope_type: 'branch' }"
                            scope-label="de la sucursal"
                        />
                        <OkrTrajectoryPanel
                            v-model:weeks="form.duration_weeks"
                            :start-date="form.start_date"
                            :end-date="endDatePreview"
                            :baseline="primaryBranchKr?.baseline_value ?? ''"
                            :target="primaryBranchKr?.target_value ?? ''"
                            :unit="kpiOf(primaryBranchKr?.kpi_id ?? null)?.unit"
                        />
                    </div>

                    <!-- 5 · METAS Y KR POR GESTOR -->
                    <div v-else-if="step === 5" key="s5" class="space-y-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="flex size-8 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-600"
                                ><Users class="size-4"
                            /></span>
                            <div>
                                <p class="text-sm font-bold text-foreground">
                                    Metas y Key Results por gestor
                                </p>
                                <p class="text-[11px] text-muted-foreground">
                                    Captura la línea base de cada gestor (valor
                                    inicial desde el cual se medirá su
                                    incremento). Las metas son independientes
                                    entre sí y de la meta general.
                                </p>
                            </div>
                            <span
                                class="ml-auto rounded-full bg-muted px-2.5 py-1 text-[11px] font-bold tabular-nums"
                                >{{
                                    gestores.length - incompleteGestores.length
                                }}/{{ gestores.length }} completos</span
                            >
                        </div>
                        <OkrGestorGoalsCard
                            v-for="(g, gi) in gestores"
                            :key="g.client_key"
                            v-model:gestor="gestores[gi]"
                            :index="gi"
                            :kpis="kpis"
                            :branch-name="selectedBranchName"
                            :scope="scopeBase"
                            :can-copy="krStructureIsComplete(branchRows)"
                            :open="openGestor === g.client_key"
                            @copy="copyStructureTo(g)"
                            @toggle="
                                openGestor =
                                    openGestor === g.client_key
                                        ? null
                                        : g.client_key
                            "
                        />
                    </div>

                    <!-- 6 · RESUMEN -->
                    <div v-else key="s6" class="space-y-4">
                        <OkrWizardSummary
                            :kpis="kpis"
                            :title="form.title"
                            :branch-name="selectedBranchName"
                            :responsible-name="selectedResponsibleName"
                            :duration-weeks="form.duration_weeks"
                            :start-date="form.start_date"
                            :end-date="endDatePreview"
                            :branch-rows="branchRows"
                            :gestores="gestores.filter(gestorComplete)"
                        />
                        <div
                            v-if="incompleteGestores.length"
                            class="rounded-2xl border border-amber-500/40 bg-amber-500/5 p-4 text-sm"
                        >
                            <p
                                class="flex items-center gap-2 font-bold text-amber-800 dark:text-amber-200"
                            >
                                <AlertTriangle class="size-4" /> Gestores con
                                metas pendientes
                            </p>
                            <ul
                                class="mt-2 space-y-1 text-xs text-amber-800 dark:text-amber-200"
                            >
                                <li
                                    v-for="g in incompleteGestores"
                                    :key="g.client_key"
                                    class="flex items-center gap-2"
                                >
                                    <span
                                        class="flex size-6 items-center justify-center rounded-full bg-gradient-to-br text-[11px] font-bold text-white"
                                        :class="avatarTone(g.employee_id)"
                                        >{{ initialsOf(g.employee_name) }}</span
                                    >
                                    <strong>{{ g.employee_name }}</strong> — sin
                                    línea base, incremento o meta terminados.
                                    <button
                                        type="button"
                                        class="ml-auto font-bold text-primary hover:underline"
                                        @click="goToGestor(g.client_key)"
                                    >
                                        Completar
                                    </button>
                                </li>
                            </ul>
                            <p class="mt-2 text-[11px] text-muted-foreground">
                                Puedes completarlos ahora o guardar un borrador
                                intencionalmente incompleto: esos gestores
                                quedarán marcados "Configurar KR" y no podrán
                                activarse hasta tener sus metas.
                            </p>
                        </div>
                        <p
                            v-else
                            class="flex items-center gap-2 rounded-xl border border-emerald-500/30 bg-emerald-500/5 px-3 py-2.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300"
                        >
                            <CheckCircle2 class="size-4" /> Objetivo general{{
                                gestores.length
                                    ? ` y ${gestores.length} OKR individual(es)`
                                    : ''
                            }}
                            completos, cada uno con sus propias metas.
                        </p>
                        <p
                            class="flex items-center gap-2 rounded-xl bg-primary/5 px-3 py-2.5 text-xs text-primary"
                        >
                            <Info class="size-4 shrink-0" /> Se guarda todo en
                            una sola transacción, en borrador. Las líneas base
                            automáticas se congelan al activar.
                        </p>
                    </div>
                </Transition>
            </div>

            <!-- Pie: anterior/cancelar, motivo del bloqueo y siguiente/guardar -->
            <div
                class="flex flex-wrap items-center justify-between gap-3 border-t border-border bg-muted/20 px-6 py-3"
            >
                <Button
                    v-if="step > 1"
                    type="button"
                    variant="outline"
                    class="h-10 gap-1.5 rounded-xl"
                    :disabled="form.processing"
                    @click="back"
                    ><ArrowLeft class="size-4" /> Anterior</Button
                >
                <Button
                    v-else
                    type="button"
                    variant="ghost"
                    class="h-10 rounded-xl text-muted-foreground"
                    @click="open = false"
                    >Cancelar</Button
                >

                <p
                    v-if="blocker"
                    class="hidden flex-1 items-center justify-center gap-1.5 text-center text-[11px] font-semibold text-amber-600 sm:flex dark:text-amber-400"
                >
                    <AlertTriangle class="size-3.5 shrink-0" /> {{ blocker }}
                </p>

                <div class="flex gap-2">
                    <Button
                        v-if="step < STEPS.length"
                        type="button"
                        class="h-10 gap-1.5 rounded-xl shadow-md shadow-primary/20"
                        :disabled="!!blocker"
                        @click="next"
                        >Siguiente <ArrowRight class="size-4"
                    /></Button>
                    <template v-else>
                        <Button
                            v-if="incompleteGestores.length"
                            type="button"
                            variant="outline"
                            class="h-10 gap-1.5 rounded-xl border-amber-500/50 text-amber-700 dark:text-amber-300"
                            :disabled="form.processing"
                            @click="submit(true)"
                        >
                            <Save class="size-4" /> Guardar borrador con
                            pendientes
                        </Button>
                        <Button
                            type="button"
                            class="h-10 gap-1.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 text-white shadow-lg shadow-emerald-600/30"
                            :disabled="
                                form.processing || incompleteGestores.length > 0
                            "
                            @click="submit(false)"
                        >
                            <Loader2
                                v-if="form.processing"
                                class="size-4 animate-spin"
                            /><CheckCircle2 v-else class="size-4" />
                            {{ form.processing ? 'Guardando…' : 'Guardar OKR' }}
                        </Button>
                    </template>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
