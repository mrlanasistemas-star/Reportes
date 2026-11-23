<script setup lang="ts">
/**
 * ExpenseStatusBadges — insignias de estado de un gasto en la Revisión de gastos.
 *
 * Muestra el estado del colaborador (manual / confirmado sin colaborador / sin colaborador),
 * el de la sucursal (Corporativo / manual / confirmada sin sucursal / sin sucursal) y si la
 * atribución automática requiere revisión. Solo presentación: sin emits ni peticiones.
 */
import { Badge } from '@/components/ui/badge';
import type { ExpenseRow } from './types';

/** Props: `row` = fila de gasto cuyos estados se muestran. */
defineProps<{ row: ExpenseRow }>();
</script>

<template>
    <!-- Insignias: colaborador, sucursal y aviso de revisión -->
    <div class="flex flex-wrap gap-1">
        <Badge
            v-if="row.employee_review_status === 'asignado'"
            class="border-success-border bg-success-soft text-success"
            >Colaborador manual</Badge
        >
        <Badge
            v-else-if="row.employee_review_status === 'sin_empleado'"
            class="border-border bg-muted text-muted-foreground"
            >Sin colaborador (confirmado)</Badge
        >
        <Badge
            v-else-if="!row.employee"
            class="border-warning-border bg-warning-soft text-warning"
            >Sin colaborador</Badge
        >

        <Badge
            v-if="row.branch_review_status === 'corporativo'"
            class="border-info-border bg-info-soft text-info"
            >Corporativo</Badge
        >
        <Badge
            v-else-if="row.branch_review_status === 'asignada'"
            class="border-success-border bg-success-soft text-success"
            >Sucursal manual</Badge
        >
        <Badge
            v-else-if="row.branch_review_status === 'sin_sucursal'"
            class="border-border bg-muted text-muted-foreground"
            >Sin sucursal (confirmado)</Badge
        >
        <Badge
            v-else-if="!row.branch"
            class="border-danger-border bg-danger-soft text-danger"
            >Sin sucursal</Badge
        >

        <Badge
            v-if="row.needs_review"
            class="border-warning-border bg-warning-soft text-warning"
            >Revisar atribución</Badge
        >
    </div>
</template>
