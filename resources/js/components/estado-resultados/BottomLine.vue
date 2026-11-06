<script setup lang="ts">
import { CircleHelp } from 'lucide-vue-next';
import { computed } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCountUp } from '@/composables/estado-resultados/useCountUp';
import type { CompRow } from './comparativas';
import { CONCEPT_HELP } from './conceptHelp';
import DeltaChip from './DeltaChip.vue';
import { isNegative, mxn, num } from './types';
import type { Dataset } from './types';

/**
 * Del EBITDA a la utilidad neta, como un renglón de libro: cada línea dice qué se
 * resta o se suma y el resultado final queda abajo. Un importe sin información
 * (null) se presenta como «Pendiente», nunca como $0.
 */
const props = defineProps<{
    dataset: Dataset;
    active: string | null;
    rows: Map<string, CompRow>;
    comparable: boolean;
    loading?: boolean;
    against?: string | null;
}>();

const emit = defineEmits<{ select: [key: string] }>();

const r = computed(() => props.dataset.results);
const neta = computed(() => r.value.utilidad_neta ?? null);
const shown = useCountUp(() => num(neta.value));
const missing = computed(
    () => props.dataset.completeness.utilidad_neta?.missing ?? [],
);
const ebitMissing = computed(
    () => props.dataset.completeness.ebit?.missing ?? [],
);
const ebtMissing = computed(() =>
    (props.dataset.completeness.ebt?.missing ?? []).filter(
        (m) => !ebitMissing.value.includes(m),
    ),
);

/** Motivo corto de «Pendiente»: lo que falta, empezando por el ISR si es el caso. */
const pendingWhy = computed(() => {
    const m = missing.value;

    if (r.value.isr === null || r.value.isr === undefined) {
        return 'Falta capturar el ISR del periodo.';
    }

    return m.length
        ? `Falta: ${m[0]}${m.length > 1 ? ` y ${m.length - 1} más` : ''}.`
        : 'Falta información.';
});

const lines = computed(() => [
    {
        key: 'ebitda',
        sign: '',
        label: 'EBITDA',
        // Versiones con el modelo anterior guardan el EBITDA como ebitda_final.
        value: r.value.ebitda ?? r.value.ebitda_final ?? null,
        total: true,
        gap: [] as string[],
    },
    {
        key: 'depreciacion_amortizacion',
        sign: '−',
        label: 'Depreciación y amortización',
        value: r.value.depreciacion_amortizacion ?? null,
        total: false,
        gap: ebitMissing.value,
    },
    {
        key: 'ebit',
        sign: '=',
        label: 'EBIT',
        value: r.value.ebit ?? null,
        total: true,
        gap: [],
    },
    {
        key: 'resultado_financiero_neto',
        sign: '±',
        label: 'Resultado financiero neto',
        value: r.value.resultado_financiero_neto ?? null,
        total: false,
        gap: ebtMissing.value,
    },
    {
        key: 'ebt',
        sign: '=',
        label: 'Antes de impuestos (EBT)',
        value: r.value.ebt ?? null,
        total: true,
        gap: [],
    },
    {
        key: 'isr',
        sign: '−',
        label: 'ISR',
        value: r.value.isr ?? null,
        total: false,
        gap: [],
    },
]);

const netaRow = computed(() => props.rows.get('utilidad_neta') ?? null);
</script>

<template>
    <div class="flex h-full flex-col gap-4">
        <div>
            <div class="flex items-center gap-1.5">
                <h3 class="text-sm font-bold">Utilidad neta</h3>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            class="rounded-full text-muted-foreground transition hover:text-foreground focus-visible:ring-3 focus-visible:ring-cyan-500/40 focus-visible:outline-none"
                            aria-label="Qué es la utilidad neta"
                        >
                            <CircleHelp class="size-3.5" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-xs">{{
                        CONCEPT_HELP.utilidad_neta
                    }}</TooltipContent>
                </Tooltip>
            </div>
            <button
                type="button"
                class="mt-1 block rounded-lg text-left transition focus-visible:ring-3 focus-visible:ring-cyan-500/40 focus-visible:outline-none"
                :aria-pressed="active === 'utilidad_neta'"
                :aria-label="`Utilidad neta: ${neta === null ? 'pendiente. ' + pendingWhy : mxn(neta)}`"
                @click="emit('select', 'utilidad_neta')"
            >
                <span
                    v-if="neta !== null"
                    class="block text-4xl leading-none font-extrabold tracking-tight tabular-nums"
                    :class="
                        isNegative(neta)
                            ? 'text-rose-600 dark:text-rose-400'
                            : 'text-emerald-800 dark:text-emerald-300'
                    "
                    >{{ mxn(shown) }}</span
                >
                <span
                    v-else
                    class="inline-flex items-center gap-2 rounded-xl border-2 border-dashed border-amber-400 bg-amber-50/70 px-3 py-1.5 text-2xl leading-none font-extrabold text-amber-800 dark:border-amber-600 dark:bg-amber-950/30 dark:text-amber-300"
                    >Pendiente</span
                >
            </button>
            <p
                v-if="neta === null"
                class="mt-2 text-sm text-amber-800 dark:text-amber-300"
            >
                {{ pendingWhy }} No se presenta como $0.
            </p>
            <p v-else class="mt-1.5">
                <DeltaChip
                    :row="netaRow"
                    :comparable="comparable"
                    :loading="loading"
                    :against="against"
                />
            </p>
        </div>

        <ol
            class="mt-auto divide-y divide-dashed divide-border rounded-xl border bg-card/80 text-sm"
            aria-label="Del EBITDA a la utilidad neta"
        >
            <li v-for="l in lines" :key="l.key">
                <button
                    type="button"
                    class="grid w-full grid-cols-[1rem_minmax(0,1fr)_auto] items-baseline gap-2 px-3 py-1.5 text-left transition hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:ring-3 focus-visible:ring-cyan-500/40 focus-visible:outline-none focus-visible:ring-inset"
                    :class="[
                        active === l.key
                            ? 'bg-cyan-50 dark:bg-cyan-950/30'
                            : '',
                        l.total ? 'font-bold' : '',
                    ]"
                    :aria-pressed="active === l.key"
                    @click="emit('select', l.key)"
                >
                    <span
                        class="text-center text-muted-foreground"
                        aria-hidden="true"
                        >{{ l.sign }}</span
                    >
                    <span class="min-w-0 truncate" :title="l.label">
                        {{ l.label }}
                        <span
                            v-if="l.gap.length"
                            class="ml-1 inline-block size-1.5 -translate-y-0.5 rounded-full bg-amber-500"
                            :title="`Sin información: ${l.gap.join(', ')}`"
                        />
                        <span v-if="l.gap.length" class="sr-only"
                            >(sin información: {{ l.gap.join(', ') }})</span
                        >
                    </span>
                    <span
                        class="text-right tabular-nums"
                        :class="
                            l.value === null
                                ? 'font-semibold text-amber-700 dark:text-amber-400'
                                : isNegative(l.value)
                                  ? 'text-rose-600 dark:text-rose-400'
                                  : ''
                        "
                        >{{
                            l.value === null ? 'Pendiente' : mxn(l.value)
                        }}</span
                    >
                </button>
            </li>
            <li>
                <button
                    type="button"
                    class="grid w-full grid-cols-[1rem_minmax(0,1fr)_auto] items-baseline gap-2 rounded-b-xl bg-muted/50 px-3 py-2 text-left font-extrabold transition hover:bg-muted focus-visible:ring-3 focus-visible:ring-cyan-500/40 focus-visible:outline-none focus-visible:ring-inset"
                    :aria-pressed="active === 'utilidad_neta'"
                    @click="emit('select', 'utilidad_neta')"
                >
                    <span
                        class="text-center text-muted-foreground"
                        aria-hidden="true"
                        >=</span
                    >
                    <span>Utilidad neta</span>
                    <span
                        class="text-right tabular-nums"
                        :class="
                            neta === null
                                ? 'text-amber-700 dark:text-amber-400'
                                : isNegative(neta)
                                  ? 'text-rose-600 dark:text-rose-400'
                                  : 'text-emerald-800 dark:text-emerald-300'
                        "
                        >{{ neta === null ? 'Pendiente' : mxn(neta) }}</span
                    >
                </button>
            </li>
        </ol>
        <p
            v-if="ebitMissing.length || ebtMissing.length"
            class="-mt-2 flex items-center gap-1.5 text-xs text-muted-foreground"
        >
            <span
                class="size-1.5 rounded-full bg-amber-500"
                aria-hidden="true"
            />
            Línea sin información capturada: se registra en Ajustes.
        </p>
    </div>
</template>
