<script setup lang="ts">
import {
    CalendarClock,
    FileInput,
    Filter,
    GitCompareArrows,
    Landmark,
    ListChecks,
    Scale,
    Tag,
    UserCheck,
} from 'lucide-vue-next';
import { computed } from 'vue';
import type { MovementDates, MovementDimensions } from './types';

const props = defineProps<{
    dimensions: MovementDimensions;
    dates?: MovementDates | null;
}>();

type Tone = 'ok' | 'warn' | 'muted' | 'info' | 'bad';

const TONE: Record<Tone, string> = {
    ok: 'border-emerald-200 bg-emerald-50/60 dark:border-emerald-900 dark:bg-emerald-950/20',
    warn: 'border-amber-300 bg-amber-50/70 dark:border-amber-800 dark:bg-amber-950/25',
    bad: 'border-rose-300 bg-rose-50/70 dark:border-rose-900 dark:bg-rose-950/25',
    info: 'border-cyan-200 bg-cyan-50/60 dark:border-cyan-900 dark:bg-cyan-950/20',
    muted: 'border-border bg-muted/30',
};

/** Tono por clave (sólo color; las etiquetas vienen del backend). */
function toneOf(dim: string, key: string | null): Tone {
    const map: Record<string, Record<string, Tone>> = {
        import: {
            importado: 'ok',
            control_no_cuadra: 'bad',
            sin_control: 'warn',
            erp: 'info',
        },
        reconciliation: {
            conciliado: 'ok',
            conciliado_parcial: 'warn',
            pendiente: 'warn',
            ambiguo: 'warn',
            duplicado: 'bad',
            sin_procesar: 'warn',
        },
        review: { pendiente: 'warn', revisado: 'info' },
        inclusion: {
            incluido: 'ok',
            incluido_parcial: 'ok',
            pendiente: 'warn',
            fuera_periodo: 'info',
        },
    };

    return map[dim]?.[key ?? ''] ?? 'muted';
}

const cells = computed(() => {
    const d = props.dimensions;

    return [
        {
            dim: 'inclusion',
            label: '¿Entra al reporte?',
            icon: Filter,
            v: d.inclusion,
        },
        {
            dim: 'reconciliation',
            label: 'Conciliación con ERP',
            icon: GitCompareArrows,
            v: d.reconciliation,
        },
        {
            dim: 'category',
            label: 'Categoría financiera',
            icon: Tag,
            v: d.category,
        },
        {
            dim: 'accounting',
            label: 'Tratamiento contable',
            icon: Scale,
            v: d.accounting,
        },
        {
            dim: 'source',
            label: 'Fuente que lo reconoce',
            icon: Landmark,
            v: d.source,
        },
        {
            dim: 'review',
            label: 'Revisión humana',
            icon: UserCheck,
            v: d.review,
        },
        {
            dim: 'import',
            label: 'Cómo entró el dato',
            icon: FileInput,
            v: d.import,
        },
        {
            dim: 'recognition',
            label: 'Periodo en que se reconoce',
            icon: CalendarClock,
            v: d.recognition,
        },
    ];
});

const DATE_LABELS: Array<[keyof MovementDates, string]> = [
    ['operation', 'Operación'],
    ['bank', 'Banco'],
    ['erp_capture', 'Captura ERP'],
    ['erp_payment', 'Pago ERP'],
    ['accrual', 'Devengo'],
];
const dateList = computed(() =>
    props.dates
        ? DATE_LABELS.filter(([k]) => props.dates?.[k]).map(([k, l]) => ({
              label: l,
              value: props.dates?.[k] as string,
          }))
        : [],
);
</script>

<template>
    <div>
        <p class="flex items-center gap-1.5 text-sm font-extrabold">
            <ListChecks class="size-4" /> Cómo se trató, dimensión por dimensión
        </p>
        <p class="mt-0.5 text-xs text-muted-foreground">
            Cada cuadro responde una pregunta distinta: conciliar no es lo mismo
            que clasificar ni que incluirlo en el reporte.
        </p>
        <dl class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
            <div
                v-for="c in cells"
                :key="c.dim"
                class="rounded-xl border p-2.5"
                :class="TONE[toneOf(c.dim, c.v.key)]"
            >
                <dt
                    class="flex items-center gap-1.5 text-[11px] text-muted-foreground"
                >
                    <component :is="c.icon" class="size-3.5" /> {{ c.label }}
                </dt>
                <dd class="mt-0.5 text-xs leading-snug font-semibold">
                    {{ c.v.label }}
                </dd>
                <dd
                    v-if="c.dim === 'recognition'"
                    class="mt-0.5 text-[11px] text-muted-foreground"
                >
                    {{ dimensions.recognition.period_code ?? '—' }} ·
                    {{ dimensions.recognition.basis_label }}
                    <span
                        v-if="dimensions.recognition.out_of_period_label"
                        class="block font-semibold text-violet-700 dark:text-violet-300"
                        >{{ dimensions.recognition.out_of_period_label }}</span
                    >
                </dd>
            </div>
        </dl>
        <div v-if="dateList.length" class="mt-3 flex flex-wrap gap-1.5">
            <span
                v-for="dt in dateList"
                :key="dt.label"
                class="rounded-lg bg-muted px-2 py-1 text-[11px]"
            >
                <span class="text-muted-foreground">{{ dt.label }}</span>
                <b class="ml-1 tabular-nums">{{ dt.value }}</b>
            </span>
        </div>
    </div>
</template>
