<script setup lang="ts">
import { Info, Loader2, Lock, RefreshCw } from 'lucide-vue-next';
import { computed, toRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { useFinancialExpenses } from '@/composables/estado-resultados/useFinancialExpenses';
import AmortizationBankCheck from './AmortizationBankCheck.vue';
import AmortizationScheduleTable from './AmortizationScheduleTable.vue';
import AmortizationUploadCard from './AmortizationUploadCard.vue';
import { dmy } from './financialExpenses';
import type { CreditPeriod } from './financialExpenses';
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

const fe = useFinancialExpenses({
    base: props.base,
    periodId: toRef(props, 'periodId'),
    reloadDataset: props.reloadDataset,
});

watch(
    () => [props.periodId, props.rev, props.consolidated] as const,
    () => {
        if (props.consolidated) {
            void fe.load();
        }
    },
    { immediate: true },
);

const data = computed(() => fe.data.value);

const ORIGIN_LABEL: Record<string, string> = {
    tabla_amortizacion: 'Viene de la tabla de amortización',
    manual: 'Captura manual en Ajustes (gana sobre la tabla)',
};

function adjustmentText(c: CreditPeriod): string {
    const a = c.adjustment;

    if (!a || a.state === 'sin_informacion') {
        return c.has_table && !c.covered
            ? 'Sin información: la tabla no cubre las fechas de este periodo.'
            : 'Sin información: sube la tabla o captura el interés en Ajustes.';
    }

    return ORIGIN_LABEL[a.origin ?? 'manual'] ?? 'Captura manual';
}
</script>

<template>
    <div class="space-y-4">
        <div
            class="flex items-start gap-2 rounded-2xl border border-sky-200 bg-sky-50/70 p-3 text-xs text-sky-950 dark:border-sky-900 dark:bg-sky-950/30 dark:text-sky-100"
        >
            <Info class="mt-px size-4 shrink-0" />
            <p>
                Los pagos a LEROM y BBVA incluyen <b>capital + interés</b>. En
                el Estado de Resultados <b>sólo el interés</b> va entre EBIT y
                EBT; el capital es flujo de financiamiento y nunca entra como
                gasto. El interés de cada periodo sale de la
                <b>tabla de amortización</b>
                (suma de los pagos con fecha dentro del periodo) y llena
                automáticamente los conceptos de Ajustes. Si capturas el interés
                a mano en Ajustes, tu captura gana y aquí verás la diferencia.
            </p>
        </div>

        <p
            v-if="!consolidated"
            class="rounded-2xl border bg-muted/50 p-4 text-sm"
        >
            Los gastos financieros sólo existen en el
            <b>consolidado</b> (no se asignan a una sucursal). Cambia el alcance
            a «Consolidado MR LANA» para verlos.
        </p>

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
                class="space-y-2 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200"
            >
                <p>{{ fe.error.value }}</p>
                <Button size="sm" variant="outline" @click="fe.load()">
                    <RefreshCw class="size-3.5" /> Reintentar
                </Button>
            </div>

            <template v-if="data">
                <div
                    v-if="data.locked"
                    class="flex items-start gap-2 rounded-2xl border bg-muted/50 p-3 text-xs"
                >
                    <Lock class="mt-px size-4 shrink-0" />
                    <p>
                        {{ data.period.label }} está aprobado (versión
                        {{ data.locked.version }}): sus intereses quedan
                        congelados en esa versión. Puedes subir o sustituir
                        tablas (son del crédito, no del periodo), pero este
                        periodo sólo cambia si abres una nueva versión.
                    </p>
                </div>

                <section
                    aria-label="Resumen del periodo"
                    class="grid gap-3 sm:grid-cols-3"
                >
                    <div class="rounded-2xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Pagos según tablas ({{ dmy(data.period.start) }} –
                            {{ dmy(data.period.end) }})
                        </p>
                        <p class="text-lg font-extrabold tabular-nums">
                            {{ mxn(data.totals.payment) }}
                        </p>
                    </div>
                    <div class="rounded-2xl border bg-card p-4">
                        <p class="text-xs text-muted-foreground">
                            Capital excluido (no es gasto)
                        </p>
                        <p
                            class="text-lg font-extrabold text-muted-foreground tabular-nums"
                        >
                            {{ mxn(data.totals.principal) }}
                        </p>
                    </div>
                    <div
                        class="rounded-2xl border border-rose-200 bg-rose-50/60 p-4 dark:border-rose-900 dark:bg-rose-950/20"
                    >
                        <p class="text-xs text-muted-foreground">
                            Interés que va entre EBIT y EBT
                        </p>
                        <p
                            class="text-lg font-extrabold text-rose-700 tabular-nums dark:text-rose-400"
                        >
                            {{ mxn(data.totals.interest) }}
                        </p>
                    </div>
                </section>

                <section
                    v-for="c in data.credits"
                    :key="c.credit.key"
                    class="space-y-3"
                    :aria-label="c.credit.name"
                >
                    <AmortizationUploadCard
                        :credit="c"
                        :accept="data.rules.accept"
                        :can-upload="canUpload"
                        :uploading="fe.uploading.value === c.credit.key"
                        @upload="(f) => fe.upload(c, f)"
                        @template="fe.downloadTemplate(c.credit.key)"
                    />

                    <div
                        class="flex flex-wrap items-center justify-between gap-2 rounded-2xl border bg-card px-4 py-3 text-xs"
                    >
                        <p>
                            <b>{{ c.credit.concept_label }}</b> en este periodo:
                            <span class="font-extrabold tabular-nums">{{
                                c.adjustment &&
                                c.adjustment.state !== 'sin_informacion'
                                    ? mxn(c.adjustment.amount)
                                    : '—'
                            }}</span>
                            · {{ adjustmentText(c) }}
                            <template
                                v-if="
                                    c.adjustment?.difference &&
                                    Number(c.adjustment.difference) !== 0
                                "
                            >
                                · diferencia contra la tabla
                                <b class="text-amber-700 dark:text-amber-400">{{
                                    mxn(c.adjustment.difference)
                                }}</b>
                                (tabla {{ mxn(c.totals.interest) }})
                            </template>
                        </p>
                        <Button
                            size="sm"
                            variant="ghost"
                            class="h-7 text-xs"
                            @click="emit('goAdjustments')"
                            >Ver en Ajustes</Button
                        >
                    </div>
                    <p
                        v-if="c.frozen?.outdated"
                        class="rounded-2xl border border-amber-300 bg-amber-50 p-3 text-xs font-medium text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200"
                    >
                        Desactualizado: la versión {{ c.frozen.version }}
                        aprobada conserva
                        {{
                            c.frozen.amount === null
                                ? 'sin información'
                                : mxn(c.frozen.amount)
                        }}; con la tabla vigente sería
                        {{
                            c.frozen.live_amount === null
                                ? 'sin información'
                                : mxn(c.frozen.live_amount)
                        }}. Abre una nueva versión del periodo para aplicarla.
                    </p>

                    <template v-if="c.has_table">
                        <AmortizationScheduleTable :credit="c" />
                        <AmortizationBankCheck :credit="c" />
                    </template>
                </section>
            </template>
        </template>
    </div>
</template>
