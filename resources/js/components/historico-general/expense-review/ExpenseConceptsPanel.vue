<script setup lang="ts">
import { AlertCircle, Loader2, RefreshCw, Search } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';
import { appSwal as Swal } from '@/components/feedback/ConfirmDialog';
import { confirmDialog } from '@/components/feedback/ConfirmDialog';
import { toast } from '@/components/feedback/Toast';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { money, num } from '@/lib/format';
import type { ConceptRow, ConceptsPayload } from './types';
import { reviewGet, reviewPost } from './types';

/**
 * ExpenseConceptsPanel — pestaña «Conceptos» de la Revisión de gastos.
 *
 * - Conceptos de gasto: cada concepto (Lendus/ERP) con registros, total, la línea del reporte a
 *   la que va hoy y un destino manual (categoría) que se guarda como regla para este periodo y
 *   las siguientes cargas (los periodos cerrados no cambian).
 * - Conceptos de ingreso: solo consulta, cómo se trata cada transacción en recuperación.
 *
 * Rutas: GET y POST `/historico-general/{periodo}/revision-gastos/conceptos`
 * (POST con `category: null` quita la regla manual).
 */

/** Props: `periodId` = periodo activo; `canEdit` = el usuario puede guardar destinos. */
const props = defineProps<{ periodId: number; canEdit: boolean }>();
/** Emits: `changed` al guardar o quitar un destino manual. */
const emit = defineEmits<{ (e: 'changed'): void }>();

/** Ruta del catálogo de conceptos del periodo. */
const base = computed(
    () => `/historico-general/${props.periodId}/revision-gastos/conceptos`,
);
/** Catálogo cargado, banderas de carga y error. */
const data = ref<ConceptsPayload | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
/** Filtros locales: solo conceptos sin destino y texto de búsqueda. */
const onlyPending = ref(false);
const query = ref('');
/** Categoría elegida en cada selector, por clave de concepto (borrador antes de guardar). */
const draft = ref<Record<string, string>>({});
/** Clave del concepto que se está guardando (deshabilita sus botones). */
const savingKey = ref<string | null>(null);
/** Contador de peticiones: solo la respuesta más reciente (del periodo vigente) se aplica. */
let requestSeq = 0;

/**
 * Carga el catálogo de conceptos e inicializa el borrador con la regla vigente de cada uno.
 */
async function load() {
    const seq = ++requestSeq;
    loading.value = true;
    error.value = null;

    try {
        const res = await reviewGet<ConceptsPayload>(base.value);

        if (seq !== requestSeq) {
            return;
        }

        data.value = res;
        draft.value = Object.fromEntries(
            res.gastos.map((c) => [c.key, c.rule?.category ?? '']),
        );
    } catch (e: any) {
        if (seq === requestSeq) {
            error.value =
                e?.message ?? 'No se pudo cargar el catálogo de conceptos.';
        }
    } finally {
        if (seq === requestSeq) {
            loading.value = false;
        }
    }
}

/** Carga el catálogo al montar. */
onMounted(load);

/** Cambio de periodo: descarta el catálogo anterior y recarga el del periodo nuevo. */
watch(
    () => props.periodId,
    () => {
        data.value = null;
        draft.value = {};
        load();
    },
);

/** Conceptos de gasto visibles tras aplicar «Solo sin destino» y la búsqueda (concepto o categoría). */
const gastos = computed(() => {
    const q = query.value.trim().toLowerCase();

    return (data.value?.gastos ?? []).filter(
        (c) =>
            (!onlyPending.value || c.needs_destination) &&
            (!q ||
                c.concept.toLowerCase().includes(q) ||
                c.category.toLowerCase().includes(q)),
    );
});

/** Número de conceptos de gasto sin destino («Sin clasificar»). */
const pendingCount = computed(
    () => (data.value?.gastos ?? []).filter((c) => c.needs_destination).length,
);
/** Suma de ingresos que cuentan para recuperación (bruto). */
const incomeTotal = computed(() =>
    (data.value?.ingresos ?? [])
        .filter((i) => i.counts)
        .reduce((s, i) => s + i.total, 0),
);

/**
 * Guarda (o quita) el destino manual de un concepto tras confirmar con el usuario.
 * Al terminar muestra un toast, emite `changed` y recarga el catálogo.
 *
 * @param c      Concepto a modificar.
 * @param remove `true` para quitar la regla manual (vuelve a la clasificación automática).
 */
async function save(c: ConceptRow, remove = false) {
    const category = remove ? null : draft.value[c.key] || null;

    if (!remove && !category) {
        toast.fire({
            icon: 'info',
            title: 'Elige primero la categoría destino.',
        });

        return;
    }

    const ok = await confirmDialog({
        title: remove
            ? '¿Quitar el destino manual?'
            : '¿Guardar el destino de este concepto?',
        text: remove
            ? `«${c.concept}» volverá a su clasificación automática en este periodo.`
            : `«${c.concept}» (${c.source_label}) irá a «${category}» en este periodo y en las siguientes cargas. La radiografía quedará marcada para regenerarse.`,
        icon: 'question',
        confirmText: remove ? 'Sí, quitar' : 'Sí, guardar',
    });

    if (!ok.isConfirmed) {
        return;
    }

    savingKey.value = c.key;

    try {
        const res = await reviewPost<{ message: string }>(base.value, {
            source_type: c.source_type,
            concept: c.concept,
            category,
        });
        toast.fire({ icon: 'success', title: res.message });
        emit('changed');
        await load();
    } catch (e: any) {
        Swal.fire({
            icon: 'error',
            title: 'No se pudo guardar',
            text: e?.message,
        });
    } finally {
        savingKey.value = null;
    }
}
</script>

<template>
    <div class="space-y-6">
        <!-- Estados de carga y error -->
        <div
            v-if="loading && !data"
            class="flex items-center justify-center gap-2 rounded-2xl border border-dashed border-border py-12 text-sm text-muted-foreground"
        >
            <Loader2 class="size-4 animate-spin" aria-hidden="true" /> Cargando
            conceptos del periodo…
        </div>
        <div v-else-if="error" class="app-banner app-banner-danger">
            <AlertCircle aria-hidden="true" />
            <div>
                <p class="font-semibold">{{ error }}</p>
                <button
                    type="button"
                    class="mt-1 text-xs font-semibold underline"
                    @click="load"
                >
                    Reintentar
                </button>
            </div>
        </div>

        <template v-else-if="data">
            <!-- Gastos -->
            <section class="space-y-3" aria-labelledby="conceptos-gastos">
                <div
                    class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between"
                >
                    <div>
                        <h3
                            id="conceptos-gastos"
                            class="text-base font-semibold text-foreground"
                        >
                            Conceptos de gasto
                        </h3>
                        <p class="text-sm text-muted-foreground">
                            Cada concepto con su total y la línea del reporte a
                            la que va hoy.
                            <span
                                v-if="pendingCount"
                                class="font-semibold text-warning"
                                >{{ pendingCount }} sin destino («Sin
                                clasificar»).</span
                            >
                        </p>
                    </div>
                    <div
                        class="flex flex-col gap-2 sm:flex-row sm:items-center"
                    >
                        <label
                            class="inline-flex items-center gap-2 text-xs font-medium text-muted-foreground"
                        >
                            <input
                                v-model="onlyPending"
                                type="checkbox"
                                class="accent-[var(--primary)]"
                            />
                            Solo sin destino
                        </label>
                        <div class="relative">
                            <Search
                                class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <Input
                                v-model="query"
                                type="search"
                                class="pl-9 sm:w-64"
                                placeholder="Buscar concepto"
                                aria-label="Buscar concepto"
                            />
                        </div>
                        <button
                            type="button"
                            class="app-btn app-btn-secondary h-9"
                            :disabled="loading"
                            @click="load"
                        >
                            <RefreshCw
                                class="size-4"
                                :class="loading ? 'animate-spin' : ''"
                                aria-hidden="true"
                            />
                            Actualizar
                        </button>
                    </div>
                </div>

                <div
                    class="overflow-x-auto rounded-2xl border border-border bg-card"
                >
                    <table class="w-full min-w-[760px] text-sm">
                        <thead
                            class="bg-muted/50 text-left text-xs font-semibold text-muted-foreground"
                        >
                            <tr>
                                <th class="px-3 py-2.5">Concepto</th>
                                <th class="px-3 py-2.5 text-right">
                                    Registros
                                </th>
                                <th class="px-3 py-2.5 text-right">Total</th>
                                <th class="px-3 py-2.5">Va hoy a</th>
                                <th class="px-3 py-2.5">Destino manual</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="c in gastos"
                                :key="c.key"
                                class="align-top"
                            >
                                <td class="px-3 py-3">
                                    <p class="font-semibold text-foreground">
                                        {{ c.concept }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ c.source_label }} · {{ c.category }}
                                        <span
                                            v-if="c.category_auto"
                                            class="text-info"
                                            >(automática:
                                            {{ c.category_auto }})</span
                                        >
                                    </p>
                                </td>
                                <td
                                    class="px-3 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ num(c.count) }}
                                </td>
                                <td
                                    class="px-3 py-3 text-right font-semibold tabular-nums"
                                >
                                    {{ money(c.total) }}
                                </td>
                                <td class="px-3 py-3">
                                    <Badge
                                        v-if="c.needs_destination"
                                        class="border-warning-border bg-warning-soft text-warning"
                                        >Sin clasificar</Badge
                                    >
                                    <p
                                        v-else
                                        class="text-xs font-medium text-foreground"
                                        :title="c.classification.hint ?? ''"
                                    >
                                        {{ c.classification.label }}
                                    </p>
                                </td>
                                <td class="px-3 py-3">
                                    <div
                                        v-if="canEdit"
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <NativeSelect
                                            v-model="draft[c.key]"
                                            class="w-56"
                                            :aria-label="`Categoría destino de ${c.concept}`"
                                        >
                                            <NativeSelectOption value=""
                                                >Elige
                                                categoría…</NativeSelectOption
                                            >
                                            <NativeSelectOption
                                                v-for="cat in data.categories"
                                                :key="cat"
                                                :value="cat"
                                                >{{ cat }}</NativeSelectOption
                                            >
                                        </NativeSelect>
                                        <button
                                            type="button"
                                            class="app-btn app-btn-primary h-9"
                                            :disabled="
                                                savingKey === c.key ||
                                                !draft[c.key] ||
                                                draft[c.key] ===
                                                    c.rule?.category
                                            "
                                            @click="save(c)"
                                        >
                                            <Loader2
                                                v-if="savingKey === c.key"
                                                class="size-4 animate-spin"
                                                aria-hidden="true"
                                            />
                                            Guardar
                                        </button>
                                        <button
                                            v-if="c.rule"
                                            type="button"
                                            class="text-xs font-semibold text-danger hover:underline"
                                            :disabled="savingKey === c.key"
                                            @click="save(c, true)"
                                        >
                                            Quitar
                                        </button>
                                    </div>
                                    <p
                                        v-else
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ c.rule?.category ?? '—' }}
                                    </p>
                                </td>
                            </tr>
                            <tr v-if="!gastos.length">
                                <td
                                    colspan="5"
                                    class="px-3 py-8 text-center text-sm text-muted-foreground"
                                >
                                    No hay conceptos que coincidan.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="app-help">
                    El destino manual cambia la categoría del concepto (y con
                    ella la línea del reporte) usando las mismas reglas de
                    clasificación de siempre. Se aplica a este periodo y a las
                    siguientes cargas; los periodos cerrados no se modifican.
                </p>
            </section>

            <!-- Ingresos (solo consulta) -->
            <section class="space-y-3" aria-labelledby="conceptos-ingresos">
                <div>
                    <h3
                        id="conceptos-ingresos"
                        class="text-base font-semibold text-foreground"
                    >
                        Conceptos de ingreso (recuperación)
                    </h3>
                    <p class="text-sm text-muted-foreground">
                        Solo consulta: cómo se trata hoy cada transacción. Suma
                        a recuperación:
                        <span class="font-semibold text-foreground">{{
                            money(incomeTotal)
                        }}</span>
                        (bruto, antes del ajuste de Seguro CRECE).
                    </p>
                </div>
                <div
                    class="overflow-x-auto rounded-2xl border border-border bg-card"
                >
                    <table class="w-full min-w-[640px] text-sm">
                        <thead
                            class="bg-muted/50 text-left text-xs font-semibold text-muted-foreground"
                        >
                            <tr>
                                <th class="px-3 py-2.5">
                                    Transacción · Operación
                                </th>
                                <th class="px-3 py-2.5 text-right">
                                    Registros
                                </th>
                                <th class="px-3 py-2.5 text-right">Total</th>
                                <th class="px-3 py-2.5">Va hoy a</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr
                                v-for="i in data.ingresos"
                                :key="`${i.transaction}|${i.operation}|${i.destination}`"
                            >
                                <td class="px-3 py-3">
                                    <span
                                        class="font-semibold text-foreground"
                                        >{{ i.transaction }}</span
                                    >
                                    <span class="text-muted-foreground"
                                        >· {{ i.operation }}</span
                                    >
                                </td>
                                <td
                                    class="px-3 py-3 text-right text-muted-foreground tabular-nums"
                                >
                                    {{ num(i.count) }}
                                </td>
                                <td
                                    class="px-3 py-3 text-right font-semibold tabular-nums"
                                >
                                    {{ money(i.total) }}
                                </td>
                                <td class="px-3 py-3">
                                    <Badge
                                        :class="
                                            i.counts
                                                ? 'border-success-border bg-success-soft text-success'
                                                : 'border-border bg-muted text-muted-foreground'
                                        "
                                        >{{ i.destination }}</Badge
                                    >
                                </td>
                            </tr>
                            <tr v-if="!data.ingresos.length">
                                <td
                                    colspan="4"
                                    class="px-3 py-8 text-center text-sm text-muted-foreground"
                                >
                                    Este periodo aún no tiene ingresos cargados.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
    </div>
</template>
