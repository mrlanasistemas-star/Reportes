<script setup lang="ts">
/**
 * HistoryTrend — evolución del colaborador en los periodos del mismo tipo
 * (GET /datos-duros/colaboradores/{id}/historial). Barras por periodo con la
 * métrica elegida por chip; el periodo abierto queda resaltado.
 */
import { LoaderCircle, TrendingDown, TrendingUp } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { moneyCompact, money, percent } from '@/lib/format';
import type { HardDataHistoryPoint } from '@/types/hardData';

const props = defineProps<{
    employeeId: number;
    periodId: number;
    periodType: string;
}>();

type MetricKey =
    | 'ebitda'
    | 'placement'
    | 'recovery'
    | 'portfolio'
    | 'mora_pct'
    | 'payroll';
const METRICS: { key: MetricKey; label: string; pct?: boolean }[] = [
    { key: 'ebitda', label: 'EBITDA' },
    { key: 'placement', label: 'Colocación' },
    { key: 'recovery', label: 'Recuperación' },
    { key: 'portfolio', label: 'Cartera' },
    { key: 'mora_pct', label: 'Mora', pct: true },
    { key: 'payroll', label: 'Nómina' },
];

const cache = new Map<string, HardDataHistoryPoint[]>();
const points = ref<HardDataHistoryPoint[]>([]);
const loading = ref(false);
const error = ref<string | null>(null);
const metric = ref<MetricKey>('ebitda');
let ctrl: AbortController | null = null;

async function load(): Promise<void> {
    const key = `${props.employeeId}:${props.periodType}`;
    ctrl?.abort();
    error.value = null;

    if (cache.has(key)) {
        points.value = cache.get(key)!;

        return;
    }

    ctrl = new AbortController();
    loading.value = true;

    try {
        const resp = await fetch(
            `/datos-duros/colaboradores/${props.employeeId}/historial?tipo=${encodeURIComponent(props.periodType)}`,
            {
                signal: ctrl.signal,
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            },
        );

        if (!resp.ok) {
            throw new Error(`El servidor respondió ${resp.status}.`);
        }

        const json = await resp.json();
        cache.set(key, json.history ?? []);
        points.value = json.history ?? [];
    } catch (e: unknown) {
        if ((e as { name?: string })?.name !== 'AbortError') {
            error.value =
                'No se pudo cargar la evolución. Intenta de nuevo más tarde.';
        }
    } finally {
        loading.value = false;
    }
}

watch(() => [props.employeeId, props.periodType], load, { immediate: true });

const def = computed(() => METRICS.find((m) => m.key === metric.value)!);
const values = computed(() =>
    points.value.map((p) => (p.present ? Number(p[metric.value] ?? 0) : null)),
);
const maxAbs = computed(() =>
    Math.max(1, ...values.value.map((v) => Math.abs(v ?? 0))),
);

const delta = computed(() => {
    const idx = points.value.findIndex((p) => p.period.id === props.periodId);
    const cur = values.value[idx];
    const prev = idx > 0 ? values.value[idx - 1] : null;

    if (
        cur === null ||
        cur === undefined ||
        prev === null ||
        prev === undefined
    ) {
        return null;
    }

    if (def.value.pct) {
        return { diff: cur - prev, pct: null as number | null };
    }

    return {
        diff: cur - prev,
        pct: prev !== 0 ? ((cur - prev) / Math.abs(prev)) * 100 : null,
    };
});

function fmt(v: number | null): string {
    if (v === null) {
        return '—';
    }

    return def.value.pct ? percent(v) : moneyCompact(v);
}

/** Para la mora, subir es malo. */
const deltaGood = computed(() =>
    delta.value
        ? metric.value === 'mora_pct'
            ? delta.value.diff <= 0
            : delta.value.diff >= 0
        : true,
);
</script>

<template>
    <div>
        <div
            class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        >
            <button
                v-for="m in METRICS"
                :key="m.key"
                type="button"
                :aria-pressed="metric === m.key"
                :class="[
                    'shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold transition',
                    metric === m.key
                        ? 'bg-primary text-primary-foreground'
                        : 'bg-muted text-muted-foreground hover:text-foreground',
                ]"
                @click="metric = m.key"
            >
                {{ m.label }}
            </button>
        </div>

        <div
            v-if="loading"
            class="flex h-40 items-center justify-center gap-2 text-sm text-muted-foreground"
        >
            <LoaderCircle class="size-4 animate-spin" /> Calculando evolución…
        </div>
        <p
            v-else-if="error"
            class="rounded-xl bg-danger-soft px-3 py-3 text-xs text-danger"
        >
            {{ error }}
        </p>
        <p
            v-else-if="points.length < 2"
            class="rounded-xl border border-dashed border-border px-3 py-4 text-center text-xs text-muted-foreground"
        >
            Aún no hay suficientes periodos de este tipo para comparar.
        </p>
        <template v-else>
            <p
                v-if="delta"
                :class="[
                    'mt-2 inline-flex items-center gap-1 text-xs font-semibold',
                    deltaGood ? 'text-success' : 'text-danger',
                ]"
            >
                <TrendingUp v-if="delta.diff >= 0" class="size-3.5" />
                <TrendingDown v-else class="size-3.5" />
                {{ delta.diff >= 0 ? '+' : ''
                }}{{ def.pct ? percent(delta.diff) : moneyCompact(delta.diff) }}
                <template v-if="delta.pct !== null">
                    ({{ delta.pct >= 0 ? '+' : ''
                    }}{{ delta.pct.toFixed(1) }} %)</template
                >
                vs periodo anterior
            </p>

            <div
                class="mt-3 flex h-40 items-stretch gap-2"
                role="img"
                :aria-label="`Evolución de ${def.label}`"
            >
                <div
                    v-for="(p, i) in points"
                    :key="p.period.id"
                    class="flex min-w-0 flex-1 flex-col items-center gap-1"
                >
                    <span
                        class="text-[10px] font-semibold text-foreground tabular-nums"
                        :title="
                            values[i] === null
                                ? ''
                                : def.pct
                                  ? percent(values[i])
                                  : money(values[i])
                        "
                        >{{ fmt(values[i]) }}</span
                    >
                    <div
                        class="relative flex w-full flex-1 flex-col justify-end"
                    >
                        <div
                            v-if="values[i] !== null"
                            :class="[
                                'mx-auto w-full max-w-10 rounded-lg transition-all duration-500',
                                p.period.id === periodId
                                    ? 'bg-primary'
                                    : 'bg-primary/30',
                                (values[i] ?? 0) < 0 && '!bg-danger/70',
                            ]"
                            :style="{
                                height: `${Math.max(3, (Math.abs(values[i] ?? 0) / maxAbs) * 100)}%`,
                            }"
                        />
                        <div
                            v-else
                            class="mx-auto h-1 w-full max-w-10 rounded bg-muted"
                            title="Sin datos en este periodo"
                        />
                    </div>
                    <span
                        :class="[
                            'w-full truncate text-center text-[10px]',
                            p.period.id === periodId
                                ? 'font-bold text-primary'
                                : 'text-muted-foreground',
                        ]"
                        >{{ p.period.name.replace(/\s\d{4}$/, '') }}</span
                    >
                </div>
            </div>
        </template>
    </div>
</template>
