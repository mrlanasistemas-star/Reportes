<script setup lang="ts">
import { CheckCircle2, CircleDashed, Receipt, Wallet } from 'lucide-vue-next';
import type { Modo, OpcionCorte } from './types';

defineProps<{ modo: Modo; opcion: OpcionCorte | null }>();

/** Qué pide cada modalidad cuando aún no hay un corte que mostrar. */
const GUIA: Record<Modo, string> = {
    DIARIO: 'Carga la cartera del domingo base, las carteras hasta el día que quieres calcular y un Ingresos Cobranza que incluya ese día.',
    ACUMULADO:
        'Carga las carteras del domingo base hasta el día elegido y un Ingresos Cobranza que cubra del lunes a ese día.',
    SEMANAL:
        'Carga las 8 carteras (domingo base, lunes a sábado y domingo de cierre) y el Ingresos Cobranza de lunes a domingo.',
    MENSUAL:
        'Carga las semanas completas del mes (8 carteras por semana) y sus Ingresos Cobranza.',
};
</script>

<template>
    <div class="rounded-lg border" aria-live="polite">
        <template v-if="opcion">
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-2.5"
            >
                <p class="font-medium">{{ opcion.titulo }}</p>
                <span
                    class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                    :class="
                        opcion.listo
                            ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                            : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300'
                    "
                >
                    {{
                        opcion.listo
                            ? 'Listo para calcular'
                            : opcion.estado === 'faltan_ingresos'
                              ? 'Faltan ingresos'
                              : 'Faltan carteras'
                    }}
                </span>
            </div>
            <ul class="grid gap-px bg-border sm:grid-cols-2 lg:grid-cols-3">
                <li
                    v-for="r in opcion.requisitos"
                    :key="r.tipo + r.etiqueta"
                    class="flex items-start gap-2 bg-card px-4 py-2.5"
                >
                    <CheckCircle2
                        v-if="r.disponible"
                        class="mt-0.5 size-4 shrink-0 text-emerald-600"
                    />
                    <CircleDashed
                        v-else
                        class="mt-0.5 size-4 shrink-0 text-amber-500"
                    />
                    <div class="min-w-0">
                        <p
                            class="flex items-center gap-1.5 text-sm font-medium"
                        >
                            <Receipt
                                v-if="r.tipo === 'ingresos'"
                                class="size-3.5 text-muted-foreground"
                            />
                            <Wallet
                                v-else-if="r.tipo === 'cartera'"
                                class="size-3.5 text-muted-foreground"
                            />
                            {{ r.etiqueta }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{
                                r.detalle ??
                                (r.disponible ? 'Disponible' : 'Faltante')
                            }}
                        </p>
                    </div>
                </li>
            </ul>
            <p
                class="border-t px-4 py-2.5 text-sm"
                :class="
                    opcion.listo
                        ? 'text-emerald-700 dark:text-emerald-400'
                        : 'font-medium text-amber-800 dark:text-amber-300'
                "
            >
                {{
                    opcion.listo
                        ? 'Tienes todos los archivos que necesita este corte.'
                        : opcion.falta
                }}
            </p>
        </template>
        <p v-else class="px-4 py-3 text-sm text-muted-foreground">
            {{ GUIA[modo] }}
        </p>
    </div>
</template>
