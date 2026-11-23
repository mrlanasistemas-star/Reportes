<script setup lang="ts">
/**
 * ExpenseDecisionDialog — diálogo para decidir colaborador y/o sucursal de uno o varios gastos.
 *
 * - Con `row`: muestra el detalle del gasto, el formulario de decisión y su bitácora.
 * - Sin `row` (`null`): decisión en lote para `count` gastos seleccionados.
 * - Si el usuario no puede editar o el periodo está cerrado, queda en modo solo consulta.
 *
 * La decisión se conserva aunque se reimporte o reprocese el periodo. El guardado lo hace el
 * padre al recibir `save`; este componente solo valida y arma el `DecisionPayload`.
 * Llama a `historyUrl` (GET, bitácora del gasto) bajo demanda.
 */
import { History, Loader2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { money, num } from '@/lib/format';
import ExpenseEmployeePicker from './ExpenseEmployeePicker.vue';
import type {
    BranchAction,
    DecisionPayload,
    EmployeeAction,
    ExpenseRow,
    ReviewOptions,
} from './types';
import { reviewGet } from './types';

/**
 * Props:
 * - `open`: visibilidad del diálogo.
 * - `row`: gasto individual, o `null` para decisión en lote.
 * - `count`: gastos afectados en lote (para el título).
 * - `options`: catálogos y permisos del apartado.
 * - `saving`: `true` mientras el padre guarda (deshabilita el botón).
 * - `historyUrl`: ruta de la bitácora del gasto, o `null`.
 */
const props = defineProps<{
    open: boolean;
    row: ExpenseRow | null; // null = decisión en lote
    count: number;
    options: ReviewOptions | null;
    saving: boolean;
    historyUrl: string | null;
}>();

/**
 * Emits:
 * - `update:open`: abrir/cerrar (v-model:open).
 * - `save`: decisión validada lista para enviar al backend.
 */
const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (e: 'save', payload: DecisionPayload): void;
}>();

/** Acción elegida para el colaborador (`''` = no cambiar) y colaborador elegido. */
const employeeAction = ref<EmployeeAction | ''>('');
const employeeId = ref<number | null>(null);
/** Acción elegida para la sucursal (`''` = no cambiar) y sucursal elegida. */
const branchAction = ref<BranchAction | ''>('');
const branchId = ref<number | null>(null);
/** Si se acepta la sugerencia de asignar también la sucursal del colaborador elegido. */
const useEmployeeBranch = ref(true);
/** Nota opcional que queda en la bitácora. */
const notes = ref('');
/** Bitácora del gasto (`null` = aún no cargada), bandera de carga y error de validación. */
const history = ref<any[] | null>(null);
const historyLoading = ref(false);
const formError = ref<string | null>(null);

/** `true` si el usuario puede guardar decisiones y el periodo no está cerrado. */
const canEdit = computed(
    () => !!props.options?.can_edit && !props.options?.is_closed,
);
/** `true` si el periodo es el general (habilita Corporativo y «Sin sucursal»); por defecto `true` si no hay opciones. */
const isGeneral = computed(() => props.options?.is_general !== false);

/**
 * Opciones de colaborador. «Quitar decisión manual» solo aparece si el gasto ya tiene una
 * decisión de colaborador, o siempre en lote.
 */
const employeeChoices = computed(
    () =>
        [
            { value: '', label: 'No cambiar' },
            { value: 'asignar', label: 'Pertenece a un colaborador' },
            { value: 'ninguno', label: 'No pertenece a ningún colaborador' },
            ...(props.row?.decision?.employee_decision || !props.row
                ? [
                      {
                          value: 'automatico',
                          label: 'Quitar decisión manual (volver a lo automático)',
                      },
                  ]
                : []),
        ] as Array<{ value: EmployeeAction | ''; label: string }>,
);

/**
 * Opciones de sucursal. Corporativo requiere periodo general y que exista la sucursal
 * Corporativo; «Sin sucursal» requiere periodo general; «Quitar decisión manual» solo si ya
 * hay decisión de sucursal (o en lote).
 */
const branchChoices = computed(
    () =>
        [
            { value: '', label: 'No cambiar' },
            { value: 'asignar', label: 'Asignar a una sucursal' },
            ...(isGeneral.value && props.options?.corporate_available
                ? [
                      {
                          value: 'corporativo',
                          label: 'Corporativo (no va a sucursales; queda para Estado de Resultados)',
                      },
                  ]
                : []),
            ...(isGeneral.value
                ? [{ value: 'ninguna', label: 'Sin sucursal (confirmado)' }]
                : []),
            ...(props.row?.decision?.branch_decision || !props.row
                ? [
                      {
                          value: 'automatico',
                          label: 'Quitar decisión manual (volver a lo automático)',
                      },
                  ]
                : []),
        ] as Array<{ value: BranchAction | ''; label: string }>,
);

/** Colaborador elegido en el selector (o `null`). */
const pickedEmployee = computed(
    () =>
        props.options?.employees.find((e) => e.id === employeeId.value) ?? null,
);
/**
 * `true` si conviene sugerir asignar también la sucursal del colaborador: se eligió asignar
 * colaborador, no se tocó la sucursal, el colaborador tiene sucursal y el gasto (individual)
 * no tiene sucursal actual. En lote siempre se sugiere.
 */
const suggestBranch = computed(
    () =>
        employeeAction.value === 'asignar' &&
        branchAction.value === '' &&
        !!pickedEmployee.value?.branch_id &&
        (props.row ? !props.row.branch : true),
);

/**
 * Reinicia el formulario al abrir: acciones en «No cambiar», preselecciona el colaborador y la
 * sucursal de la decisión previa (o la sucursal actual del gasto), carga la nota previa y
 * limpia la bitácora y el error.
 */
function reset() {
    const d = props.row?.decision;
    employeeAction.value = '';
    employeeId.value = d?.employee?.id ?? null;
    branchAction.value = '';
    branchId.value = d?.branch?.id ?? props.row?.branch?.id ?? null;
    useEmployeeBranch.value = true;
    notes.value = d?.notes ?? '';
    history.value = null;
    formError.value = null;
}

/** Al abrir el diálogo, reinicia el formulario. */
watch(
    () => props.open,
    (open) => {
        if (open) {
            reset();
        }
    },
);

/**
 * Carga la bitácora del gasto desde `historyUrl`. Si falla, la deja vacía (sin mostrar error).
 */
async function loadHistory() {
    if (!props.historyUrl) {
        return;
    }

    historyLoading.value = true;

    try {
        history.value = (
            await reviewGet<{ logs: any[] }>(props.historyUrl)
        ).logs;
    } catch {
        history.value = [];
    } finally {
        historyLoading.value = false;
    }
}

/**
 * Valida y emite la decisión:
 * - Asignar colaborador/sucursal exige haber elegido uno.
 * - Si se aceptó la sugerencia, asigna la sucursal del colaborador elegido.
 * - Exige al menos un cambio (colaborador, sucursal o nota distinta a la previa).
 * Emite `save` con el payload; la nota solo se envía si cambió.
 */
function submit() {
    formError.value = null;

    if (employeeAction.value === 'asignar' && !employeeId.value) {
        formError.value = 'Elige el colaborador al que pertenece el gasto.';

        return;
    }

    if (branchAction.value === 'asignar' && !branchId.value) {
        formError.value = 'Elige la sucursal.';

        return;
    }

    let bAction: BranchAction | null = branchAction.value || null;
    let bId = branchAction.value === 'asignar' ? branchId.value : null;

    if (suggestBranch.value && useEmployeeBranch.value) {
        bAction = 'asignar';
        bId = pickedEmployee.value!.branch_id;
    }

    const notesChanged = props.row
        ? notes.value.trim() !== (props.row.decision?.notes ?? '')
        : notes.value.trim() !== '';

    if (!employeeAction.value && !bAction && !notesChanged) {
        formError.value =
            'Elige al menos una decisión (colaborador o sucursal).';

        return;
    }

    emit('save', {
        employee_action: employeeAction.value || null,
        employee_id:
            employeeAction.value === 'asignar' ? employeeId.value : null,
        branch_action: bAction,
        branch_id: bId,
        ...(notesChanged ? { notes: notes.value.trim() } : {}),
    });
}

/**
 * Traduce la acción de la bitácora a texto visible.
 *
 * @param a Código de acción (`decision_guardada`, `decision_quitada`).
 * @returns Etiqueta en español o el código si no se conoce.
 */
const actionLabel = (a: string) =>
    (
        ({
            decision_guardada: 'Decisión guardada',
            decision_quitada: 'Decisión quitada',
        }) as Record<string, string>
    )[a] ?? a;
/**
 * Formatea una fecha-hora ISO en formato local es-MX (fecha media y hora corta).
 *
 * @param iso Fecha ISO o `null`.
 * @returns Texto formateado o `''`.
 */
const fmtWhen = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleString('es-MX', {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '';
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{
                    row
                        ? canEdit
                            ? 'Decidir sobre este gasto'
                            : 'Detalle del gasto'
                        : `Decidir para ${num(count)} gasto(s)`
                }}</DialogTitle>
                <DialogDescription>
                    Opcional. Lo que decidas se conserva aunque vuelvas a subir
                    o reprocesar el periodo. Si no decides nada, el reporte se
                    genera con las reglas automáticas de siempre.
                </DialogDescription>
            </DialogHeader>

            <!-- Detalle del gasto -->
            <dl
                v-if="row"
                class="grid grid-cols-1 gap-x-4 gap-y-2 rounded-xl border border-border bg-muted/30 p-4 text-sm sm:grid-cols-2"
            >
                <div class="sm:col-span-2">
                    <dt class="text-xs text-muted-foreground">Concepto</dt>
                    <dd class="font-semibold text-foreground">
                        {{ row.concept || 'Sin concepto' }}
                        <span class="font-normal text-muted-foreground"
                            >· {{ row.category || 'Sin categoría' }}</span
                        >
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Monto</dt>
                    <dd class="font-semibold tabular-nums">
                        {{ money(row.amount) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Fecha · Fuente
                    </dt>
                    <dd>{{ row.date ?? '—' }} · {{ row.source_label }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Sucursal actual
                    </dt>
                    <dd>{{ row.branch?.name ?? 'Sin sucursal' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Colaborador actual
                    </dt>
                    <dd>
                        {{ row.employee?.name ?? 'Sin colaborador' }}
                        <span class="text-xs text-muted-foreground"
                            >({{ row.attribution_label }})</span
                        >
                    </dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs text-muted-foreground">
                        Destino en el reporte
                    </dt>
                    <dd>
                        {{ row.classification.label
                        }}<span
                            v-if="row.classification.hint"
                            class="block text-xs text-muted-foreground"
                            >{{ row.classification.hint }}</span
                        >
                    </dd>
                </div>
                <div v-if="row.observations" class="sm:col-span-2">
                    <dt class="text-xs text-muted-foreground">Observaciones</dt>
                    <dd class="break-words text-muted-foreground">
                        {{ row.observations }}
                    </dd>
                </div>
                <div
                    v-if="row.decision"
                    class="text-xs text-muted-foreground sm:col-span-2"
                >
                    Última decisión manual por
                    <span class="font-semibold text-foreground">{{
                        row.decision.updated_by ?? '—'
                    }}</span>
                    · {{ fmtWhen(row.decision.updated_at) }}
                </div>
            </dl>

            <!-- Formulario de decisión (solo con permiso y periodo abierto) -->
            <template v-if="canEdit">
                <!-- Colaborador -->
                <fieldset class="space-y-2">
                    <legend class="text-sm font-semibold text-foreground">
                        Colaborador
                    </legend>
                    <div class="grid gap-1.5">
                        <label
                            v-for="c in employeeChoices"
                            :key="c.value"
                            class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition"
                            :class="
                                employeeAction === c.value
                                    ? 'border-primary bg-primary/5'
                                    : 'border-border hover:bg-muted/40'
                            "
                        >
                            <input
                                v-model="employeeAction"
                                type="radio"
                                name="employee-action"
                                :value="c.value"
                                class="accent-[var(--primary)]"
                            />
                            {{ c.label }}
                        </label>
                    </div>
                    <ExpenseEmployeePicker
                        v-if="employeeAction === 'asignar'"
                        v-model="employeeId"
                        :employees="options?.employees ?? []"
                    />
                    <label
                        v-if="suggestBranch"
                        class="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <Checkbox
                            :model-value="useEmployeeBranch"
                            @update:model-value="
                                (v) => (useEmployeeBranch = v === true)
                            "
                        />
                        Asignar también su sucursal ({{
                            pickedEmployee?.branch_name
                        }})
                    </label>
                </fieldset>

                <!-- Sucursal -->
                <fieldset class="space-y-2">
                    <legend class="text-sm font-semibold text-foreground">
                        Sucursal
                    </legend>
                    <div class="grid gap-1.5">
                        <label
                            v-for="c in branchChoices"
                            :key="c.value"
                            class="flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2 text-sm transition"
                            :class="
                                branchAction === c.value
                                    ? 'border-primary bg-primary/5'
                                    : 'border-border hover:bg-muted/40'
                            "
                        >
                            <input
                                v-model="branchAction"
                                type="radio"
                                name="branch-action"
                                :value="c.value"
                                class="accent-[var(--primary)]"
                            />
                            {{ c.label }}
                        </label>
                    </div>
                    <NativeSelect
                        v-if="branchAction === 'asignar'"
                        v-model="branchId"
                        class="w-full"
                        aria-label="Sucursal"
                    >
                        <NativeSelectOption :value="null" disabled
                            >Elige una sucursal</NativeSelectOption
                        >
                        <NativeSelectOption
                            v-for="b in options?.branches ?? []"
                            :key="b.id"
                            :value="b.id"
                            >{{ b.name }}</NativeSelectOption
                        >
                    </NativeSelect>
                    <p
                        v-if="branchAction === 'corporativo'"
                        class="app-banner app-banner-info text-xs"
                    >
                        No se sumará a la radiografía de sucursales. Si es un
                        gasto del ERP, el Estado de Resultados lo toma como
                        gasto corporativo.
                    </p>
                </fieldset>

                <label class="block space-y-1.5">
                    <span class="text-sm font-semibold text-foreground"
                        >Nota (opcional)</span
                    >
                    <textarea
                        v-model="notes"
                        rows="2"
                        maxlength="1000"
                        class="app-textarea w-full"
                        placeholder="¿Por qué se decidió así? Queda en la bitácora."
                    />
                </label>

                <p v-if="formError" class="app-field-error" role="alert">
                    {{ formError }}
                </p>
            </template>
            <p
                v-else-if="options?.is_closed"
                class="app-banner app-banner-warning text-xs"
            >
                El periodo está cerrado: sólo consulta.
            </p>
            <p v-else class="app-banner app-banner-info text-xs">
                Tu rol puede consultar el desglose, pero no guardar decisiones
                (requiere «Procesar» en Carga de archivos).
            </p>

            <!-- Bitácora -->
            <div v-if="row && historyUrl" class="border-t border-border pt-3">
                <button
                    v-if="history === null"
                    type="button"
                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-primary hover:underline"
                    :disabled="historyLoading"
                    @click="loadHistory"
                >
                    <Loader2
                        v-if="historyLoading"
                        class="size-3.5 animate-spin"
                        aria-hidden="true"
                    />
                    <History v-else class="size-3.5" aria-hidden="true" />
                    Ver bitácora de este gasto
                </button>
                <p
                    v-else-if="!history.length"
                    class="text-xs text-muted-foreground"
                >
                    Este gasto no tiene decisiones manuales registradas.
                </p>
                <ol v-else class="space-y-2 text-xs">
                    <li
                        v-for="log in history"
                        :key="log.id"
                        class="rounded-lg border border-border p-2.5"
                    >
                        <p class="font-semibold text-foreground">
                            {{ actionLabel(log.action) }} · {{ log.user }} ·
                            <span class="font-normal text-muted-foreground">{{
                                fmtWhen(log.at)
                            }}</span>
                        </p>
                        <p class="text-muted-foreground">
                            Antes:
                            {{ log.before?.employee ?? 'Sin colaborador' }} /
                            {{ log.before?.branch ?? 'Sin sucursal' }} →
                            Después:
                            {{ log.after?.employee ?? 'Sin colaborador' }} /
                            {{ log.after?.branch ?? 'Sin sucursal' }}
                        </p>
                        <p
                            v-if="log.after?.notes"
                            class="mt-0.5 text-muted-foreground italic"
                        >
                            «{{ log.after.notes }}»
                        </p>
                    </li>
                </ol>
            </div>

            <DialogFooter v-if="canEdit" class="gap-2">
                <button
                    type="button"
                    class="app-btn app-btn-secondary"
                    @click="emit('update:open', false)"
                >
                    Cancelar
                </button>
                <button
                    type="button"
                    class="app-btn app-btn-primary"
                    :disabled="saving"
                    @click="submit"
                >
                    <Loader2
                        v-if="saving"
                        class="size-4 animate-spin"
                        aria-hidden="true"
                    />
                    Guardar decisión
                </button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
