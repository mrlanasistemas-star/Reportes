<script setup lang="ts">
/**
 * EstadoReporteBadge — insignia "Reporte conciliado" (verde) o "Reporte
 * preliminar" (ámbar) según el estado de la auditoría del cálculo.
 *
 * Se usa en ControlTrazabilidad y ResultadoHero. Al pasar el cursor muestra
 * el texto explicativo del estado.
 *
 * Props:
 * - auditoria: control del cálculo (se usan `estado` y `estado_texto`).
 */
import { ShieldAlert, ShieldCheck } from 'lucide-vue-next';
import type { Auditoria } from './types';

defineProps<{ auditoria: Auditoria }>();
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold whitespace-nowrap"
        :class="
            auditoria.estado === 'CONCILIADO'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-300'
                : 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-300'
        "
        :title="auditoria.estado_texto"
    >
        <ShieldCheck
            v-if="auditoria.estado === 'CONCILIADO'"
            class="size-3.5"
            aria-hidden="true"
        />
        <ShieldAlert v-else class="size-3.5" aria-hidden="true" />
        {{
            auditoria.estado === 'CONCILIADO'
                ? 'Reporte conciliado'
                : 'Reporte preliminar'
        }}
    </span>
</template>
