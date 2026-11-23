<script setup lang="ts">
/**
 * MovementDetailSheet — panel lateral con el detalle de un movimiento.
 *
 * Se usa en pages/EstadoResultados/Index. Explica si el movimiento suma al
 * resultado y por qué, muestra la partición del monto, sus dimensiones, la
 * cadena de evidencia, los datos de banco/ERP, la conciliación, la
 * clasificación financiera (con acciones para aceptar la sugerencia,
 * clasificar o crear una regla de catálogo) y el historial de clasificación
 * manual, que se pide al servidor al abrir.
 *
 * Props:
 * - movement: movimiento a mostrar (null = nada).
 * - open: si el panel está abierto.
 * - historyUrl: construye la URL del historial a partir de la huella.
 * - canClassify: permiso para clasificar.
 * - toneOf: clases de color de la insignia de un tratamiento.
 * - lookup: busca un movimiento del dataset por huella (para la cadena de evidencia).
 * - lockReason: motivo de solo lectura (versión aprobada o histórica).
 * - canCreateRule: permiso para crear reglas del catálogo de conceptos.
 *
 * Emits:
 * - update:open: abrir o cerrar el panel.
 * - classify: clasificar el movimiento (lista con su huella).
 * - acceptSuggestion: aceptar el tratamiento sugerido (huella).
 * - openMovement: abrir otro movimiento enlazado.
 * - createRule: crear una regla de catálogo con el texto del movimiento.
 */
import {
    ArrowLeftRight,
    BookMarked,
    CheckCircle2,
    CircleSlash,
    History,
    Loader2,
    Lock,
    ShieldAlert,
    Sparkles,
    Tags,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import AmountPartitionBar from './AmountPartitionBar.vue';
import DimensionGrid from './DimensionGrid.vue';
import EvidenceChain from './EvidenceChain.vue';
import { apiJson, mxn, reconciliationTone } from './types';
import type { Movement } from './types';

const props = defineProps<{
    movement: Movement | null;
    open: boolean;
    historyUrl: (fingerprint: string) => string;
    canClassify: boolean;
    toneOf: (treatment: string) => string;
    lookup: (fingerprint: string) => Movement | null;
    /** Motivo de solo lectura (versión aprobada / histórica). */
    lockReason?: string | null;
    /** Puede crear reglas del catálogo de conceptos. */
    canCreateRule?: boolean;
}>();

const emit = defineEmits<{
    'update:open': [v: boolean];
    classify: [fingerprints: string[]];
    acceptSuggestion: [fingerprint: string];
    openMovement: [m: Movement];
    createRule: [m: Movement];
}>();

/** Cambio del historial de clasificación: tratamiento nuevo y anterior, motivo, usuario y fecha. */
interface HistoryRow {
    treatment_label: string;
    previous_label: string | null;
    notes: string;
    user: string | null;
    at: string | null;
}
/** Historial de clasificación del movimiento abierto. */
const history = ref<HistoryRow[]>([]);
/** Se está leyendo el historial. */
const loadingHistory = ref(false);
/** Mensaje de error al leer el historial. */
const historyError = ref<string | null>(null);
/** Cada lectura lleva un número; una respuesta vieja (otro movimiento) se descarta. */
let historyRequest = 0;

/** Movimiento mostrado (atajo a la prop). */
const m = computed(() => props.movement);

/**
 * Lee el historial de clasificación del movimiento. Solo pide datos si el panel está
 * abierto y el movimiento tiene clasificación manual; descarta respuestas viejas.
 */
async function loadHistory(): Promise<void> {
    const id = ++historyRequest;
    const fp = props.movement?.fingerprint;
    history.value = [];
    historyError.value = null;

    if (!props.open || !fp || !props.movement?.override) {
        loadingHistory.value = false;

        return;
    }

    loadingHistory.value = true;

    try {
        const res = await apiJson<{ history: HistoryRow[] }>(
            props.historyUrl(fp),
        );

        if (id === historyRequest) {
            history.value = res.history;
        }
    } catch (e) {
        if (id === historyRequest) {
            historyError.value =
                e instanceof Error && e.message
                    ? e.message
                    : 'Revisa tu conexión e inténtalo de nuevo.';
        }
    } finally {
        if (id === historyRequest) {
            loadingHistory.value = false;
        }
    }
}

// Recarga el historial al abrir el panel o al cambiar de movimiento; immediate
// cubre el caso en que el panel ya se monta abierto con un movimiento.
watch(() => [props.open, props.movement?.fingerprint] as const, loadHistory, {
    immediate: true,
});

/** Aviso principal: suma (con monto), requiere revisión, ya está en el ERP o no suma. */
const headline = computed(() => {
    const mv = m.value;

    if (!mv) {
        return { icon: CircleSlash, cls: '', title: '', text: '' };
    }

    if (mv.impact.included) {
        return {
            icon: CheckCircle2,
            cls: 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200',
            title: `Sí suma: ${mxn(mv.impact.amount)}`,
            text: `Afecta la línea "${mv.treatment_label}". ${mv.treatment_explain}`,
        };
    }

    if (mv.needs_review) {
        return {
            icon: ShieldAlert,
            cls: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200',
            title: 'Requiere revisión: todavía no suma',
            text: mv.treatment_explain,
        };
    }

    if (mv.treatment === 'conciliado_erp') {
        return {
            icon: CheckCircle2,
            cls: 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200',
            title: 'Ya está en el ERP: no se vuelve a sumar',
            text: mv.treatment_explain,
        };
    }

    return {
        icon: CircleSlash,
        cls: 'border-slate-200 bg-slate-50 text-slate-800 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200',
        title: `No suma: ${mv.treatment_label}`,
        text: mv.treatment_explain,
    };
});
</script>

<template>
    <Sheet :open="open" @update:open="(v: boolean) => emit('update:open', v)">
        <SheetContent class="w-full overflow-y-auto sm:max-w-2xl">
            <template v-if="m">
                <!-- Encabezado: concepto, cuenta y fila, fecha -->
                <SheetHeader>
                    <SheetTitle class="pr-6 text-base">
                        {{ m.concept ?? 'Movimiento' }}
                    </SheetTitle>
                    <SheetDescription>
                        {{
                            m.source === 'erp'
                                ? 'Registro ERP'
                                : (m.account_label ?? m.account_slot) +
                                  ' · fila ' +
                                  m.source_row
                        }}
                        · {{ m.date }}
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-4 px-4 pb-6 text-sm">
                    <!-- Si suma o no al resultado y por qué -->
                    <div class="rounded-2xl border p-3" :class="headline.cls">
                        <p class="flex items-center gap-2 font-bold">
                            <component :is="headline.icon" class="size-4" />
                            {{ headline.title }}
                        </p>
                        <p class="mt-1 text-xs leading-relaxed">
                            {{ headline.text }}
                        </p>
                    </div>

                    <!-- Partición del monto, dimensiones y cadena de evidencia -->
                    <AmountPartitionBar v-if="m.amounts" :amounts="m.amounts" />
                    <DimensionGrid
                        v-if="m.dimensions"
                        :dimensions="m.dimensions"
                        :dates="m.dates"
                    />
                    <EvidenceChain
                        v-if="m.links"
                        :movement="m"
                        :lookup="lookup"
                        @open="(x) => emit('openMovement', x)"
                    />

                    <!-- Datos del movimiento: cargo, abono, folio, sucursal, beneficiario y referencia -->
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div class="rounded-xl bg-muted/50 p-2">
                            <p class="text-muted-foreground">Cargo</p>
                            <p class="font-bold tabular-nums">
                                {{ mxn(m.cargo) }}
                            </p>
                        </div>
                        <div class="rounded-xl bg-muted/50 p-2">
                            <p class="text-muted-foreground">Abono</p>
                            <p class="font-bold tabular-nums">
                                {{ mxn(m.abono) }}
                            </p>
                        </div>
                        <div class="rounded-xl bg-muted/50 p-2">
                            <p class="text-muted-foreground">Folio / REQ</p>
                            <p class="font-mono font-semibold">
                                {{ m.folio ?? '—' }}
                            </p>
                        </div>
                        <div class="rounded-xl bg-muted/50 p-2">
                            <p class="text-muted-foreground">Sucursal</p>
                            <p class="font-semibold">
                                {{ m.branch_name ?? m.sucursal_raw ?? '—' }}
                                <span class="text-[10px] text-muted-foreground"
                                    >({{ m.branch_kind }})</span
                                >
                            </p>
                        </div>
                        <div class="col-span-2 rounded-xl bg-muted/50 p-2">
                            <p class="text-muted-foreground">
                                Beneficiario · referencia bancaria
                            </p>
                            <p class="font-semibold">
                                {{ m.beneficiary ?? '—' }}
                            </p>
                            <p
                                class="text-[11px] break-all text-muted-foreground"
                            >
                                {{ m.reference ?? '' }}
                            </p>
                            <p
                                v-if="m.uuid"
                                class="font-mono text-[10.5px] break-all"
                            >
                                UUID {{ m.uuid }}
                            </p>
                            <p v-if="m.observations" class="mt-1 text-[11px]">
                                Obs.: {{ m.observations }}
                            </p>
                        </div>
                    </div>

                    <!-- Conciliación con ERP y banco -->
                    <section class="space-y-2">
                        <h3 class="text-sm font-extrabold">Conciliación</h3>
                        <span
                            class="inline-block rounded-full border px-2 py-0.5 text-xs font-semibold"
                            :class="reconciliationTone(m.reconciliation_status)"
                            >{{ m.reconciliation_label }}</span
                        >
                        <p
                            v-if="m.reconciliation_message"
                            class="text-xs leading-relaxed"
                        >
                            {{ m.reconciliation_message }}
                        </p>
                        <div
                            v-if="m.erp.length"
                            class="overflow-hidden rounded-xl border"
                        >
                            <table class="w-full text-[11px]">
                                <thead class="bg-muted/60 text-left">
                                    <tr>
                                        <th class="px-2 py-1">Folio ERP</th>
                                        <th class="px-2 py-1 text-right">
                                            Monto
                                        </th>
                                        <th class="px-2 py-1">Fecha</th>
                                        <th class="px-2 py-1">Sucursal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="e in m.erp"
                                        :key="e.fingerprint"
                                        class="border-t"
                                    >
                                        <td class="px-2 py-1 font-mono">
                                            {{ e.folio }}
                                        </td>
                                        <td
                                            class="px-2 py-1 text-right tabular-nums"
                                        >
                                            {{ mxn(e.amount) }}
                                        </td>
                                        <td class="px-2 py-1">
                                            {{ e.date }}
                                            <span class="text-muted-foreground"
                                                >({{
                                                    e.date_source ===
                                                    'fecha_pago'
                                                        ? 'pago'
                                                        : 'captura'
                                                }})</span
                                            >
                                        </td>
                                        <td class="px-2 py-1">
                                            {{ e.branch_name ?? '—' }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <p v-if="m.linked_bank.length" class="text-xs">
                            Pagado en banco:
                            {{
                                m.linked_bank
                                    .map(
                                        (b) =>
                                            `${b.account} fila ${b.row} (${mxn(b.amount)}, ${b.date})`,
                                    )
                                    .join(' · ')
                            }}
                        </p>
                        <p
                            v-if="m.transfer_pair"
                            class="flex items-center gap-1 text-xs text-cyan-700 dark:text-cyan-400"
                        >
                            <ArrowLeftRight class="size-3.5" /> Traspaso
                            emparejado con la otra cuenta ({{
                                m.transfer_pair
                            }}): no se cuenta dos veces.
                        </p>
                        <p
                            v-if="m.detail_lines"
                            class="text-xs text-muted-foreground"
                        >
                            {{ m.detail_lines }} línea(s) de comprobación
                            (facturas/notas) conservadas para auditoría; no se
                            suman.
                        </p>
                    </section>

                    <!-- Clasificación financiera y acciones -->
                    <section class="space-y-2">
                        <h3 class="text-sm font-extrabold">
                            Clasificación financiera
                        </h3>
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="rounded-full border px-2 py-0.5 text-xs font-semibold"
                                :class="toneOf(m.treatment)"
                                >{{ m.treatment_label }}</span
                            >
                            <span class="text-[11px] text-muted-foreground">{{
                                m.treatment_source === 'manual'
                                    ? 'Clasificación manual'
                                    : m.treatment_source === 'catalogo'
                                      ? `Catálogo de conceptos · ${m.concept_label ?? ''}`
                                      : 'Regla automática'
                            }}</span>
                        </div>
                        <p class="text-xs leading-relaxed">
                            <b>Regla:</b> {{ m.rule_reason ?? '—' }}
                        </p>
                        <p
                            v-if="
                                m.suggested_label && m.treatment === 'pendiente'
                            "
                            class="text-xs"
                        >
                            <b>Sugerencia:</b> {{ m.suggested_label }}
                        </p>
                        <div
                            v-if="m.override"
                            class="rounded-xl border border-violet-200 bg-violet-50/60 p-2 text-xs dark:border-violet-900 dark:bg-violet-950/20"
                        >
                            Clasificado por
                            <b>{{ m.override.user ?? '—' }}</b> el
                            {{ m.override.at }} · motivo: {{ m.override.notes }}
                        </div>
                        <Button
                            v-if="
                                canClassify &&
                                !lockReason &&
                                m.treatment === 'pendiente' &&
                                m.suggested_treatment
                            "
                            size="sm"
                            variant="outline"
                            class="mr-2"
                            @click="emit('acceptSuggestion', m.fingerprint)"
                        >
                            <Sparkles class="size-3.5 text-amber-500" />
                            Aceptar sugerencia
                        </Button>
                        <Button
                            v-if="canClassify && !lockReason"
                            size="sm"
                            class="bg-emerald-700 text-white hover:bg-emerald-800"
                            @click="emit('classify', [m.fingerprint])"
                        >
                            <Tags class="size-3.5" /> Clasificar / corregir
                        </Button>
                        <Button
                            v-if="
                                canCreateRule &&
                                m.source === 'banco' &&
                                !m.redacted &&
                                (m.concept || m.observations)
                            "
                            size="sm"
                            variant="outline"
                            class="ml-2"
                            @click="emit('createRule', m)"
                        >
                            <BookMarked class="size-3.5" /> Crear regla de
                            catálogo con este texto
                        </Button>
                        <p
                            v-if="lockReason"
                            class="flex items-start gap-1.5 rounded-xl bg-muted/60 p-2.5 text-xs text-muted-foreground"
                        >
                            <Lock class="mt-0.5 size-3.5 shrink-0" />
                            {{ lockReason }}
                        </p>
                    </section>

                    <!-- Historial de clasificación manual -->
                    <section v-if="m.override" class="space-y-2">
                        <h3
                            class="flex items-center gap-1 text-sm font-extrabold"
                        >
                            <History class="size-3.5" /> Historial de
                            clasificación
                        </h3>
                        <p
                            v-if="loadingHistory"
                            class="flex items-center gap-2 text-xs text-muted-foreground"
                            role="status"
                        >
                            <Loader2
                                class="size-4 animate-spin text-emerald-600"
                            />
                            Leyendo el historial…
                        </p>
                        <div
                            v-else-if="historyError"
                            role="alert"
                            class="flex flex-wrap items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 p-2.5 text-xs text-rose-900 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200"
                        >
                            <span class="min-w-0 flex-1"
                                ><b>No se pudo cargar el historial.</b>
                                {{ historyError }}</span
                            >
                            <Button
                                size="sm"
                                variant="outline"
                                class="h-7 rounded-lg"
                                @click="loadHistory"
                            >
                                Reintentar
                            </Button>
                        </div>
                        <p
                            v-else-if="!history.length"
                            class="text-xs text-muted-foreground"
                        >
                            Todavía no hay cambios registrados para este
                            movimiento.
                        </p>
                        <ol
                            v-else
                            class="space-y-1.5 border-l-2 border-emerald-200 pl-3 text-xs dark:border-emerald-900"
                        >
                            <li v-for="(h, i) in history" :key="i">
                                <b>{{ h.treatment_label }}</b>
                                <span class="text-muted-foreground"
                                    >(antes:
                                    {{ h.previous_label ?? '—' }})</span
                                ><br />
                                <span class="text-muted-foreground"
                                    >{{ h.user ?? '—' }} · {{ h.at }}</span
                                >
                                — {{ h.notes }}
                            </li>
                        </ol>
                    </section>
                </div>
            </template>
        </SheetContent>
    </Sheet>
</template>
