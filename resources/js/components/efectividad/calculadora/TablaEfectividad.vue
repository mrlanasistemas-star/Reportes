<script setup lang="ts">
/**
 * TablaEfectividad — tabla de efectividad de cobranza agrupada por
 * Sucursal → Ruta → Promotor, con columnas por tramo (Sano, Vigente con
 * atraso, Vencida), cobranza total, % de efectividad y mora.
 *
 * Se usa en ResultadoEfectividad. Encabezados y primera columna fijos al
 * desplazarse; el GRAN TOTAL queda fijo abajo. Los porcentajes sin base
 * (Sano por cobrar en cero) se muestran atenuados como "—".
 *
 * Props:
 * - filas: filas de promotores, subtotales y gran total, en el orden del cálculo.
 */
import { computed } from 'vue';
import type { FilaEfectividad } from './types';
import { colorMora, fmtMoney, fmtPct, semaforo, tienePct } from './types';

const props = defineProps<{ filas: FilaEfectividad[] }>();

/** Campos numéricos de una fila que se pintan como columnas. */
type CampoNumerico =
    | 'sano_pc'
    | 'sano_cob'
    | 'sano_pct'
    | 'atraso_pc'
    | 'atraso_cob'
    | 'atraso_pct'
    | 'vencido_pc'
    | 'vencido_cob'
    | 'vencido_pct'
    | 'cobranza_total'
    | 'pct_total'
    | 'mora';

/** Definición de una columna: campo, tipo de formato y si inicia grupo. */
type Columna = {
    campo: CampoNumerico;
    tipo: 'money' | 'pct' | 'efectividad' | 'mora';
    /** Primera columna de su grupo (lleva separador a la izquierda). */
    inicio?: boolean;
};

/** Grupos de la primera fila de encabezados, con su ancho (colspan) y color de acento. */
const GRUPOS = [
    { titulo: 'Sano', span: 3, acento: 'border-b-emerald-500' },
    { titulo: 'Vigente con atraso', span: 3, acento: 'border-b-amber-400' },
    { titulo: 'Vencida', span: 3, acento: 'border-b-red-500' },
    { titulo: 'Cobranza total', span: 2, acento: 'border-b-sky-400' },
];

/** Segunda fila de encabezados (Mora no aparece: ocupa dos filas). */
const SUBENCABEZADOS = [
    'Por cobrar',
    'Cobrado',
    '% Aporte',
    'Por cobrar',
    'Cobrado',
    '% Aporte',
    'Por cobrar',
    'Cobrado',
    '% Aporte',
    'Cobrado',
    '% Efectividad',
];

/** Columnas numéricas en el orden en que se muestran. */
const COLUMNAS: Columna[] = [
    { campo: 'sano_pc', tipo: 'money', inicio: true },
    { campo: 'sano_cob', tipo: 'money' },
    { campo: 'sano_pct', tipo: 'pct' },
    { campo: 'atraso_pc', tipo: 'money', inicio: true },
    { campo: 'atraso_cob', tipo: 'money' },
    { campo: 'atraso_pct', tipo: 'pct' },
    { campo: 'vencido_pc', tipo: 'money', inicio: true },
    { campo: 'vencido_cob', tipo: 'money' },
    { campo: 'vencido_pct', tipo: 'pct' },
    { campo: 'cobranza_total', tipo: 'money', inicio: true },
    { campo: 'pct_total', tipo: 'efectividad' },
    { campo: 'mora', tipo: 'mora', inicio: true },
];

/** Columnas totales de la tabla (numéricas + Ruta / Promotor), para los colspan. */
const TOTAL_COLUMNAS = COLUMNAS.length + 1;

/** Filas con encabezados intercalados: Sucursal → Ruta → promotores. */
const renglones = computed(() => {
    const out: Array<{
        k: string;
        sucursal?: string;
        oficina?: string;
        fila?: FilaEfectividad;
    }> = [];
    let sucursalActual: string | null | undefined;
    let actual: string | null = null;
    props.filas.forEach((f, i) => {
        if (
            f.tipo === 'promotor' &&
            f.sucursal &&
            f.sucursal !== sucursalActual
        ) {
            sucursalActual = f.sucursal;
            actual = null;
            out.push({ k: `s${i}`, sucursal: f.sucursal });
        }

        if (f.tipo === 'promotor' && f.oficina !== actual) {
            actual = f.oficina;
            out.push({ k: `o${i}`, oficina: f.oficina_titulo ?? '' });
        }

        out.push({ k: `f${i}`, fila: f });
    });

    return out;
});

/** Valor crudo de una columna en una fila. */
const valor = (f: FilaEfectividad, c: Columna) => f[c.campo];

/** Texto de la celda: porcentaje (o "—" sin base) o importe en pesos. */
function texto(f: FilaEfectividad, c: Columna): string {
    const v = valor(f, c);

    return c.tipo === 'pct' || c.tipo === 'efectividad'
        ? fmtPct(v)
        : fmtMoney(v ?? 0);
}

/**
 * Clases de una celda: separador de grupo, atenuado sin base, semáforo en la
 * efectividad, color de mora e importes negativos en rojo (no en el GRAN TOTAL).
 */
function claseCelda(f: FilaEfectividad, c: Columna): string {
    const v = valor(f, c);
    const clases: string[] = [];
    const granTotal = f.tipo === 'gran_total';

    if (c.inicio) {
        clases.push(
            granTotal
                ? 'border-l border-white/15'
                : 'border-l border-border/70',
        );
    }

    if (c.tipo === 'pct' || c.tipo === 'efectividad') {
        if (!tienePct(v)) {
            clases.push('opacity-50');
        } else if (c.tipo === 'efectividad') {
            clases.push('font-semibold');

            if (!granTotal) {
                clases.push(semaforo(f));
            }
        }
    } else if (c.tipo === 'mora') {
        if (!granTotal) {
            clases.push(colorMora(v ?? 0));
        }
    } else if ((v ?? 0) < 0 && !granTotal) {
        clases.push('text-red-600 dark:text-red-400');
    }

    return clases.join(' ');
}

/** Tooltip de la celda: explica el "—" cuando el porcentaje no tiene base. */
function titulo(f: FilaEfectividad, c: Columna): string | undefined {
    return (c.tipo === 'pct' || c.tipo === 'efectividad') &&
        !tienePct(valor(f, c))
        ? 'Sin base: Sano por cobrar en cero'
        : undefined;
}

/** Fondo y peso de letra de la fila según su tipo (promotor, subtotales, gran total). */
function claseFila(f: FilaEfectividad): string {
    switch (f.tipo) {
        case 'subtotal':
            return 'bg-slate-100 font-semibold dark:bg-slate-900';
        case 'subtotal_sucursal':
            return 'bg-slate-200 font-bold dark:bg-slate-800';
        case 'gran_total':
            return 'bg-primary font-bold text-primary-foreground';
        default:
            return 'bg-card hover:bg-muted';
    }
}

/** Celdas fijas (primera columna) necesitan fondo opaco igual al de su fila. */
function fondoFijo(f: FilaEfectividad): string {
    switch (f.tipo) {
        case 'subtotal':
            return 'bg-slate-100 font-semibold dark:bg-slate-900';
        case 'subtotal_sucursal':
            return 'bg-slate-200 font-bold dark:bg-slate-800';
        case 'gran_total':
            return 'bg-primary font-bold';
        default:
            return 'bg-card font-normal group-hover:bg-muted';
    }
}
</script>

<template>
    <div
        class="max-h-[72vh] overflow-auto rounded-2xl border bg-card shadow-sm"
        role="region"
        aria-label="Tabla de efectividad por sucursal, ruta y promotor"
        tabindex="0"
    >
        <table
            class="w-full min-w-[1180px] border-separate border-spacing-0 text-[13px] tabular-nums"
        >
            <!-- Encabezados en dos niveles: grupos por tramo y subcolumnas -->
            <thead class="text-xs text-white">
                <tr>
                    <th
                        rowspan="2"
                        scope="col"
                        class="sticky top-0 left-0 z-30 min-w-[14rem] border-b border-slate-700 bg-slate-900 px-3 text-left font-semibold dark:bg-slate-950"
                    >
                        Ruta / Promotor
                    </th>
                    <th
                        v-for="g in GRUPOS"
                        :key="g.titulo"
                        :colspan="g.span"
                        scope="colgroup"
                        class="sticky top-0 z-20 h-9 border-b-[3px] border-l border-l-slate-700 bg-slate-900 px-2 text-center font-semibold tracking-wide uppercase dark:bg-slate-950"
                        :class="g.acento"
                    >
                        {{ g.titulo }}
                    </th>
                    <th
                        rowspan="2"
                        scope="col"
                        class="sticky top-0 z-20 border-b border-l border-slate-700 bg-slate-900 px-3 text-right font-semibold tracking-wide uppercase dark:bg-slate-950"
                    >
                        Mora
                    </th>
                </tr>
                <tr>
                    <th
                        v-for="(s, i) in SUBENCABEZADOS"
                        :key="i"
                        scope="col"
                        class="sticky top-9 z-20 h-8 border-b border-slate-700 bg-slate-800 px-3 text-right font-medium whitespace-nowrap text-slate-200 dark:bg-slate-900"
                        :class="COLUMNAS[i]?.inicio ? 'border-l' : ''"
                    >
                        {{ s }}
                    </th>
                </tr>
            </thead>
            <!-- Cuerpo: encabezado de sucursal, encabezado de ruta o fila de datos -->
            <tbody>
                <template v-for="r in renglones" :key="r.k">
                    <tr v-if="r.sucursal !== undefined">
                        <td
                            :colspan="TOTAL_COLUMNAS"
                            class="border-b border-slate-700 bg-slate-700 p-0 text-white dark:bg-slate-800"
                        >
                            <div
                                class="sticky left-0 w-max px-3 py-2 text-xs font-bold tracking-widest uppercase"
                            >
                                Sucursal {{ r.sucursal }}
                            </div>
                        </td>
                    </tr>
                    <tr v-else-if="r.oficina !== undefined">
                        <td
                            :colspan="TOTAL_COLUMNAS"
                            class="border-b bg-muted p-0"
                        >
                            <div
                                class="sticky left-0 w-max px-3 py-1.5 text-xs font-semibold tracking-wide text-primary uppercase"
                            >
                                <span class="font-medium text-muted-foreground"
                                    >Ruta ·
                                </span>
                                {{ r.oficina }}
                            </div>
                        </td>
                    </tr>
                    <tr
                        v-else-if="r.fila"
                        class="group text-right transition-colors"
                        :class="claseFila(r.fila)"
                    >
                        <th
                            scope="row"
                            class="sticky left-0 z-10 max-w-[18rem] truncate border-b px-3 py-2 text-left transition-colors"
                            :class="[
                                fondoFijo(r.fila),
                                r.fila.tipo === 'gran_total'
                                    ? 'bottom-0 z-20 border-primary'
                                    : '',
                                r.fila.tipo === 'promotor' ? 'pl-5' : '',
                            ]"
                            :title="r.fila.promotor"
                        >
                            {{ r.fila.promotor }}
                        </th>
                        <td
                            v-for="c in COLUMNAS"
                            :key="c.campo"
                            class="border-b px-3 py-2 whitespace-nowrap"
                            :class="[
                                claseCelda(r.fila, c),
                                r.fila.tipo === 'gran_total'
                                    ? 'sticky bottom-0 z-10 border-primary bg-primary'
                                    : '',
                            ]"
                            :title="titulo(r.fila, c)"
                        >
                            {{ texto(r.fila, c) }}
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</template>
