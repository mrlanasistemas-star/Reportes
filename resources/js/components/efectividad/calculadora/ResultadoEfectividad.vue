<script setup lang="ts">
import { AlertTriangle, Info, Loader2 } from 'lucide-vue-next';
import { computed } from 'vue';
import DetalleAuditoria from './DetalleAuditoria.vue';
import TablaEfectividad from './TablaEfectividad.vue';
import type { FilaEfectividad, Resultado } from './types';
import {
    colorMora,
    fmtMoney,
    fmtPct,
    partesLeyenda,
    semaforo,
    tienePct,
} from './types';

const props = defineProps<{
    resultado: Resultado;
    filas: FilaEfectividad[];
    /** Hay un filtro de Sucursal/Ruta/Promotor aplicado. */
    filtrado?: boolean;
    /** Se está cargando la vista filtrada. */
    cargando?: boolean;
}>();

/** GRAN TOTAL de la vista actual (cambia con los filtros). */
const total = computed(
    () => props.filas.find((f) => f.tipo === 'gran_total') ?? null,
);

const kpisVista = computed(() => {
    const t = total.value;

    if (!t) {
        return [];
    }

    return [
        { l: 'Sano por cobrar', v: fmtMoney(t.sano_pc), c: '' },
        { l: 'Cobranza total', v: fmtMoney(t.cobranza_total), c: '' },
        {
            l: '% Efectividad',
            v: fmtPct(t.pct_total),
            c: tienePct(t.pct_total) ? semaforo(t) : 'text-muted-foreground',
        },
        { l: 'Mora', v: fmtMoney(t.mora), c: colorMora(t.mora) },
    ];
});

const leyenda = computed(() => partesLeyenda(props.resultado.leyenda));
</script>

<template>
    <div class="space-y-5">
        <div v-if="resultado.avisos.length" class="space-y-2">
            <div
                v-for="a in resultado.avisos"
                :key="a"
                class="flex gap-2.5 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/70 dark:bg-amber-950/30 dark:text-amber-200"
                role="note"
            >
                <AlertTriangle
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                {{ a }}
            </div>
        </div>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0 -translate-y-1"
            leave-active-class="transition duration-150 ease-in"
            leave-to-class="opacity-0"
        >
            <div
                v-if="filtrado && kpisVista.length"
                class="grid grid-cols-2 gap-3 lg:grid-cols-4"
                aria-label="Resumen de la vista filtrada"
            >
                <div
                    v-for="k in kpisVista"
                    :key="k.l"
                    class="rounded-2xl border bg-card p-3.5 shadow-xs"
                >
                    <p class="text-xs text-muted-foreground">
                        {{ k.l }} · vista filtrada
                    </p>
                    <p
                        class="mt-1 text-lg font-semibold tabular-nums"
                        :class="k.c"
                    >
                        {{ k.v }}
                    </p>
                </div>
            </div>
        </Transition>

        <div
            v-if="leyenda.length"
            class="flex flex-wrap items-center gap-x-2 gap-y-1.5 rounded-2xl bg-muted/50 px-4 py-2.5 text-xs text-muted-foreground"
        >
            <Info class="size-3.5 shrink-0" aria-hidden="true" />
            <template v-for="(parte, i) in leyenda" :key="parte">
                <span v-if="i > 0" class="text-border" aria-hidden="true"
                    >•</span
                >
                <span
                    :class="i === 0 ? 'font-medium text-foreground/80' : ''"
                    >{{ parte }}</span
                >
            </template>
            <span class="text-muted-foreground/80">
                · Sin base (Sano por cobrar en cero) se muestra “—”.
            </span>
        </div>

        <div class="relative">
            <p
                v-if="!filas.length"
                class="rounded-2xl border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                No hay movimientos para este corte con los filtros elegidos.
            </p>
            <TablaEfectividad
                v-else
                :filas="filas"
                class="transition-opacity duration-200"
                :class="cargando ? 'opacity-50' : ''"
                :aria-busy="cargando ? 'true' : 'false'"
            />
            <div
                v-if="cargando"
                class="pointer-events-none absolute inset-x-0 top-14 flex justify-center"
            >
                <span
                    class="inline-flex items-center gap-2 rounded-full border bg-card px-3 py-1.5 text-xs font-medium shadow-sm"
                >
                    <Loader2 class="size-3.5 animate-spin" aria-hidden="true" />
                    Actualizando vista…
                </span>
            </div>
        </div>

        <section
            v-if="resultado.semanas.length"
            class="rounded-2xl border bg-card p-4 shadow-xs"
            aria-labelledby="semanas-incluidas"
        >
            <h3 id="semanas-incluidas" class="mb-3 text-sm font-semibold">
                Semanas incluidas
            </h3>
            <ul class="grid gap-2 text-sm sm:grid-cols-2 xl:grid-cols-3">
                <li
                    v-for="s in resultado.semanas"
                    :key="s.corte"
                    class="flex items-center justify-between gap-3 rounded-xl bg-muted/50 px-3 py-2"
                >
                    <span class="min-w-0 truncate" :title="s.titulo">{{
                        s.titulo
                    }}</span>
                    <span
                        v-if="s.gran_total"
                        class="font-semibold tabular-nums"
                        :class="
                            tienePct(s.gran_total.pct_total)
                                ? semaforo(s.gran_total)
                                : 'text-muted-foreground'
                        "
                        >{{ fmtPct(s.gran_total.pct_total) }}</span
                    >
                </li>
            </ul>
        </section>

        <DetalleAuditoria
            :key="resultado.generado"
            :notas="resultado.notas"
            :incidencias="resultado.incidencias"
        />
    </div>
</template>
