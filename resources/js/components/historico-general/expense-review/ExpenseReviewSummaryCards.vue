<script setup lang="ts">
/**
 * ExpenseReviewSummaryCards — tarjetas de resumen de la Revisión de gastos.
 *
 * Cuatro tarjetas (Sin colaborador, Sin sucursal, Corporativo, Revisados) con conteo, monto y
 * una nota. Cada tarjeta funciona como botón que aplica su filtro al listado.
 */
import { Building2, CheckCircle2, Landmark, UserX } from 'lucide-vue-next';
import { computed } from 'vue';
import { money, num } from '@/lib/format';
import type { ReviewFilter, ReviewSummary } from './types';

/** Props: `summary` = resumen del periodo (o `null` mientras carga); `active` = filtro activo. */
const props = defineProps<{
    summary: ReviewSummary | null;
    active: ReviewFilter;
}>();
/** Emits: `select` con el filtro de la tarjeta pulsada. */
const emit = defineEmits<{ (e: 'select', filter: ReviewFilter): void }>();

/**
 * Definición de las tarjetas a partir del resumen: clave de filtro, etiqueta, icono, tono,
 * conteo, monto (`null` en «Revisados», que no muestra monto) y nota descriptiva.
 */
const cards = computed(() => {
    const s = props.summary;

    return [
        {
            key: 'sin_empleado' as const,
            label: 'Sin colaborador',
            icon: UserX,
            tone: 'text-warning',
            count: s?.sin_empleado.count ?? 0,
            total: s?.sin_empleado.total ?? 0,
            note: s?.confirmados_sin_empleado
                ? `${num(s.confirmados_sin_empleado)} confirmados como «no pertenece»`
                : 'Pendientes de revisar (opcional)',
        },
        {
            key: 'sin_sucursal' as const,
            label: 'Sin sucursal',
            icon: Building2,
            tone: 'text-danger',
            count: s?.sin_sucursal.count ?? 0,
            total: s?.sin_sucursal.total ?? 0,
            note: s?.confirmados_sin_sucursal
                ? `${num(s.confirmados_sin_sucursal)} confirmados sin sucursal`
                : 'No suman a ninguna sucursal',
        },
        {
            key: 'corporativo' as const,
            label: 'Corporativo',
            icon: Landmark,
            tone: 'text-info',
            count: s?.corporativo.count ?? 0,
            total: s?.corporativo.total ?? 0,
            note: 'Fuera de sucursales · Estado de Resultados',
        },
        {
            key: 'revisados' as const,
            label: 'Revisados',
            icon: CheckCircle2,
            tone: 'text-success',
            count: s?.revisados ?? 0,
            total: null,
            note: `de ${num(s?.total.count ?? 0)} gastos · ${money(s?.total.total ?? 0)}`,
        },
    ];
});
</script>

<template>
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <button
            v-for="card in cards"
            :key="card.key"
            type="button"
            class="group rounded-2xl border bg-card p-4 text-left shadow-soft-sm transition hover:border-primary/40 hover:shadow-soft-md focus:outline-none focus-visible:ring-4 focus-visible:ring-primary/20"
            :class="
                active === card.key
                    ? 'border-primary/60 ring-2 ring-primary/15'
                    : 'border-border'
            "
            :aria-pressed="active === card.key"
            @click="emit('select', card.key)"
        >
            <div class="flex items-center justify-between gap-2">
                <span class="text-xs font-semibold text-muted-foreground">{{
                    card.label
                }}</span>
                <component
                    :is="card.icon"
                    class="size-4"
                    :class="card.tone"
                    aria-hidden="true"
                />
            </div>
            <p class="mt-2 text-2xl font-bold text-foreground tabular-nums">
                {{ num(card.count) }}
            </p>
            <p
                v-if="card.total !== null"
                class="text-sm font-medium text-foreground/80 tabular-nums"
            >
                {{ money(card.total) }}
            </p>
            <p class="mt-1 line-clamp-2 text-xs text-muted-foreground">
                {{ card.note }}
            </p>
        </button>
    </div>
</template>
