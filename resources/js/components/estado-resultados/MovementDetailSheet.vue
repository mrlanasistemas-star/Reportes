<script setup lang="ts">
/**
 * MovementDetailSheet — detalle de un movimiento en un modal centrado (ErModal).
 *
 * Se usa en pages/EstadoResultados/Index (sobre cualquier pestaña, incluso encima del
 * modal de la lista de control). Jerarquía: importe grande, concepto y chips (si suma,
 * tratamiento, conciliación, origen de la clasificación); «¿A dónde va cada peso?» como
 * barra; las dimensiones como cuadrícula de chips con icono; la información secundaria
 * (datos bancarios, conciliación con ERP, evidencia, regla e historial) en secciones
 * plegables. Las acciones (aceptar sugerencia, clasificar, crear regla) quedan en el pie
 * fijo del modal. El historial de clasificación manual se pide al servidor al abrir.
 *
 * Props:
 * - movement: movimiento a mostrar (null = nada).
 * - open: si el modal está abierto.
 * - historyUrl: construye la URL del historial a partir de la huella.
 * - canClassify: permiso para clasificar.
 * - toneOf: clases de color de la insignia de un tratamiento.
 * - lookup: busca un movimiento del dataset por huella (para la cadena de evidencia).
 * - lockReason: motivo de solo lectura (versión aprobada o histórica).
 * - canCreateRule: permiso para crear reglas del catálogo de conceptos.
 *
 * Emits:
 * - update:open: abrir o cerrar el modal.
 * - classify: clasificar el movimiento (lista con su huella).
 * - acceptSuggestion: aceptar el tratamiento sugerido (huella).
 * - consider: marcar «Ya considerado» (total o parcial) (lista con su huella).
 * - revert: deshacer la clasificación manual / el reparto (huella).
 * - openMovement: abrir otro movimiento enlazado.
 * - createRule: crear una regla de catálogo con el texto del movimiento.
 */
import {
    ArrowDownLeft,
    ArrowLeftRight,
    ArrowUpRight,
    BookMarked,
    CheckCircle2,
    CircleSlash,
    Undo2,
    FileText,
    GitCompareArrows,
    History,
    Info,
    Link2,
    Loader2,
    Lock,
    ShieldAlert,
    Sparkles,
    Tags,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import AmountPartitionBar from './AmountPartitionBar.vue';
import DimensionGrid from './DimensionGrid.vue';
import ErDisclosure from './ErDisclosure.vue';
import ErModal from './ErModal.vue';
import type { ErTone } from './erTone';
import EvidenceChain from './EvidenceChain.vue';
import { apiJson, mxn, num, reconciliationTone } from './types';
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
    consider: [fingerprints: string[]];
    revert: [fingerprint: string];
    openMovement: [m: Movement];
    createRule: [m: Movement];
}>();

/** Cambio del historial de clasificación: tratamiento nuevo y anterior, motivo, usuario y fecha. */
interface HistoryRow {
    treatment_label: string;
    considered_amount?: string | null;
    considered_reason_label?: string | null;
    previous_label: string | null;
    notes: string;
    user: string | null;
    at: string | null;
}

// ── Estado (todo antes de cualquier watch) ─────────────────────────────

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

/** Aviso principal: suma (con monto), requiere revisión, ya está en el ERP o no suma. */
const headline = computed<{
    icon: typeof CheckCircle2;
    tone: ErTone;
    chip: string;
    text: string;
}>(() => {
    const mv = m.value;

    if (!mv) {
        return { icon: CircleSlash, tone: 'slate', chip: '', text: '' };
    }

    if (mv.impact.included) {
        return {
            icon: CheckCircle2,
            tone: 'emerald',
            chip: `Sí suma: ${mxn(mv.impact.amount)}`,
            text: `Afecta la línea "${mv.treatment_label}". ${mv.treatment_explain}`,
        };
    }

    if (mv.needs_review) {
        return {
            icon: ShieldAlert,
            tone: 'amber',
            chip: 'Requiere revisión: todavía no suma',
            text: mv.treatment_explain,
        };
    }

    if (mv.treatment === 'conciliado_erp') {
        return {
            icon: CheckCircle2,
            tone: 'cyan',
            chip: 'Ya está en el ERP: no se vuelve a sumar',
            text: mv.treatment_explain,
        };
    }

    return {
        icon: CircleSlash,
        tone: 'slate',
        chip: `No suma: ${mv.treatment_label}`,
        text: mv.treatment_explain,
    };
});

/** Clases del chip principal por tono. */
const HEAD_CHIP: Record<ErTone, string> = {
    emerald: 'bg-emerald-600 text-white',
    amber: 'bg-amber-500 text-white',
    cyan: 'bg-cyan-600 text-white',
    slate: 'bg-slate-600 text-white dark:bg-slate-500',
    rose: 'bg-rose-600 text-white',
    sky: 'bg-sky-600 text-white',
    violet: 'bg-violet-600 text-white',
};

/** Importe principal (cargo o abono) y su sentido. */
const main = computed(() => {
    const mv = m.value;

    if (!mv) {
        return null;
    }

    return num(mv.cargo)
        ? { amount: mv.cargo, kind: 'cargo' as const }
        : { amount: mv.abono, kind: 'abono' as const };
});

/** Subtítulo: cuenta y fila (o registro ERP) y fecha. */
const subtitle = computed(() => {
    const mv = m.value;

    if (!mv) {
        return '';
    }

    return `${
        mv.source === 'erp'
            ? 'Registro ERP'
            : `${mv.account_label ?? mv.account_slot} · fila ${mv.source_row}`
    } · ${mv.date}`;
});

/** Origen de la clasificación. */
const sourceLabel = computed(() => {
    const mv = m.value;

    if (!mv) {
        return '';
    }

    return mv.treatment_source === 'manual'
        ? 'Clasificación manual'
        : mv.treatment_source === 'catalogo'
          ? `Catálogo · ${mv.concept_label ?? ''}`
          : 'Regla automática';
});

/** Acciones visibles en el pie. */
const can = computed(() => {
    const mv = m.value;
    const open = props.canClassify && !props.lockReason;

    return {
        accept:
            !!mv &&
            open &&
            mv.treatment === 'pendiente' &&
            !!mv.suggested_treatment,
        classify: !!mv && open,
        consider: !!mv && open && mv.source !== 'noi_corp',
        revert: !!mv && open && !!mv.override,
        rule:
            !!mv &&
            !!props.canCreateRule &&
            mv.source === 'banco' &&
            !mv.redacted &&
            !!(mv.concept || mv.observations),
    };
});

/**
 * Lee el historial de clasificación del movimiento. Solo pide datos si el modal está
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

// Recarga el historial al abrir el modal o al cambiar de movimiento; immediate
// cubre el caso en que el modal ya se monta abierto con un movimiento.
watch(() => [props.open, props.movement?.fingerprint] as const, loadHistory, {
    immediate: true,
});
</script>

<template>
    <ErModal
        :open="open && !!m"
        :title="m?.concept ?? 'Movimiento'"
        :description="subtitle"
        :icon="headline.icon"
        :tone="headline.tone"
        size="lg"
        @update:open="(v) => emit('update:open', v)"
    >
        <template v-if="m" #badge>
            <span
                class="rounded-full border px-3 py-1 text-sm font-semibold"
                :class="toneOf(m.treatment)"
                >{{ m.treatment_label }}</span
            >
        </template>

        <div v-if="m && main" class="space-y-4">
            <!-- Importe grande, concepto y chips -->
            <section
                class="rounded-3xl border bg-gradient-to-br from-muted/60 to-background p-5"
            >
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p
                            class="flex items-center gap-1.5 text-sm font-semibold"
                            :class="
                                main.kind === 'cargo'
                                    ? 'text-rose-700 dark:text-rose-400'
                                    : 'text-emerald-700 dark:text-emerald-400'
                            "
                        >
                            <component
                                :is="
                                    main.kind === 'cargo'
                                        ? ArrowUpRight
                                        : ArrowDownLeft
                                "
                                class="size-4"
                            />
                            {{
                                main.kind === 'cargo'
                                    ? 'Cargo (sale dinero)'
                                    : 'Abono (entra dinero)'
                            }}
                        </p>
                        <p
                            class="mt-1 text-3xl font-extrabold tracking-tight tabular-nums sm:text-4xl"
                        >
                            {{ mxn(main.amount) }}
                        </p>
                        <p
                            v-if="num(m.cargo) && num(m.abono)"
                            class="text-sm text-muted-foreground tabular-nums"
                        >
                            Abono {{ mxn(m.abono) }}
                        </p>
                    </div>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-bold shadow-sm"
                        :class="HEAD_CHIP[headline.tone]"
                    >
                        <component :is="headline.icon" class="size-4" />
                        {{ headline.chip }}
                    </span>
                </div>
                <p
                    v-if="headline.text"
                    class="mt-3 line-clamp-2 text-sm leading-relaxed text-muted-foreground"
                    :title="headline.text"
                >
                    {{ headline.text }}
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <span
                        class="rounded-full border px-2.5 py-0.5 text-xs font-semibold"
                        :class="reconciliationTone(m.reconciliation_status)"
                        >{{ m.reconciliation_label }}</span
                    >
                    <span
                        class="rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold text-muted-foreground"
                        >{{ sourceLabel }}</span
                    >
                    <span
                        v-if="m.folio"
                        class="rounded-full bg-muted px-2.5 py-0.5 font-mono text-xs font-semibold"
                        >Folio {{ m.folio }}</span
                    >
                    <span
                        v-if="m.branch_name || m.sucursal_raw"
                        class="rounded-full bg-muted px-2.5 py-0.5 text-xs font-semibold"
                        >{{ m.branch_name ?? m.sucursal_raw }}
                        <span class="font-normal text-muted-foreground"
                            >({{ m.branch_kind }})</span
                        ></span
                    >
                    <span
                        v-if="m.transfer_pair"
                        class="inline-flex items-center gap-1 rounded-full bg-cyan-100 px-2.5 py-0.5 text-xs font-semibold text-cyan-800 dark:bg-cyan-950/60 dark:text-cyan-300"
                        :title="`Traspaso emparejado con la otra cuenta (${m.transfer_pair}): no se cuenta dos veces.`"
                    >
                        <ArrowLeftRight class="size-3.5" /> Traspaso emparejado
                    </span>
                </div>
                <p
                    v-if="m.suggested_label && m.treatment === 'pendiente'"
                    class="mt-3 inline-flex items-center gap-1.5 rounded-xl bg-violet-50 px-3 py-1.5 text-sm font-semibold text-violet-800 dark:bg-violet-950/30 dark:text-violet-300"
                >
                    <Sparkles class="size-4" /> Sugerencia:
                    {{ m.suggested_label }}
                </p>
            </section>

            <!-- ¿A dónde va cada peso? -->
            <section
                v-if="m.amounts"
                class="rounded-2xl border bg-card p-4 shadow-soft-xs"
            >
                <AmountPartitionBar :amounts="m.amounts" />
            </section>

            <!-- Dimensiones -->
            <section
                v-if="m.dimensions"
                class="rounded-2xl border bg-card p-4 shadow-soft-xs"
            >
                <DimensionGrid :dimensions="m.dimensions" :dates="m.dates" />
            </section>

            <!-- Información secundaria, plegable -->
            <ErDisclosure
                title="Regla y clasificación"
                :icon="Tags"
                :hint="sourceLabel"
            >
                <p class="text-sm leading-relaxed">
                    <b>Regla:</b> {{ m.rule_reason ?? '—' }}
                </p>
                <p
                    v-if="m.override"
                    class="mt-2 rounded-xl border border-violet-200 bg-violet-50/60 p-3 text-sm dark:border-violet-900 dark:bg-violet-950/20"
                >
                    Clasificado por <b>{{ m.override.user ?? '—' }}</b> el
                    {{ m.override.at }} · motivo: {{ m.override.notes }}
                </p>
                <p
                    v-if="num(m.considered ?? 0)"
                    class="mt-2 flex items-center gap-1.5 rounded-xl border border-teal-200 bg-teal-50/60 p-3 text-sm dark:border-teal-900 dark:bg-teal-950/20"
                >
                    <CheckCircle2 class="size-4 shrink-0 text-teal-600" />
                    <span
                        >Ya considerado <b>{{ mxn(m.considered) }}</b> (no
                        suma); el resto va a <b>{{ m.treatment_label }}</b
                        >.</span
                    >
                </p>
            </ErDisclosure>

            <ErDisclosure
                title="Conciliación con ERP y banco"
                :icon="GitCompareArrows"
                :hint="
                    m.erp.length
                        ? `${m.erp.length} folio(s) ERP`
                        : m.reconciliation_label
                "
                :open="m.erp.length > 0"
            >
                <p
                    v-if="m.reconciliation_message"
                    class="text-sm leading-relaxed"
                >
                    {{ m.reconciliation_message }}
                </p>
                <div
                    v-if="m.erp.length"
                    class="mt-3 overflow-x-auto rounded-xl border"
                >
                    <table class="w-full text-sm">
                        <thead
                            class="bg-muted/60 text-left text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-3 py-2 font-semibold">
                                    Folio ERP
                                </th>
                                <th class="px-3 py-2 text-right font-semibold">
                                    Monto
                                </th>
                                <th class="px-3 py-2 font-semibold">Fecha</th>
                                <th class="px-3 py-2 font-semibold">
                                    Sucursal
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="e in m.erp"
                                :key="e.fingerprint"
                                class="border-t"
                            >
                                <td class="px-3 py-2 font-mono">
                                    {{ e.folio }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right font-semibold tabular-nums"
                                >
                                    {{ mxn(e.amount) }}
                                </td>
                                <td class="px-3 py-2 tabular-nums">
                                    {{ e.date }}
                                    <span class="text-xs text-muted-foreground"
                                        >({{
                                            e.date_source === 'fecha_pago'
                                                ? 'pago'
                                                : 'captura'
                                        }})</span
                                    >
                                </td>
                                <td class="px-3 py-2">
                                    {{ e.branch_name ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="m.linked_bank.length" class="mt-3 text-sm">
                    <b>Pagado en banco:</b>
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
                    class="mt-3 flex items-center gap-1.5 text-sm text-cyan-700 dark:text-cyan-400"
                >
                    <ArrowLeftRight class="size-4" /> Traspaso emparejado con la
                    otra cuenta ({{ m.transfer_pair }}): no se cuenta dos veces.
                </p>
                <p
                    v-if="m.detail_lines"
                    class="mt-3 text-sm text-muted-foreground"
                >
                    {{ m.detail_lines }} línea(s) de comprobación
                    (facturas/notas) conservadas para auditoría; no se suman.
                </p>
                <p
                    v-if="
                        !m.reconciliation_message &&
                        !m.erp.length &&
                        !m.linked_bank.length &&
                        !m.transfer_pair &&
                        !m.detail_lines
                    "
                    class="text-sm text-muted-foreground"
                >
                    Sin datos de conciliación para este movimiento.
                </p>
            </ErDisclosure>

            <ErDisclosure
                v-if="m.links"
                title="Cadena de evidencia"
                :icon="Link2"
                :hint="`${m.links.length} vínculo(s)`"
            >
                <EvidenceChain
                    :movement="m"
                    :lookup="lookup"
                    @open="(x) => emit('openMovement', x)"
                />
            </ErDisclosure>

            <ErDisclosure
                title="Datos del movimiento"
                :icon="FileText"
                :hint="m.beneficiary ?? null"
            >
                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs text-muted-foreground">Cargo</dt>
                        <dd class="font-bold tabular-nums">
                            {{ mxn(m.cargo) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Abono</dt>
                        <dd class="font-bold tabular-nums">
                            {{ mxn(m.abono) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">
                            Folio / REQ
                        </dt>
                        <dd class="font-mono font-semibold">
                            {{ m.folio ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-muted-foreground">Sucursal</dt>
                        <dd class="font-semibold">
                            {{ m.branch_name ?? m.sucursal_raw ?? '—' }}
                            <span class="text-xs text-muted-foreground"
                                >({{ m.branch_kind }})</span
                            >
                        </dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-xs text-muted-foreground">
                            Beneficiario · referencia bancaria
                        </dt>
                        <dd class="font-semibold">
                            {{ m.beneficiary ?? '—' }}
                        </dd>
                        <dd
                            v-if="m.reference"
                            class="text-xs break-all text-muted-foreground"
                        >
                            {{ m.reference }}
                        </dd>
                        <dd v-if="m.uuid" class="font-mono text-xs break-all">
                            UUID {{ m.uuid }}
                        </dd>
                        <dd v-if="m.observations" class="mt-1 text-sm">
                            Obs.: {{ m.observations }}
                        </dd>
                    </div>
                </dl>
            </ErDisclosure>

            <ErDisclosure
                v-if="m.override"
                title="Historial de clasificación"
                :icon="History"
                :hint="history.length ? `${history.length} cambio(s)` : null"
            >
                <p
                    v-if="loadingHistory"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                    role="status"
                >
                    <Loader2 class="size-4 animate-spin text-emerald-600" />
                    Leyendo el historial…
                </p>
                <div
                    v-else-if="historyError"
                    role="alert"
                    class="flex flex-wrap items-center gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-900 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-200"
                >
                    <span class="min-w-0 flex-1"
                        ><b>No se pudo cargar el historial.</b>
                        {{ historyError }}</span
                    >
                    <Button
                        size="sm"
                        variant="outline"
                        class="h-8 rounded-lg"
                        @click="loadHistory"
                    >
                        Reintentar
                    </Button>
                </div>
                <p
                    v-else-if="!history.length"
                    class="text-sm text-muted-foreground"
                >
                    Todavía no hay cambios registrados para este movimiento.
                </p>
                <ol
                    v-else
                    class="space-y-2 border-l-2 border-emerald-200 pl-4 text-sm dark:border-emerald-900"
                >
                    <li v-for="(h, i) in history" :key="i">
                        <b>{{ h.treatment_label }}</b>
                        <span
                            v-if="h.considered_amount"
                            class="ml-1 rounded-full bg-teal-100 px-2 text-xs font-semibold text-teal-800 dark:bg-teal-950/60 dark:text-teal-300"
                            >ya considerado {{ mxn(h.considered_amount) }} ·
                            {{ h.considered_reason_label }}</span
                        >
                        <span class="text-muted-foreground">
                            (antes: {{ h.previous_label ?? '—' }})</span
                        ><br />
                        <span class="text-xs text-muted-foreground"
                            >{{ h.user ?? '—' }} · {{ h.at }}</span
                        >
                        — {{ h.notes }}
                    </li>
                </ol>
            </ErDisclosure>
        </div>

        <!-- Pie fijo: acciones -->
        <template v-if="m" #footer>
            <p
                v-if="lockReason"
                class="flex min-w-0 flex-1 items-center gap-1.5 text-sm text-muted-foreground"
                :title="lockReason"
            >
                <Lock class="size-4 shrink-0" />
                <span class="truncate">{{ lockReason }}</span>
            </p>
            <p
                v-else-if="
                    !can.accept && !can.classify && !can.rule && !can.consider
                "
                class="flex min-w-0 flex-1 items-center gap-1.5 text-sm text-muted-foreground"
            >
                <Info class="size-4 shrink-0" /> Solo consulta: no tienes
                permiso para clasificar.
            </p>
            <span v-else class="flex-1" />
            <Button
                v-if="can.rule"
                variant="outline"
                class="h-10 rounded-xl"
                title="Crear regla de catálogo con este texto"
                @click="emit('createRule', m)"
            >
                <BookMarked class="size-4" /> Crear regla
            </Button>
            <Button
                v-if="can.revert"
                variant="ghost"
                class="h-10 rounded-xl"
                title="Volver a la regla automática (quita también un «Ya considerado» parcial)"
                @click="emit('revert', m.fingerprint)"
            >
                <Undo2 class="size-4" /> Deshacer
            </Button>
            <Button
                v-if="can.consider"
                variant="outline"
                class="h-10 rounded-xl"
                title="Ya está tomado en cuenta (Radiografía, otro periodo u otra fuente): todo o una parte"
                @click="emit('consider', [m.fingerprint])"
            >
                <CheckCircle2 class="size-4 text-teal-600" /> Ya considerado
            </Button>
            <Button
                v-if="can.accept"
                variant="outline"
                class="h-10 rounded-xl"
                @click="emit('acceptSuggestion', m.fingerprint)"
            >
                <Sparkles class="size-4 text-amber-500" /> Aceptar sugerencia
            </Button>
            <Button
                v-if="can.classify"
                class="h-10 rounded-xl bg-emerald-700 text-white hover:bg-emerald-800"
                @click="emit('classify', [m.fingerprint])"
            >
                <Tags class="size-4" /> Clasificar / corregir
            </Button>
        </template>
    </ErModal>
</template>
