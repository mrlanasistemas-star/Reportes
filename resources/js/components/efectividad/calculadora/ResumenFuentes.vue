<script setup lang="ts">
import { CheckCircle2, CircleDashed, Receipt, Trash2, XCircle } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import type { Rechazado, ResumenFuentes } from './types';
import { fmtFecha } from './types';

defineProps<{
    resumen: ResumenFuentes;
    rechazados: Rechazado[];
    ocupado: boolean;
}>();

defineEmits<{ quitar: [string] }>();

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
                {{ rechazados.length === 1 ? 'Un archivo no se pudo usar' : `${rechazados.length} archivos no se pudieron usar` }}
            </p>
            <ul class="mt-1.5 space-y-1 text-sm">
                <li v-for="r in rechazados" :key="r.nombre" class="flex gap-2">
                    <XCircle class="mt-0.5 size-4 shrink-0 text-destructive" />
                    <span><b class="font-medium">{{ r.nombre }}</b> — {{ r.error }}</span>
                </li>
            </ul>
        </div>

        <div v-if="resumen.semana" class="rounded-lg border">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-2.5">
                <p class="font-medium">{{ resumen.semana.titulo }}</p>
                <span class="text-xs text-muted-foreground">
                    {{ resumen.semana.dias.filter((d) => d.disponible).length }}/8 carteras
                </span>
            </div>
            <ul class="grid gap-px bg-border sm:grid-cols-2 lg:grid-cols-4">
                <li
                    v-for="d in resumen.semana.dias"
                    :key="d.fecha"
                    class="flex items-start gap-2 bg-card px-4 py-2.5"
                >
                    <CheckCircle2 v-if="d.disponible" class="mt-0.5 size-4 shrink-0 text-emerald-600" />
                    <CircleDashed v-else class="mt-0.5 size-4 shrink-0 text-amber-500" />
                    <div class="min-w-0">
                        <p class="text-sm font-medium">
                            {{ d.dia }} · {{ fmtFecha(d.fecha) }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground" :title="d.archivo ?? ''">
                            {{ d.disponible ? `${n(d.contratos)} contratos` : 'Falta este archivo' }}
                        </p>
                    </div>
                </li>
            </ul>
            <div class="flex items-center gap-2 border-t px-4 py-2.5 text-sm">
                <CheckCircle2 v-if="resumen.semana.ingresos_ok" class="size-4 text-emerald-600" />
                <CircleDashed v-else class="size-4 text-amber-500" />
                Ingresos de la semana:
                <b class="font-medium">{{ resumen.semana.ingresos_ok ? 'Disponibles' : 'Faltan o no cubren todos los días' }}</b>
            </div>
        </div>

        <div class="overflow-hidden rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left text-xs text-muted-foreground">
                    <tr>
                        <th class="px-3 py-2 font-medium">Archivo</th>
                        <th class="px-3 py-2 font-medium">Tipo</th>
                        <th class="px-3 py-2 font-medium">Fecha(s)</th>
                        <th class="hidden px-3 py-2 text-right font-medium sm:table-cell">Registros</th>
                        <th class="px-3 py-2"><span class="sr-only">Quitar</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="a in [...resumen.carteras, ...resumen.ingresos]" :key="a.id">
                        <td class="max-w-[14rem] truncate px-3 py-2" :title="a.nombre">{{ a.nombre }}</td>
                        <td class="px-3 py-2">
                            <span
                                class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="a.tipo === 'cartera' ? 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-300' : 'bg-violet-100 text-violet-800 dark:bg-violet-950 dark:text-violet-300'"
                            >
                                <Receipt v-if="a.tipo === 'ingresos'" class="size-3" />
                                {{ a.tipo === 'cartera' ? 'Cartera' : 'Ingresos' }}
                            </span>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ a.tipo === 'cartera' ? fmtFecha(a.fecha) : `${fmtFecha(a.desde)} – ${fmtFecha(a.hasta)}` }}
                        </td>
                        <td class="hidden px-3 py-2 text-right tabular-nums sm:table-cell">
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
