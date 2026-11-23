<script setup lang="ts">
import {
    AlertCircle,
    ArrowRight,
    Info,
    Loader2,
    ReceiptText,
    Tags,
} from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import { appSwal as Swal } from '@/components/feedback/ConfirmDialog';
import { confirmDialog } from '@/components/feedback/ConfirmDialog';
import { toast } from '@/components/feedback/Toast';
import { money, num } from '@/lib/format';
import ExpenseConceptsPanel from './ExpenseConceptsPanel.vue';
import ExpenseDecisionDialog from './ExpenseDecisionDialog.vue';
import ExpenseReviewBulkBar from './ExpenseReviewBulkBar.vue';
import ExpenseReviewPagination from './ExpenseReviewPagination.vue';
import ExpenseReviewSummaryCards from './ExpenseReviewSummaryCards.vue';
import ExpenseReviewTable from './ExpenseReviewTable.vue';
import ExpenseReviewToolbar from './ExpenseReviewToolbar.vue';
import type { DecisionPayload, ExpenseRow, ReviewFilter } from './types';
import { FILTER_LABELS } from './types';
import { useExpenseReview } from './useExpenseReview';

/**
 * Revisión de gastos — paso OPCIONAL antes de generar el reporte. No bloquea nada:
 * «Continuar» siempre está disponible y, sin decisiones, el reporte usa las reglas de hoy.
 *
 * Se muestra dentro del flujo de Histórico general (`pages/historico-general/index.vue`).
 * Tiene dos pestañas:
 * - «Gastos»: resumen, filtros, listado paginado, selección masiva y diálogo de decisión
 *   (colaborador/sucursal/Corporativo) vía `useExpenseReview`.
 * - «Conceptos»: catálogo de conceptos con destino manual (`ExpenseConceptsPanel`).
 *
 * Rutas: las de `useExpenseReview` y GET `/historico-general/{periodo}/revision-gastos/{gasto}/bitacora`.
 */

/** Props: `period` = periodo activo del flujo (se usan `id` y `label`). */
const props = defineProps<{ period: any }>();
/**
 * Emits:
 * - `continue`: avanzar al paso de generación del reporte.
 * - `changed`: se guardó una decisión o un destino de concepto (el reporte debe regenerarse).
 */
const emit = defineEmits<{ (e: 'continue'): void; (e: 'changed'): void }>();

/** Id del periodo activo (o `null`). */
const periodId = computed<number | null>(() => props.period?.id ?? null);
/** Estado y acciones del listado de revisión. */
const review = useExpenseReview(periodId);
const {
    filter,
    search,
    source,
    sort,
    page,
    perPage,
    data,
    options,
    loading,
    saving,
    error,
    selected,
    allMatching,
    selectionCount,
} = review;

/** Pestaña visible: listado de gastos o catálogo de conceptos. */
const tab = ref<'gastos' | 'conceptos'>('gastos');
/** Visibilidad del diálogo y gasto abierto (`null` = decisión en lote). */
const dialogOpen = ref(false);
const dialogRow = ref<ExpenseRow | null>(null);
/** `true` si en esta visita se guardó algún cambio (cambia el texto del botón y muestra aviso). */
const changedThisVisit = ref(false);

/** `true` si el usuario puede decidir y el periodo no está cerrado. */
const canEdit = computed(
    () => !!options.value?.can_edit && !options.value?.is_closed,
);
/** Resumen del periodo (o `null` mientras carga). */
const summary = computed(() => data.value?.summary ?? null);
/** Filas de la página actual. */
const rows = computed(() => data.value?.rows ?? []);
/** Ruta de la bitácora del gasto abierto en el diálogo (o `null` en lote). */
const historyUrl = computed(() =>
    dialogRow.value && periodId.value
        ? `/historico-general/${periodId.value}/revision-gastos/${dialogRow.value.id}/bitacora`
        : null,
);

/** Al montar: carga la primera página y las opciones del apartado. */
onMounted(() => {
    review.load();
    review.loadOptions();
});

/**
 * Aplica el filtro de la tarjeta de resumen pulsada.
 *
 * @param f Filtro a activar.
 */
function selectFilter(f: ReviewFilter) {
    filter.value = f;
}

/**
 * Abre el diálogo de decisión/detalle para un gasto.
 *
 * @param row Gasto seleccionado.
 */
function openRow(row: ExpenseRow) {
    dialogRow.value = row;
    dialogOpen.value = true;
}

/** Abre el diálogo en modo lote para la selección actual. */
function openBulk() {
    dialogRow.value = null;
    dialogOpen.value = true;
}

/**
 * Guarda la decisión emitida por el diálogo. En lote con más de un gasto pide confirmación.
 * Tras guardar cierra el diálogo, marca la visita como modificada, emite `changed` y muestra
 * un toast; si falla, muestra el error con SweetAlert2.
 *
 * @param payload Decisión validada por el diálogo.
 */
async function onSave(payload: DecisionPayload) {
    const isBulk = !dialogRow.value;
    const count = isBulk ? selectionCount.value : 1;

    if (isBulk && count > 1) {
        const ok = await confirmDialog({
            title: `¿Aplicar a ${num(count)} gastos?`,
            text: 'La decisión se guardará para cada gasto seleccionado y se conservará aunque se vuelvan a cargar los archivos. Puedes quitarla después.',
            icon: 'question',
            confirmText: 'Sí, aplicar',
        });

        if (!ok.isConfirmed) {
            return;
        }
    }

    try {
        const res = await review.save(
            payload,
            isBulk ? undefined : [dialogRow.value!.id],
        );
        dialogOpen.value = false;
        changedThisVisit.value = true;
        emit('changed');
        toast.fire({ icon: 'success', title: res.message });
    } catch (e: any) {
        Swal.fire({
            icon: 'error',
            title: 'No se pudo guardar',
            text: e?.message ?? 'Intenta de nuevo.',
        });
    }
}

/** Un destino de concepto cambió: marca la visita como modificada, emite `changed` y recarga el listado. */
function onConceptChanged() {
    changedThisVisit.value = true;
    emit('changed');
    review.load();
}
</script>

<template>
    <section class="space-y-5" aria-labelledby="revision-gastos-title">
        <!-- Encabezado del paso + botón Continuar (siempre disponible) -->
        <header
            class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-5 shadow-soft-sm lg:flex-row lg:items-start lg:justify-between"
        >
            <div class="max-w-3xl">
                <p class="app-kicker">Paso opcional</p>
                <h2
                    id="revision-gastos-title"
                    class="mt-1 text-xl font-bold text-foreground"
                >
                    Revisar gastos
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Revisa el desglose de los gastos de
                    {{ period?.label ?? 'este periodo' }} y, si quieres más
                    detalle, indica a qué colaborador o sucursal pertenece cada
                    uno, o márcalo como corporativo. Puedes continuar sin
                    revisar: el reporte se genera con las reglas automáticas de
                    siempre.
                </p>
            </div>
            <button
                type="button"
                class="app-btn app-btn-primary h-10 shrink-0"
                @click="emit('continue')"
            >
                {{
                    changedThisVisit
                        ? 'Continuar a generar reporte'
                        : 'Continuar sin revisar'
                }}
                <ArrowRight class="size-4" aria-hidden="true" />
            </button>
        </header>

        <!-- Avisos: migración pendiente / cambios guardados -->
        <div
            v-if="options && !options.installed"
            class="app-banner app-banner-warning"
        >
            <Info aria-hidden="true" />
            <p>
                La revisión de gastos aún no está disponible en este servidor
                (falta aplicar la actualización de base de datos). Puedes
                continuar con la generación normalmente.
            </p>
        </div>

        <div v-if="changedThisVisit" class="app-banner app-banner-info text-sm">
            <Info aria-hidden="true" />
            <p>
                Guardaste cambios: genera (o vuelve a generar) el reporte para
                que la radiografía los refleje.
            </p>
        </div>

        <!-- Pestañas -->
        <div
            class="flex gap-1 rounded-xl border border-border bg-muted/40 p-1 sm:w-fit"
            role="tablist"
            aria-label="Vista de la revisión"
        >
            <button
                type="button"
                role="tab"
                :aria-selected="tab === 'gastos'"
                class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg px-4 py-1.5 text-sm font-semibold transition"
                :class="
                    tab === 'gastos'
                        ? 'bg-card text-foreground shadow-soft-sm'
                        : 'text-muted-foreground hover:text-foreground'
                "
                @click="tab = 'gastos'"
            >
                <ReceiptText class="size-4" aria-hidden="true" /> Gastos
            </button>
            <button
                type="button"
                role="tab"
                :aria-selected="tab === 'conceptos'"
                class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg px-4 py-1.5 text-sm font-semibold transition"
                :class="
                    tab === 'conceptos'
                        ? 'bg-card text-foreground shadow-soft-sm'
                        : 'text-muted-foreground hover:text-foreground'
                "
                @click="tab = 'conceptos'"
            >
                <Tags class="size-4" aria-hidden="true" /> Conceptos
            </button>
        </div>

        <!-- Pestaña Gastos: resumen, filtros, listado, paginación y barra de selección -->
        <template v-if="tab === 'gastos'">
            <ExpenseReviewSummaryCards
                :summary="summary"
                :active="filter"
                @select="selectFilter"
            />

            <ExpenseReviewToolbar
                v-model:filter="filter"
                v-model:search="search"
                v-model:source="source"
                v-model:sort="sort"
                :sources="
                    (summary?.by_source ?? []).map((s) => ({
                        code: s.code,
                        label: `${s.label} (${num(s.count)})`,
                    }))
                "
            />

            <div v-if="error" class="app-banner app-banner-danger">
                <AlertCircle aria-hidden="true" />
                <div>
                    <p class="font-semibold">{{ error }}</p>
                    <button
                        type="button"
                        class="mt-1 text-xs font-semibold underline"
                        @click="review.load()"
                    >
                        Reintentar
                    </button>
                </div>
            </div>

            <div
                v-else-if="loading && !data"
                class="space-y-2"
                aria-busy="true"
                aria-label="Cargando gastos"
            >
                <div
                    v-for="n in 6"
                    :key="n"
                    class="h-16 animate-pulse rounded-2xl bg-muted/60"
                />
            </div>

            <template v-else-if="data">
                <div
                    class="flex flex-wrap items-center justify-between gap-2 text-sm"
                >
                    <p class="text-muted-foreground">
                        {{ FILTER_LABELS[filter] }}:
                        <span class="font-semibold text-foreground">{{
                            num(data.pagination.total)
                        }}</span>
                        gasto(s) ·
                        <span
                            class="font-semibold text-foreground tabular-nums"
                            >{{ money(data.filtered_total) }}</span
                        >
                    </p>
                    <span
                        v-if="loading"
                        class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                        ><Loader2
                            class="size-3.5 animate-spin"
                            aria-hidden="true"
                        />
                        Actualizando…</span
                    >
                </div>

                <div
                    v-if="!rows.length"
                    class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-border bg-card py-12 text-center"
                >
                    <ReceiptText
                        class="size-8 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <p class="mt-3 font-semibold text-foreground">
                        {{
                            filter === 'pendientes'
                                ? 'No hay gastos pendientes de revisar.'
                                : 'No hay gastos con este filtro.'
                        }}
                    </p>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Cambia el filtro o la búsqueda para ver otros gastos.
                    </p>
                </div>

                <template v-else>
                    <ExpenseReviewTable
                        :rows="rows"
                        :selected="selected"
                        :all-matching="allMatching"
                        :can-edit="canEdit"
                        @toggle="review.toggle"
                        @toggle-page="review.togglePage"
                        @open="openRow"
                    />
                    <ExpenseReviewPagination
                        v-model:page="page"
                        v-model:per-page="perPage"
                        :total="data.pagination.total"
                        :last-page="data.pagination.last_page"
                    />
                </template>

                <ExpenseReviewBulkBar
                    v-if="canEdit"
                    :count="selectionCount"
                    :total="data.pagination.total"
                    :all-matching="allMatching"
                    :page-count="rows.length"
                    @decide="openBulk"
                    @select-all="allMatching = true"
                    @clear="review.clearSelection()"
                />
            </template>
        </template>

        <!-- Pestaña Conceptos -->
        <ExpenseConceptsPanel
            v-else-if="periodId"
            :period-id="periodId"
            :can-edit="canEdit"
            @changed="onConceptChanged"
        />

        <!-- Diálogo de decisión (individual o en lote) -->
        <ExpenseDecisionDialog
            v-model:open="dialogOpen"
            :row="dialogRow"
            :count="selectionCount"
            :options="options"
            :saving="saving"
            :history-url="historyUrl"
            @save="onSave"
        />
    </section>
</template>
