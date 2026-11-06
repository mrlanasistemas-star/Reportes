<script setup lang="ts">
import { ArrowDownUp, Building2, Info, Lock } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import type { CompBranches } from './comparativas';
import { isNegative, mxn, num, pct } from './types';

const props = defineProps<{ branches: CompBranches }>();

const sortKey = ref(props.branches.default_sort ?? 'ebitda');
const dir = ref<'desc' | 'asc'>('desc');

const COLS = [
    { key: 'ingresos_operativos', label: 'Ingresos', unit: 'MXN' },
    { key: 'opex_total', label: 'OPEX directo', unit: 'MXN' },
    { key: 'ebitda', label: 'EBITDA sucursal', unit: 'MXN' },
    { key: 'margen_ebitda', label: 'Margen', unit: '%' },
] as const;

type Key = (typeof COLS)[number]['key'];

const val = (r: CompBranches['rows'][number], k: string) =>
    r[k as Key] as string | null;

/** Orden por la métrica elegida; las sucursales sin dato van al final. */
const rows = computed(() =>
    [...props.branches.rows].sort((a, b) => {
        const va = val(a, sortKey.value);
        const vb = val(b, sortKey.value);

        if (va === null) {
            return 1;
        }

        if (vb === null) {
            return -1;
        }

        return dir.value === 'desc' ? num(vb) - num(va) : num(va) - num(vb);
    }),
);

/** Barras divergentes desde 0: escala con el mayor valor absoluto (sólo visual). */
const scale = computed(() =>
    Math.max(
        1,
        ...props.branches.rows.map((r) => Math.abs(num(val(r, sortKey.value)))),
    ),
);
const hasNegative = computed(() =>
    props.branches.rows.some((r) => num(val(r, sortKey.value)) < 0),
);
const unitOf = computed(
    () => COLS.find((c) => c.key === sortKey.value)?.unit ?? 'MXN',
);

function fmt(v: string | null, unit: string): string {
    return v === null ? 'Sin datos' : unit === '%' ? pct(v) : mxn(v);
}
</script>

<template>
    <div class="space-y-4">
        <section class="rounded-2xl border bg-card shadow-soft-xs">
            <header
                class="flex flex-wrap items-end justify-between gap-3 border-b px-5 py-4"
            >
                <div>
                    <h3 class="text-base font-extrabold">
                        {{ branches.label }}
                    </h3>
                    <p class="mt-0.5 max-w-3xl text-xs text-muted-foreground">
                        {{ branches.note }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <label
                        class="text-xs font-semibold text-muted-foreground"
                        for="er-branch-sort"
                        >Ordenar por</label
                    >
                    <select
                        id="er-branch-sort"
                        v-model="sortKey"
                        class="h-9 rounded-xl border bg-background px-3 text-sm font-semibold outline-none focus:border-emerald-500 focus:ring-3 focus:ring-emerald-500/20"
                    >
                        <option
                            v-for="o in branches.sort_options"
                            :key="o.key"
                            :value="o.key"
                        >
                            {{ o.label }}
                        </option>
                    </select>
                    <button
                        type="button"
                        class="rounded-lg border p-2 transition hover:bg-muted"
                        :aria-label="
                            dir === 'desc'
                                ? 'Orden de mayor a menor'
                                : 'Orden de menor a mayor'
                        "
                        :title="
                            dir === 'desc'
                                ? 'De mayor a menor'
                                : 'De menor a mayor'
                        "
                        @click="dir = dir === 'desc' ? 'asc' : 'desc'"
                    >
                        <ArrowDownUp class="size-4" />
                    </button>
                </div>
            </header>
            <p
                v-if="branches.restricted"
                class="mx-5 mt-3 flex items-center gap-1.5 text-xs text-muted-foreground"
            >
                <Lock class="size-3.5" /> Sólo ves tus sucursales asignadas.
            </p>

            <div class="overflow-x-auto p-2">
                <div class="min-w-[820px]">
                    <div
                        class="grid grid-cols-[minmax(150px,1.1fr)_minmax(220px,2fr)_repeat(4,minmax(110px,1fr))] gap-x-3 border-b px-3 py-2 text-xs font-semibold text-muted-foreground"
                    >
                        <span>Sucursal</span>
                        <span>{{
                            branches.sort_options.find((o) => o.key === sortKey)
                                ?.label
                        }}</span>
                        <span
                            v-for="c in COLS"
                            :key="c.key"
                            class="text-right"
                            >{{ c.label }}</span
                        >
                    </div>
                    <div
                        v-for="r in rows"
                        :key="r.key"
                        class="grid grid-cols-[minmax(150px,1.1fr)_minmax(220px,2fr)_repeat(4,minmax(110px,1fr))] items-center gap-x-3 border-b border-border/60 px-3 py-2 text-sm transition-colors hover:bg-muted/40"
                    >
                        <span class="truncate font-semibold" :title="r.label">{{
                            r.label.replace(/^Sucursal /, '')
                        }}</span>
                        <span
                            class="relative block h-4"
                            :aria-label="fmt(val(r, sortKey), unitOf)"
                        >
                            <span
                                v-if="hasNegative"
                                class="absolute inset-y-0 left-1/2 w-px bg-slate-400"
                                aria-hidden="true"
                            />
                            <span
                                v-if="val(r, sortKey) !== null"
                                class="absolute inset-y-0.5 rounded-sm transition-[width] duration-500"
                                :class="
                                    num(val(r, sortKey)) < 0
                                        ? 'bg-rose-500'
                                        : 'bg-emerald-600 dark:bg-emerald-500'
                                "
                                :style="
                                    hasNegative
                                        ? num(val(r, sortKey)) < 0
                                            ? {
                                                  right: '50%',
                                                  width: `${(Math.abs(num(val(r, sortKey))) / scale) * 50}%`,
                                              }
                                            : {
                                                  left: '50%',
                                                  width: `${(num(val(r, sortKey)) / scale) * 50}%`,
                                              }
                                        : {
                                              left: 0,
                                              width: `${(num(val(r, sortKey)) / scale) * 100}%`,
                                          }
                                "
                            />
                            <span
                                v-else
                                class="text-xs text-muted-foreground italic"
                                >Sin datos</span
                            >
                        </span>
                        <span
                            v-for="c in COLS"
                            :key="c.key"
                            class="text-right tabular-nums"
                            :class="
                                val(r, c.key) === null
                                    ? 'text-xs text-muted-foreground italic'
                                    : isNegative(val(r, c.key))
                                      ? 'text-rose-600 dark:text-rose-400'
                                      : ''
                            "
                            >{{ fmt(val(r, c.key), c.unit) }}</span
                        >
                    </div>
                    <div
                        v-for="t in [
                            branches.totals.sucursales,
                            branches.totals.consolidado,
                        ].filter(Boolean)"
                        :key="t!.label"
                        class="grid grid-cols-[minmax(150px,1.1fr)_minmax(220px,2fr)_repeat(4,minmax(110px,1fr))] items-center gap-x-3 bg-muted/50 px-3 py-2 text-sm font-bold"
                    >
                        <span>{{ t!.label }}</span>
                        <span />
                        <span
                            v-for="c in COLS"
                            :key="c.key"
                            class="text-right tabular-nums"
                            >{{ fmt(t![c.key], c.unit) }}</span
                        >
                    </div>
                </div>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <section
                v-if="branches.totals.centralizados"
                class="rounded-2xl border border-slate-300 bg-card p-5 shadow-soft-xs dark:border-slate-700"
            >
                <h3 class="flex items-center gap-2 text-base font-extrabold">
                    <Building2 class="size-4" /> Gastos centralizados
                </h3>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    {{ branches.totals.centralizados.label }}
                </p>
                <ul class="mt-3 space-y-1.5 text-sm">
                    <li
                        v-for="c in branches.totals.centralizados.categories"
                        :key="c.key"
                        class="flex justify-between gap-2"
                    >
                        <span class="flex items-center gap-1.5"
                            >{{ c.label }}
                            <Lock
                                v-if="c.restricted"
                                class="size-3 text-muted-foreground"
                        /></span>
                        <span class="tabular-nums">{{ mxn(c.amount) }}</span>
                    </li>
                    <li
                        class="flex justify-between gap-2 border-t pt-1.5 font-bold"
                    >
                        <span>Total centralizado</span>
                        <span class="tabular-nums">{{
                            mxn(branches.totals.centralizados.total)
                        }}</span>
                    </li>
                </ul>
            </section>
            <section
                v-if="branches.totals.conciliacion"
                class="rounded-2xl border bg-card p-5 shadow-soft-xs"
            >
                <h3 class="text-base font-extrabold">
                    De las sucursales al EBITDA consolidado
                </h3>
                <dl class="mt-3 space-y-1.5 text-sm tabular-nums">
                    <div class="flex justify-between gap-2">
                        <dt>Suma del EBITDA de sucursales</dt>
                        <dd>
                            {{
                                fmt(
                                    branches.totals.conciliacion
                                        .suma_ebitda_sucursales,
                                    'MXN',
                                )
                            }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>− Gastos centralizados</dt>
                        <dd>
                            {{
                                fmt(
                                    branches.totals.conciliacion
                                        .menos_centralizados,
                                    'MXN',
                                )
                            }}
                        </dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt>± No asignado a sucursal</dt>
                        <dd>
                            {{
                                fmt(
                                    branches.totals.conciliacion
                                        .no_asignado_a_sucursal,
                                    'MXN',
                                )
                            }}
                        </dd>
                    </div>
                    <div
                        class="flex justify-between gap-2 border-t pt-1.5 font-bold"
                    >
                        <dt>= EBITDA consolidado</dt>
                        <dd>
                            {{
                                fmt(
                                    branches.totals.conciliacion
                                        .ebitda_consolidado,
                                    'MXN',
                                )
                            }}
                        </dd>
                    </div>
                </dl>
                <p class="mt-2 flex gap-1.5 text-[11px] text-muted-foreground">
                    <Info class="mt-0.5 size-3.5 shrink-0" />
                    {{ branches.totals.conciliacion.note }}
                </p>
            </section>
        </div>
    </div>
</template>
