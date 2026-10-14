<script setup lang="ts">
import { AlertTriangle, ChevronDown } from 'lucide-vue-next';
import { computed } from 'vue';
import type { FilaEfectividad, Resultado } from './types';
import { fmtFecha, fmtMoney, fmtPct, semaforo } from './types';

const props = defineProps<{ resultado: Resultado; filas: FilaEfectividad[] }>();

const total = computed(() => props.filas.find((f) => f.tipo === 'gran_total') ?? null);

const kpis = computed(() => {
    const t = total.value;

    if (!t) {
        return [];
    }

    return [
        { l: 'Sano por cobrar', v: fmtMoney(t.sano_pc), c: '' },
        { l: 'Sano cobrado', v: fmtMoney(t.sano_cob), c: '' },
        { l: 'Atraso + vencido cobrado', v: fmtMoney(t.atraso_cob + t.vencido_cob), c: '' },
        { l: 'Cobranza total', v: fmtMoney(t.cobranza_total), c: '' },
        { l: '% Cobranza total', v: fmtPct(t.pct_total), c: semaforo(t) },
        { l: 'Mora', v: fmtMoney(t.mora), c: t.mora > 0 ? 'text-red-600 dark:text-red-400' : 'text-emerald-600 dark:text-emerald-400' },
    ];
});

/** Filas con encabezado de oficina intercalado (como la hoja EFECTIVIDAD). */
const renglones = computed(() => {
    const out: Array<{ k: string; oficina?: string; fila?: FilaEfectividad }> = [];
    let actual: string | null = null;
    props.filas.forEach((f, i) => {
        if (f.tipo === 'promotor' && f.oficina !== actual) {
            actual = f.oficina;
            out.push({ k: `o${i}`, oficina: f.oficina_titulo ?? '' });
        }

        out.push({ k: `f${i}`, fila: f });
    });

    return out;
});

const porCodigo = computed(() => {
    const m = new Map<string, number>();
    props.resultado.incidencias.forEach((i) => m.set(i.codigo, (m.get(i.codigo) ?? 0) + 1));

    return [...m.entries()];
});

const neg = (v: number) => (v < 0 ? 'text-red-600 dark:text-red-400' : '');
const p = computed(() => props.resultado.parametros);
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <p class="text-xs font-semibold tracking-widest text-primary uppercase">
                    {{ resultado.modo === 'DIARIO' ? 'Diario' : resultado.modo === 'SEMANAL' ? 'Semanal' : 'Mensual' }}
                </p>
                <h2 class="text-lg font-semibold">{{ resultado.titulo }}</h2>
                <p class="text-sm text-muted-foreground">
                    Ingresos del {{ fmtFecha(p.inicio_ingresos) }} al
                    {{ fmtFecha(p.fin_ingresos) }} · Corte
                    {{ fmtFecha(p.fecha_corte) }} · Domingo base
                    {{ fmtFecha(p.domingo_base) }}
                </p>
            </div>
            <p class="text-xs text-muted-foreground">{{ resultado.leyenda }}</p>
        </div>

        <div
            v-for="a in resultado.avisos"
            :key="a"
            class="flex gap-2 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            {{ a }}
        </div>

        <div v-if="kpis.length" class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
            <div v-for="k in kpis" :key="k.l" class="rounded-xl border bg-card p-3">
                <p class="text-xs text-muted-foreground">{{ k.l }}</p>
                <p class="mt-1 text-lg font-semibold tabular-nums" :class="k.c">{{ k.v }}</p>
            </div>
        </div>

        <p v-if="!filas.length" class="rounded-lg border p-6 text-center text-sm text-muted-foreground">
            No hay movimientos para este corte con los filtros elegidos.
        </p>

        <div v-else class="overflow-x-auto rounded-xl border">
            <table class="w-full min-w-[1100px] text-sm tabular-nums">
                <thead class="bg-slate-900 text-xs text-white dark:bg-slate-800">
                    <tr>
                        <th rowspan="2" class="sticky left-0 bg-slate-900 px-3 py-2 text-left font-semibold dark:bg-slate-800">Oficina / Promotor</th>
                        <th colspan="3" class="border-l border-slate-700 px-2 py-1.5 text-center font-semibold">Sano</th>
                        <th colspan="3" class="border-l border-slate-700 px-2 py-1.5 text-center font-semibold">Vigente con atraso</th>
                        <th colspan="3" class="border-l border-slate-700 px-2 py-1.5 text-center font-semibold">Vencido con atraso</th>
                        <th colspan="3" class="border-l border-slate-700 px-2 py-1.5 text-center font-semibold">Total</th>
                    </tr>
                    <tr class="text-right">
                        <template v-for="g in 3" :key="g">
                            <th class="border-l border-slate-700 px-2 py-1.5 font-medium">Por cobrar</th>
                            <th class="px-2 py-1.5 font-medium">Cobrado</th>
                            <th class="px-2 py-1.5 font-medium">%</th>
                        </template>
                        <th class="border-l border-slate-700 px-2 py-1.5 font-medium">Cobranza</th>
                        <th class="px-2 py-1.5 font-medium">%</th>
                        <th class="px-2 py-1.5 font-medium">Mora</th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="r in renglones" :key="r.k">
                        <tr v-if="r.oficina !== undefined" class="bg-muted/60">
                            <td colspan="13" class="sticky left-0 px-3 py-1.5 text-xs font-bold tracking-wide text-primary uppercase">
                                {{ r.oficina }}
                            </td>
                        </tr>
                        <tr
                            v-else-if="r.fila"
                            class="border-b text-right"
                            :class="{
                                'bg-slate-100 font-semibold dark:bg-slate-900': r.fila.tipo === 'subtotal',
                                'bg-primary font-bold text-primary-foreground': r.fila.tipo === 'gran_total',
                            }"
                        >
                            <td
                                class="sticky left-0 max-w-[16rem] truncate px-3 py-1.5 text-left"
                                :class="r.fila.tipo === 'gran_total' ? 'bg-primary' : r.fila.tipo === 'subtotal' ? 'bg-slate-100 dark:bg-slate-900' : 'bg-card'"
                                :title="r.fila.promotor"
                            >
                                {{ r.fila.promotor }}
                            </td>
                            <td class="px-2 py-1.5" :class="neg(r.fila.sano_pc)">{{ fmtMoney(r.fila.sano_pc) }}</td>
                            <td class="px-2 py-1.5" :class="neg(r.fila.sano_cob)">{{ fmtMoney(r.fila.sano_cob) }}</td>
                            <td class="px-2 py-1.5">{{ fmtPct(r.fila.sano_pct) }}</td>
                            <td class="border-l px-2 py-1.5" :class="neg(r.fila.atraso_pc)">{{ fmtMoney(r.fila.atraso_pc) }}</td>
                            <td class="px-2 py-1.5" :class="neg(r.fila.atraso_cob)">{{ fmtMoney(r.fila.atraso_cob) }}</td>
                            <td class="px-2 py-1.5">{{ fmtPct(r.fila.atraso_pct) }}</td>
                            <td class="border-l px-2 py-1.5" :class="neg(r.fila.vencido_pc)">{{ fmtMoney(r.fila.vencido_pc) }}</td>
                            <td class="px-2 py-1.5" :class="neg(r.fila.vencido_cob)">{{ fmtMoney(r.fila.vencido_cob) }}</td>
                            <td class="px-2 py-1.5">{{ fmtPct(r.fila.vencido_pct) }}</td>
                            <td class="border-l px-2 py-1.5" :class="neg(r.fila.cobranza_total)">{{ fmtMoney(r.fila.cobranza_total) }}</td>
                            <td class="px-2 py-1.5 font-semibold" :class="r.fila.tipo === 'gran_total' ? '' : semaforo(r.fila)">{{ fmtPct(r.fila.pct_total) }}</td>
                            <td class="px-2 py-1.5" :class="r.fila.tipo === 'gran_total' ? '' : r.fila.mora > 0 ? 'text-red-600 dark:text-red-400' : r.fila.mora < 0 ? 'text-emerald-600 dark:text-emerald-400' : ''">{{ fmtMoney(r.fila.mora) }}</td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div v-if="resultado.semanas.length" class="rounded-lg border p-4">
            <p class="mb-2 text-sm font-medium">Semanas incluidas</p>
            <ul class="grid gap-2 text-sm sm:grid-cols-2">
                <li v-for="s in resultado.semanas" :key="s.corte" class="flex justify-between gap-3 rounded-md bg-muted/40 px-3 py-2">
                    <span>{{ s.titulo }}</span>
                    <span v-if="s.gran_total" class="font-medium tabular-nums" :class="semaforo(s.gran_total)">{{ fmtPct(s.gran_total.pct_total) }}</span>
                </li>
            </ul>
        </div>

        <details class="group rounded-lg border">
            <summary class="flex cursor-pointer list-none items-center justify-between px-4 py-3 text-sm font-medium">
                Notas metodológicas e incidencias de auditoría ({{ resultado.incidencias.length.toLocaleString('es-MX') }})
                <ChevronDown class="size-4 transition-transform group-open:rotate-180" />
            </summary>
            <div class="space-y-4 border-t px-4 py-3 text-sm">
                <ul class="list-disc space-y-1 pl-5 text-muted-foreground">
                    <li v-for="n in resultado.notas" :key="n">{{ n }}</li>
                </ul>
                <div v-if="porCodigo.length" class="flex flex-wrap gap-2">
                    <span v-for="[c, n] in porCodigo" :key="c" class="rounded-full border px-2.5 py-0.5 text-xs">
                        {{ c }}: <b>{{ n.toLocaleString('es-MX') }}</b>
                    </span>
                </div>
                <div v-if="resultado.incidencias.length" class="max-h-80 overflow-auto rounded-md border">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-muted text-left">
                            <tr>
                                <th class="px-2 py-1.5">Código</th>
                                <th class="px-2 py-1.5">Contrato</th>
                                <th class="px-2 py-1.5">Fecha</th>
                                <th class="px-2 py-1.5 text-right">Importe</th>
                                <th class="px-2 py-1.5">Descripción</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="i in resultado.incidencias.slice(0, 500)" :key="i.id">
                                <td class="px-2 py-1 font-mono">{{ i.codigo }}</td>
                                <td class="px-2 py-1">{{ i.contrato ?? '—' }}</td>
                                <td class="px-2 py-1 whitespace-nowrap">{{ i.fecha ?? '—' }}</td>
                                <td class="px-2 py-1 text-right tabular-nums">{{ i.importe !== null ? fmtMoney(i.importe) : '—' }}</td>
                                <td class="px-2 py-1">{{ i.descripcion }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="resultado.incidencias.length > 500" class="px-2 py-1.5 text-xs text-muted-foreground">
                        Se muestran las primeras 500.
                    </p>
                </div>
            </div>
        </details>
    </div>
</template>
