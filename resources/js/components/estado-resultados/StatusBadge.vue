<script setup lang="ts">
/**
 * StatusBadge — insignia redonda con icono y texto del estado de una fuente.
 *
 * Se usa en AccountUploadCard, ControlPanel, PayrollUploadCard, SourcesPanel y
 * en Histórico general (AutomaticPeriodDetail / AutomaticPeriodInfo).
 *
 * Props:
 * - status: estado de la fuente (procesado, cargado, con_incidencias, pendiente).
 *   Etiqueta y colores salen de STATUS_META; un estado desconocido se muestra
 *   como «pendiente».
 */
import {
    CheckCircle2,
    CircleDashed,
    AlertTriangle,
    UploadCloud,
} from 'lucide-vue-next';
import { computed } from 'vue';
import { STATUS_META } from './types';
import type { SourceStatus } from './types';

const props = defineProps<{ status: SourceStatus }>();

/** Etiqueta y clases del estado (con respaldo a «pendiente»). */
const meta = computed(() => STATUS_META[props.status] ?? STATUS_META.pendiente);
/** Icono lucide que corresponde a cada estado. */
const icon = computed(
    () =>
        ({
            procesado: CheckCircle2,
            cargado: UploadCloud,
            con_incidencias: AlertTriangle,
            pendiente: CircleDashed,
        })[props.status] ?? CircleDashed,
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[11px] font-semibold whitespace-nowrap"
        :class="meta.cls"
    >
        <component :is="icon" class="size-3" />
        {{ meta.label }}
    </span>
</template>
