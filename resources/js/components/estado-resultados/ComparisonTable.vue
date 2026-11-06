<script setup lang="ts">
import {
    AlertTriangle,
    ChevronDown,
    ExternalLink,
    Info,
    Lock,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { signed } from './comparativas';
import type { CompPair, CompRow } from './comparativas';
import { cargaUrl, fuentesUrl } from './sourceLinks';
import { isNegative, mxn, pct } from './types';

const props = defineProps<{
    pair: CompPair;
    /** Etiqueta del periodo actual (p. ej. "Acumulado 2026 · parcial 4 de 12"). */
    currentLabel?: string;
    comparisonLabel?: string;
}>();

const showCats = ref(false);

/** Respuestas anteriores sin `level`: no comparable ⇒ se trata como «con reservas». */
const level = computed(
    () =>
        props.pair.comparability.level ??
        (props.pair.comparability.comparable ? 'comparable' : 'con_reservas'),
);
const missing = computed(() => props.pair.comparability.missing ?? []);
/** La cobertura distinta ya se explica con la lista exacta de lo que falta. */
const otherReasons = computed(() =>
    props.pair.comparability.reasons.filter(
        (r) => !(missing.value.length && r.code === 'cobertura_distinta'),
    ),
);

/** "Sin datos" (no hay periodo) y "Pendiente" (concepto sin información) son distintos de $0. */
function empty(r: CompRow, side: 'current' | 'previous'): string | null {
    const v = side === 'current' ? r.current : r.previous;

    if (v !== null) {
        return null;
    }

    const code = r.reason?.code ?? '';
    const ref = side === 'current' ? props.pair.current : props.pair.comparison;

    if (!ref.available) {
        return 'Sin datos';
    }

    if (r.unit === '%') {
        return 'Sin margen';
    }

    return code.startsWith('pendiente') || code === 'incompleto'
        ? 'Pendiente'
        : 'Pendiente';
}

function fmt(v: string | null, unit: 'MXN' | '%'): string {
    return v === null ? '' : unit === '%' ? pct(v) : mxn(v);
}

const ASSESS: Record<string, string> = {
    favorable:
        'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
    desfavorable:
        'bg-rose-100 text-rose-800 dark:bg-rose-950/60 dark:text-rose-300',
    neutral: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <section class="rounded-2xl border bg-card shadow-soft-xs">
        <header
            class="flex flex-wrap items-start justify-between gap-3 border-b px-5 py-4"
        >
            <div class="min-w-0">
                <h3 class="text-base font-extrabold">{{ pair.label }}</h3>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    <b class="text-foreground">{{
                        currentLabel ?? pair.current.period.label
                    }}</b>
                    ({{ pair.current.status_label
                    }}<template v-if="pair.current.source_label">
                        · {{ pair.current.source_label }}</template
                    >) contra
                    <b class="text-foreground">{{
                        comparisonLabel ?? pair.comparison.period.label
                    }}</b>
                    ({{ pair.comparison.status_label
                    }}<template v-if="pair.comparison.source_label">
                        · {{ pair.comparison.source_label }}</template
                    >)
                </p>
            </div>
        </header>

        <!-- Comparabilidad: se muestran cifras y variaciones; «con reservas» dice exactamente qué falta -->
        <div
            v-if="!pair.comparability.comparable"
            class="m-4 flex gap-2.5 rounded-xl border p-3 text-sm"
            :class="
                level === 'no_comparable'
                    ? 'border-border bg-muted/40 text-foreground'
                    : 'border-amber-300 bg-amber-50 text-amber-950 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100'
            "
            role="status"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            <div class="min-w-0 flex-1">
                <p class="font-bold">
                    {{
                        level === 'no_comparable'
                            ? 'No hay contra qué comparar'
                            : 'Comparación con reservas: las cifras y variaciones se muestran, pero marcadas'
                    }}
                </p>
                <p
                    v-if="pair.comparability.headline && !missing.length"
                    class="mt-0.5 text-xs"
                >
                    {{ pair.comparability.headline }}
                </p>
                <div v-if="missing.length" class="mt-1.5 space-y-1.5">
                    <p class="text-xs">
                        Para comparar
                        <b>{{ pair.current.period.label }}</b> contra
                        <b>{{ pair.comparison.period.label }}</b> falta:
                    </p>
                    <ul class="space-y-1.5">
                        <li
                            v-for="m in missing"
                            :key="`${m.side}-${m.source}-${m.source_label}`"
                            class="flex flex-wrap items-center gap-2 text-xs"
                        >
                            <span class="font-semibold"
                                >{{ m.source_label }} de
                                {{ m.period_label }}</span
                            >
                            <span class="text-muted-foreground"
                                >→ {{ m.where_label }}</span
                            >
                            <a
                                :href="
                                    m.where === 'carga'
                                        ? cargaUrl(m.period_id)
                                        : fuentesUrl(m.period_id)
                                "
                                class="inline-flex items-center gap-1 rounded-lg bg-amber-600 px-2 py-1 text-[11px] font-bold text-white transition hover:bg-amber-700 focus-visible:ring-2 focus-visible:ring-amber-500/50 focus-visible:outline-none"
                            >
                                {{
                                    m.where === 'carga'
                                        ? 'Ir a Carga de archivos'
                                        : 'Subirlo en Fuentes'
                                }}
                                <ExternalLink class="size-3" />
                            </a>
                        </li>
                    </ul>
                </div>
                <ul
                    v-if="otherReasons.length"
                    class="mt-1.5 space-y-0.5 text-xs"
                >
                    <li v-for="r in otherReasons" :key="r.code">
                        • {{ r.message }}
                    </li>
                </ul>
                <p
                    v-if="level === 'con_reservas'"
                    class="mt-1.5 text-[11px] opacity-80"
                >
                    Los conceptos que dependen de la fuente faltante llevan la
                    marca «con reservas»: su variación puede deberse a esa
                    fuente y no a la operación.
                </p>
            </div>
        </div>
        <ul
            v-if="pair.comparability.warnings.length"
            class="mx-4 mt-3 space-y-1 text-xs text-muted-foreground"
        >
            <li
                v-for="w in pair.comparability.warnings"
                :key="w.code"
                class="flex gap-1.5"
            >
                <Info class="mt-0.5 size-3.5 shrink-0" /> {{ w.message }}
            </li>
        </ul>

        <div class="overflow-x-auto p-2">
            <table
                class="w-full min-w-[760px] border-separate border-spacing-0 text-sm"
            >
                <thead class="text-left text-xs text-muted-foreground">
                    <tr
                        class="[&>th]:border-b [&>th]:px-3 [&>th]:py-2 [&>th]:font-semibold"
                    >
                        <th>Concepto</th>
                        <th class="text-right">Actual</th>
                        <th class="text-right">Comparación</th>
                        <th class="text-right">Variación</th>
                        <th class="text-right">Var. %</th>
                        <th>Lectura</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="r in pair.rows"
                        :key="r.key"
                        class="[&>td]:border-b [&>td]:border-border/60 [&>td]:px-3 [&>td]:py-2"
                        :class="[
                            ['ebitda', 'utilidad_neta'].includes(r.key)
                                ? 'bg-emerald-50/50 font-bold dark:bg-emerald-950/20'
                                : '',
                            r.unit === '%'
                                ? 'text-[13px] text-muted-foreground'
                                : '',
                        ]"
                    >
                        <td :class="r.unit === '%' ? 'pl-6' : ''">
                            {{ r.label }}
                        </td>
                        <td class="text-right tabular-nums">
                            <span
                                v-if="empty(r, 'current')"
                                class="rounded border border-dashed border-amber-400 px-1.5 text-xs font-semibold text-amber-800 dark:text-amber-300"
                                >{{ empty(r, 'current') }}</span
                            >
                            <span
                                v-else
                                :class="
                                    isNegative(r.current)
                                        ? 'text-rose-600 dark:text-rose-400'
                                        : ''
                                "
                                >{{ fmt(r.current, r.unit) }}</span
                            >
                        </td>
                        <td class="text-right tabular-nums">
                            <span
                                v-if="empty(r, 'previous')"
                                class="rounded border border-dashed px-1.5 text-xs font-semibold text-muted-foreground"
                                >{{ empty(r, 'previous') }}</span
                            >
                            <span
                                v-else
                                :class="
                                    isNegative(r.previous)
                                        ? 'text-rose-600 dark:text-rose-400'
                                        : ''
                                "
                                >{{ fmt(r.previous, r.unit) }}</span
                            >
                        </td>
                        <td
                            class="text-right tabular-nums"
                            :class="
                                r.reservation || level === 'no_comparable'
                                    ? 'text-muted-foreground'
                                    : ''
                            "
                        >
                            {{ signed(r.variation, r.variation_unit) }}
                        </td>
                        <td class="text-right tabular-nums">
                            {{
                                r.variation_pct === null
                                    ? '—'
                                    : signed(r.variation_pct, '%')
                            }}
                        </td>
                        <td>
                            <Tooltip v-if="r.assessment && r.reservation">
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        class="cursor-help rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-amber-400 focus-visible:ring-2 focus-visible:ring-amber-500 focus-visible:outline-none"
                                        :class="ASSESS[r.assessment]"
                                        :aria-label="`${r.assessment_label}: ${r.reservation.message}`"
                                    >
                                        {{ r.assessment_label }}
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent class="max-w-xs">{{
                                    r.reservation.message
                                }}</TooltipContent>
                            </Tooltip>
                            <span
                                v-else-if="r.assessment"
                                class="rounded-full px-2 py-0.5 text-[11px] font-bold"
                                :class="ASSESS[r.assessment]"
                                >{{ r.assessment_label }}</span
                            >
                            <Tooltip v-else-if="r.reason">
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        class="cursor-help rounded text-xs text-muted-foreground underline decoration-dotted focus-visible:ring-2 focus-visible:ring-emerald-500/40 focus-visible:outline-none"
                                        :aria-label="`Sin lectura: ${r.reason.message}`"
                                    >
                                        Sin lectura
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent class="max-w-xs">{{
                                    r.reason.message
                                }}</TooltipContent>
                            </Tooltip>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="pair.opex_categories.length" class="border-t">
            <button
                type="button"
                class="flex w-full items-center gap-2 px-5 py-3 text-left text-sm font-bold transition hover:bg-muted/40"
                :aria-expanded="showCats"
                @click="showCats = !showCats"
            >
                OPEX por categoría
                <span class="text-xs font-medium text-muted-foreground"
                    >({{ pair.opex_categories.length }})</span
                >
                <ChevronDown
                    class="ml-auto size-4 text-muted-foreground transition-transform"
                    :class="showCats ? 'rotate-180' : ''"
                />
            </button>
            <div v-show="showCats" class="overflow-x-auto px-2 pb-3">
                <table class="w-full min-w-[640px] text-sm">
                    <tbody>
                        <tr
                            v-for="c in pair.opex_categories"
                            :key="c.key"
                            class="border-t"
                        >
                            <td class="px-3 py-2">
                                {{ c.label }}
                                <span
                                    v-if="c.centralized"
                                    class="ml-1 rounded-full bg-slate-200 px-1.5 text-[10px] font-bold text-slate-700 dark:bg-slate-700 dark:text-slate-200"
                                    >Centralizado</span
                                >
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{
                                    c.current === null
                                        ? 'Sin datos'
                                        : mxn(c.current)
                                }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{
                                    c.previous === null
                                        ? 'Sin datos'
                                        : mxn(c.previous)
                                }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ signed(c.variation, 'MXN') }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{
                                    c.variation_pct === null
                                        ? '—'
                                        : signed(c.variation_pct, '%')
                                }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        <p
            class="flex items-center gap-1.5 px-5 pb-4 text-[11px] text-muted-foreground"
        >
            <Lock class="size-3" /> Los márgenes varían en puntos porcentuales
            (pp). Un gasto que sube es desfavorable; un resultado que sube es
            favorable.
        </p>
    </section>
</template>
