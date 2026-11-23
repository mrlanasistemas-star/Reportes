<script setup lang="ts">
/**
 * LaborHistoryPanel — tabla "Histórico laboral" con la plantilla NOI, altas,
 * bajas confirmadas y pendientes de cada periodo mensual.
 *
 * Se usa en pages/AsignacionSucursal/Index. Cada fila es un enlace (clic, Enter
 * o Espacio) que recarga la página con ese periodo seleccionado.
 *
 * Props:
 * - rows: resumen por periodo.
 * - selectedPeriodId: periodo abierto actualmente (se resalta su fila).
 */
import { router } from '@inertiajs/vue3';
import AppSectionCard from '@/components/app/AppSectionCard.vue';
import type { LaborHistoryRow } from '@/types/asignaciones';

defineProps<{
    rows: LaborHistoryRow[];
    selectedPeriodId: number | null;
}>();

/**
 * Navega a la pantalla de asignaciones con el periodo indicado.
 * @param periodId id del periodo a abrir
 */
function open(periodId: number) {
    router.get(
        '/asignaciones-empleado-sucursal',
        { period_id: periodId },
        { preserveScroll: false },
    );
}
</script>

<template>
    <AppSectionCard
        title="Histórico laboral"
        subtitle="Plantilla y movimientos de cada periodo mensual, tal como se calcularon. Solo las bajas confirmadas cuentan en la rotación. Haz clic en un periodo para abrirlo."
        :padded="false"
    >
        <div class="overflow-x-auto">
            <table class="w-full min-w-[560px] text-sm">
                <thead
                    class="bg-muted/40 text-left text-xs text-muted-foreground"
                >
                    <tr>
                        <th class="px-4 py-2.5 font-semibold sm:px-6">
                            Periodo
                        </th>
                        <th class="px-3 py-2.5 text-right font-semibold">
                            Plantilla NOI
                        </th>
                        <th class="px-3 py-2.5 text-right font-semibold">
                            Altas
                        </th>
                        <th class="px-3 py-2.5 text-right font-semibold">
                            Bajas confirmadas
                        </th>
                        <th class="px-3 py-2.5 text-right font-semibold">
                            Pendientes
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60">
                    <tr
                        v-for="row in rows"
                        :key="row.period_id"
                        class="cursor-pointer transition hover:bg-muted/40 focus-visible:bg-muted/60 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring"
                        :class="
                            row.period_id === selectedPeriodId
                                ? 'bg-muted/60'
                                : ''
                        "
                        tabindex="0"
                        role="link"
                        :aria-label="`Abrir periodo ${row.label}`"
                        :aria-current="
                            row.period_id === selectedPeriodId
                                ? 'page'
                                : undefined
                        "
                        @click="open(row.period_id)"
                        @keydown.enter.prevent="open(row.period_id)"
                        @keydown.space.prevent="open(row.period_id)"
                    >
                        <td class="px-4 py-2.5 font-semibold sm:px-6">
                            {{ row.label }}
                        </td>
                        <!-- Cifras del periodo, o aviso si su plantilla no se ha calculado -->
                        <template v-if="row.calculado">
                            <td class="px-3 py-2.5 text-right">
                                {{ row.plantilla }}
                            </td>
                            <td
                                class="px-3 py-2.5 text-right text-emerald-600 dark:text-emerald-400"
                            >
                                {{ row.altas }}
                            </td>
                            <td
                                class="px-3 py-2.5 text-right text-rose-600 dark:text-rose-400"
                            >
                                {{ row.bajas_confirmadas }}
                            </td>
                            <td
                                class="px-3 py-2.5 text-right"
                                :class="
                                    row.pendientes
                                        ? 'font-bold text-amber-600 dark:text-amber-400'
                                        : ''
                                "
                            >
                                {{ row.pendientes }}
                            </td>
                        </template>
                        <td
                            v-else
                            colspan="4"
                            class="px-3 py-2.5 text-right text-xs text-muted-foreground"
                        >
                            Sin plantilla calculada
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppSectionCard>
</template>
