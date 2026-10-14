<script setup lang="ts">
import { History, Receipt, Trash2, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { Rechazado, ResumenFuentes } from './types';
import { fmtFecha } from './types';

const props = defineProps<{
    resumen: ResumenFuentes;
    rechazados: Rechazado[];
    ocupado: boolean;
    /** Primer día del periodo del corte elegido (para saber si hay ingresos previos). */
    inicioPeriodo?: string | null;
}>();

defineEmits<{ quitar: [string] }>();

/**
 * Cobertura de ingresos. La macro reconoce anticipos y registra NO-CARTERA desde su
 * historial acumulado (_HIST_INGRESOS); aquí ese historial es la suma de los Excel de
 * Ingresos Cobranza cargados (una fecha la aporta un solo archivo: el más reciente).
 */
const historial = computed(() => {
    const ing = props.resumen.ingresos;

    if (!ing.length) {
        return null;
    }

    const desde = ing.map((i) => i.desde ?? '').sort()[0];
    const hasta = ing
        .map((i) => i.hasta ?? '')
        .sort()
        .reverse()[0];
    const inicioSemana = props.inicioPeriodo ?? null;

    return {
        desde,
        hasta,
        previo: inicioSemana ? desde < inicioSemana : false,
        archivos: ing.length,
    };
});

const n = (v: number | null | undefined) =>
    v === null || v === undefined ? '—' : v.toLocaleString('es-MX');
</script>

<template>
    <div class="space-y-4">
        <div
            v-if="rechazados.length"
            class="rounded-lg border border-destructive/30 bg-destructive/5 p-3"
            role="alert"
        >
            <p class="text-sm font-medium text-destructive">
                {{
                    rechazados.length === 1
                        ? 'Un archivo no se pudo usar'
                        : `${rechazados.length} archivos no se pudieron usar`
                }}
            </p>
            <ul class="mt-1.5 space-y-1 text-sm">
                <li v-for="r in rechazados" :key="r.nombre" class="flex gap-2">
                    <XCircle class="mt-0.5 size-4 shrink-0 text-destructive" />
                    <span
                        ><b class="font-medium">{{ r.nombre }}</b> —
                        {{ r.error }}</span
                    >
                </li>
            </ul>
        </div>

        <div
            v-if="historial"
            class="flex gap-3 rounded-lg border p-3 text-sm"
            :class="
                historial.previo
                    ? 'border-emerald-200 bg-emerald-50/60 dark:border-emerald-900 dark:bg-emerald-950/30'
                    : 'border-amber-200 bg-amber-50/60 dark:border-amber-900 dark:bg-amber-950/30'
            "
        >
            <History
                class="mt-0.5 size-4 shrink-0"
                :class="
                    historial.previo ? 'text-emerald-600' : 'text-amber-600'
                "
            />
            <div>
                <p class="font-medium">
                    Ingresos cargados: del {{ fmtFecha(historial.desde) }} al
                    {{ fmtFecha(historial.hasta) }} ({{
                        historial.archivos
                    }}
                    archivo{{ historial.archivos === 1 ? '' : 's' }})
                </p>
                <p class="mt-0.5 text-muted-foreground">
                    <template v-if="historial.previo">
                        Incluye ingresos anteriores a la semana: se reconocen
                        anticipos y se auditan los pagos de contratos sin
                        cartera.
                    </template>
                    <template v-else>
                        Solo hay ingresos de la semana. Para reconocer anticipos
                        y auditar los pagos de contratos sin cartera
                        (NO-CARTERA), agrega también el Excel de Ingresos
                        Cobranza de las semanas anteriores. Si un día aparece en
                        dos archivos, se usa el más reciente: no se duplican
                        movimientos.
                    </template>
                </p>
            </div>
        </div>

        <div
            v-if="resumen.sucursales?.length"
            class="flex flex-wrap gap-2 text-sm"
            aria-label="Sucursales y rutas detectadas"
        >
            <div
                v-for="s in resumen.sucursales"
                :key="s.sucursal"
                class="rounded-lg border px-3 py-2"
                :class="
                    s.en_catalogo
                        ? ''
                        : 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/40'
                "
            >
                <p class="font-medium">
                    {{ s.en_catalogo ? `Sucursal ${s.sucursal}` : s.sucursal }}
                    <span class="font-normal text-muted-foreground"
                        >· {{ s.rutas.length }} ruta{{
                            s.rutas.length === 1 ? '' : 's'
                        }}</span
                    >
                </p>
                <p class="text-xs text-muted-foreground">
                    {{ s.rutas.join(', ') }}
                </p>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg border">
            <table class="w-full text-sm">
                <thead
                    class="bg-muted/50 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-3 py-2 font-medium">Archivo</th>
                        <th class="px-3 py-2 font-medium">Tipo</th>
                        <th class="px-3 py-2 font-medium">Fecha(s)</th>
                        <th
                            class="hidden px-3 py-2 text-right font-medium sm:table-cell"
                        >
                            Registros
                        </th>
                        <th class="px-3 py-2">
                            <span class="sr-only">Quitar</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="a in [...resumen.carteras, ...resumen.ingresos]"
                        :key="a.id"
                    >
                        <td
                            class="max-w-[14rem] truncate px-3 py-2"
                            :title="a.nombre"
                        >
                            {{ a.nombre }}
                        </td>
                        <td class="px-3 py-2">
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="
                                    a.tipo === 'cartera'
                                        ? 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300'
                                        : 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300'
                                "
                            >
                                <Receipt
                                    v-if="a.tipo === 'ingresos'"
                                    class="size-3"
                                />
                                {{
                                    a.tipo === 'cartera'
                                        ? 'Cartera'
                                        : 'Ingresos'
                                }}
                            </span>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{
                                a.tipo === 'cartera'
                                    ? fmtFecha(a.fecha)
                                    : `${fmtFecha(a.desde)} – ${fmtFecha(a.hasta)}`
                            }}
                        </td>
                        <td
                            class="hidden px-3 py-2 text-right tabular-nums sm:table-cell"
                        >
                            {{ n(a.resumen.registros) }}
                        </td>
                        <td class="px-1 py-1 text-right">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-8"
                                :disabled="ocupado"
                                :aria-label="`Quitar ${a.nombre}`"
                                @click="$emit('quitar', a.id)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
