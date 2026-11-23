<script setup lang="ts">
/**
 * OkrGestorGoalsCard — tarjeta plegable de UN gestor en el paso "Metas y KR
 * por gestor" del asistente (OkrAssignDialog, 15-oct-2026): SUS propios Key
 * Results, SU línea base (capturada manualmente para ESE gestor), SU
 * incremento y SU meta. Copiar la estructura del KR general es opcional y
 * visible, y nunca copia importes ni líneas base.
 *
 * v-model:
 * - gestor: plan del gestor (GestorPlan) con sus key_results.
 *
 * Props:
 * - index: posición del gestor (para "Gestor N").
 * - kpis: catálogo de KPI disponibles.
 * - branchName: nombre de la sucursal.
 * - scope: sucursal y fecha de inicio del OKR (para consultar la base).
 * - canCopy: habilita "Copiar estructura del KR general".
 * - open: si la tarjeta está desplegada.
 *
 * Emits:
 * - copy: copiar la estructura de KR general a este gestor.
 * - toggle: plegar/desplegar la tarjeta.
 */
import {
    AlertTriangle,
    CheckCircle2,
    ChevronDown,
    Copy,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import OkrGoalsTable from './OkrGoalsTable.vue';
import OkrKrEditor from './OkrKrEditor.vue';
import {
    avatarTone,
    initialsOf,
    krGoalIsComplete,
    krStructureIsComplete,
    totalWeight,
} from './okrWizard';
import type { GestorPlan } from './okrWizard';

const gestor = defineModel<GestorPlan>('gestor', { required: true });
const props = defineProps<{
    index: number;
    kpis: any[];
    branchName: string | null;
    scope: { branch_id: number | null; start_date: string };
    canCopy: boolean;
    open: boolean;
}>();
const emit = defineEmits<{ copy: []; toggle: [] }>();

/**
 * Busca un KPI del catálogo por id.
 * @param id id del KPI.
 * @returns el KPI o null.
 */
function kpiOf(id: number | null) {
    return props.kpis.find((k) => k.id === id) ?? null;
}

/** true si los KR del gestor tienen KPI, descripción y pesos al 100 %. */
const structureOk = computed(() =>
    krStructureIsComplete(gestor.value.key_results),
);
/** true si además cada KR tiene línea base y meta capturadas. */
const goalsOk = computed(
    () =>
        structureOk.value &&
        gestor.value.key_results.every((kr) =>
            krGoalIsComplete(kr, kpiOf(kr.kpi_id), 'employee'),
        ),
);
/** Qué le falta al gestor (texto del aviso) o null si está completo. */
const missing = computed(() => {
    if (!gestor.value.key_results.length) {
        return 'Sin Key Results';
    }

    if (!structureOk.value) {
        return `KPI/descripción o pesos incompletos (${totalWeight(gestor.value.key_results)}%)`;
    }

    return goalsOk.value ? null : 'Captura su línea base y su meta';
});
</script>

<template>
    <article
        class="overflow-hidden rounded-2xl border bg-card shadow-sm transition duration-300 hover:shadow-md"
        :class="goalsOk ? 'border-emerald-500/30' : 'border-border'"
    >
        <!-- Encabezado: avatar, nombre, estado y flecha -->
        <button
            type="button"
            class="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-muted/30"
            @click="emit('toggle')"
        >
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br text-xs font-bold text-white shadow"
                :class="avatarTone(gestor.employee_id)"
                >{{ initialsOf(gestor.employee_name) }}</span
            >
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-bold text-foreground">
                    Gestor {{ index + 1 }} — {{ gestor.employee_name }}
                </p>
                <p class="truncate text-[11px] text-muted-foreground">
                    {{ gestor.position ?? 'Puesto no registrado' }} ·
                    {{ branchName }}
                </p>
            </div>
            <span
                v-if="goalsOk"
                class="flex items-center gap-1 rounded-full bg-emerald-500/10 px-2.5 py-1 text-[11px] font-bold text-emerald-700 dark:text-emerald-300"
                ><CheckCircle2 class="size-3.5" /> Completo</span
            >
            <span
                v-else
                class="flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-1 text-[11px] font-bold text-amber-700 dark:text-amber-300"
                ><AlertTriangle class="size-3.5" /> {{ missing }}</span
            >
            <ChevronDown
                class="size-4 text-muted-foreground transition"
                :class="open ? 'rotate-180' : ''"
            />
        </button>

        <!-- Contenido desplegado: KR del gestor y sus metas -->
        <div
            v-if="open"
            class="animate-in space-y-4 border-t border-border p-4 duration-200 fade-in"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <p
                        class="text-xs font-bold tracking-wide text-muted-foreground uppercase"
                    >
                        1 · Key Results de {{ gestor.employee_name }}
                    </p>
                    <p class="text-[11px] text-muted-foreground">
                        Sus pesos suman 100% dentro de SU OKR. Puede tener KR
                        distintos a los de la sucursal.
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="gap-1.5 rounded-xl"
                    :disabled="!canCopy"
                    @click="emit('copy')"
                >
                    <Copy class="size-3.5" /> Copiar estructura del KR general
                </Button>
            </div>
            <OkrKrEditor
                v-model:rows="gestor.key_results"
                :kpis="kpis"
                placeholder="Incrementar mi colocación de cartera"
            />

            <div v-if="structureOk" class="space-y-2">
                <p
                    class="text-xs font-bold tracking-wide text-muted-foreground uppercase"
                >
                    2 · Línea base, incremento y meta individuales
                </p>
                <OkrGoalsTable
                    v-model:rows="gestor.key_results"
                    :kpis="kpis"
                    :scope="{
                        ...scope,
                        scope_type: 'employee',
                        employee_id: gestor.employee_id,
                    }"
                    scope-label="del gestor"
                />
            </div>
            <p
                v-else
                class="rounded-xl border border-dashed border-border p-3 text-center text-xs text-muted-foreground"
            >
                Completa sus Key Results (KPI, descripción y pesos al 100%) para
                capturar su línea base y su meta.
            </p>
        </div>
    </article>
</template>
