<script setup lang="ts">
/**
 * FinancialExpensesPanel — pestaña de gastos financieros (intereses LEROM/BBVA).
 *
 * Se usa en pages/EstadoResultados/Index. Solo funciona en el consolidado.
 * Vista visual: chips de periodo/aprobado/sin conciliar, KPI y barra del pago
 * (FinancialSummaryTiles), una tarjeta por crédito (CreditStatusCard: estado,
 * anillo de pagos, próximo pago, saldo según tabla, detalle y carga) y el
 * prorrateo por sucursal (FinancialAllocationBars). Las explicaciones van en popovers.
 *
 * Props:
 * - base: URL base del módulo para las peticiones.
 * - periodId: periodo consultado.
 * - consolidated: el alcance mostrado es el consolidado.
 * - canUpload: permiso para subir tablas (estado_resultados,ajustes).
 * - rev: cambia al recargar el dataset; dispara releer el panel.
 * - reloadDataset: recarga el dataset del reporte tras subir una tabla.
 *
 * Emits:
 * - goAdjustments: ir a la pestaña Ajustes.
 */
import {
    CircleAlert,
    Landmark,
    Loader2,
    Lock,
    RefreshCw,
} from 'lucide-vue-next';
import { computed, toRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { useFinancialExpenses } from '@/composables/estado-resultados/useFinancialExpenses';
import CreditStatusCard from './CreditStatusCard.vue';
import FinancialAllocationBars from './FinancialAllocationBars.vue';
import { dmy } from './financialExpenses';
import FinancialSummaryTiles from './FinancialSummaryTiles.vue';
import FinInfoTip from './FinInfoTip.vue';
import { mxn } from './types';

const props = defineProps<{
    base: string;
    periodId: number;
    /** El alcance mostrado es el consolidado (los intereses sólo existen ahí). */
    consolidated: boolean;
    /** Permiso estado_resultados,ajustes. */
    canUpload: boolean;
    /** Cambia cuando el dataset se recarga (para releer el panel). */
    rev: string | null;
    reloadDataset: () => Promise<void>;
}>();

const emit = defineEmits<{ goAdjustments: [] }>();

/** Estado y acciones del panel (carga, subida de tablas y descarga de plantilla). */
const fe = useFinancialExpenses({
    base: props.base,
    periodId: toRef(props, 'periodId'),
    reloadDataset: props.reloadDataset,
});

// Recarga el panel al cambiar periodo, revisión del dataset o alcance (solo en consolidado).
watch(
    () => [props.periodId, props.rev, props.consolidated] as const,
    () => {
        if (props.consolidated) {
            void fe.load();
        }
    },
    { immediate: true },
);

/** Datos del panel ya cargados (null mientras no hay). */
const data = computed(() => fe.data.value);

/** Ayuda general de la pestaña (antes era un párrafo fijo). */
const HELP =
    'Los pagos a los acreedores incluyen capital + interés + comisión + IVA.\n' +
    'Sólo el interés y la comisión van entre EBIT y EBT; el capital es flujo de financiamiento y el IVA va separado.\n' +
    'El interés sale de la tabla de amortización (pagos con fecha en el periodo) y llena Ajustes; si lo capturas a mano en Ajustes, tu captura gana.';
</script>

<template>
    <div class="space-y-4">
        <div
            v-if="!consolidated"
            class="flex flex-col items-center gap-2 rounded-2xl border border-dashed bg-muted/30 p-8 text-center text-sm"
        >
            <Landmark class="size-8 text-muted-foreground" />
            <p class="font-semibold">Sólo en el consolidado</p>
            <p class="text-xs text-muted-foreground">
                Cambia el alcance a «Consolidado MR LANA».
            </p>
        </div>

        <template v-else>
            <div
                v-if="fe.loading.value && !data"
                class="flex items-center gap-2 p-6 text-sm text-muted-foreground"
            >
                <Loader2 class="size-4 animate-spin text-emerald-600" />
                Cargando gastos financieros…
            </div>
            <div
                v-else-if="fe.error.value && !data"
                class="flex flex-wrap items-center gap-3 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200"
            >
                <CircleAlert class="size-5 shrink-0" />
                <p class="flex-1">{{ fe.error.value }}</p>
                <Button size="sm" variant="outline" @click="fe.load()">
                    <RefreshCw class="size-3.5" /> Reintentar
                </Button>
            </div>

            <template v-if="data">
                <div
                    class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                >
                    <span class="font-semibold text-foreground"
                        >{{ dmy(data.period.start) }} –
                        {{ dmy(data.period.end) }}</span
                    >
                    <FinInfoTip :text="HELP" label="Cómo se calcula" />
                    <span
                        v-if="data.locked"
                        class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-semibold"
                        :title="`Periodo aprobado: intereses congelados en la versión ${data.locked.version}. Las tablas se pueden sustituir; el periodo sólo cambia con una nueva versión.`"
                    >
                        <Lock class="size-3" /> Aprobado v{{
                            data.locked.version
                        }}
                    </span>
                    <span
                        v-if="data.summary && data.summary.unreconciled.count"
                        class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2 py-0.5 font-semibold text-amber-900 dark:bg-amber-950/50 dark:text-amber-300"
                        title="Pagos de la tabla que lo aplicado (aportaciones y pagos al acreedor) aún no alcanza a cubrir"
                    >
                        <CircleAlert class="size-3" />
                        {{ data.summary.unreconciled.count }} sin cubrir ·
                        faltan {{ mxn(data.summary.unreconciled.amount) }}
                    </span>
                </div>

                <FinancialSummaryTiles
                    v-if="data.summary"
                    :summary="data.summary"
                    :scheduled="data.totals.payment"
                />

                <div
                    v-if="!data.credits.length"
                    class="flex flex-col items-center gap-2 rounded-2xl border border-dashed p-8 text-center text-sm text-muted-foreground"
                >
                    <Landmark class="size-8" />
                    No hay créditos configurados.
                </div>
                <div v-else class="grid gap-4 xl:grid-cols-2">
                    <CreditStatusCard
                        v-for="c in data.credits"
                        :key="c.credit.key"
                        :credit="c"
                        :accept="data.rules.accept"
                        :can-upload="canUpload"
                        :uploading="fe.uploading.value === c.credit.key"
                        @upload="(f, k) => fe.upload(c, f, k)"
                        @template="fe.downloadTemplate(c.credit.key)"
                        @go-adjustments="emit('goAdjustments')"
                    />
                </div>

                <FinancialAllocationBars
                    v-if="data.allocation && data.allocation.credits.length"
                    :allocation="data.allocation"
                />
            </template>
        </template>
    </div>
</template>
