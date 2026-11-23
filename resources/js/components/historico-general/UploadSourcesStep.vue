<script setup lang="ts">
/**
 * Etapa 1 del flujo Histórico general: cuadrícula de tarjetas (SourceUploadCard) para
 * subir el archivo de cada fuente del periodo. En periodos derivados muestra
 * AutomaticPeriodInfo en lugar de la cuadrícula. No llama rutas: reenvía eventos al padre.
 * Emits: `upload` ({ sourceId, file }) y `delete` (id de la carga).
 */
import { computed } from 'vue';
import AutomaticPeriodInfo from './AutomaticPeriodInfo.vue';
import SectionHeader from './SectionHeader.vue';
import SourceUploadCard from './SourceUploadCard.vue';

/**
 * Props: `sources` catálogo de fuentes; `uploadsBySource` carga vigente por código de fuente;
 * `selectedPeriodId`; `period` periodo actual; `uploading` deshabilita las tarjetas durante una subida.
 */
const props = defineProps<{
    sources: any[];
    uploadsBySource: Record<string, any>;
    selectedPeriodId: number | null;
    period: any;
    uploading?: boolean;
}>();
/** Eventos reenviados desde cada tarjeta: `upload` y `delete`. */
const emit = defineEmits(['upload', 'delete']);

// Rotación e IMSS ya no se cargan manualmente — se calculan automáticamente
// desde NOI Nómina + NOI Nómina Fiscal. Se ocultan de la grilla de carga.
const AUTO_DERIVED_CODES = ['rotacion', 'imss'];
/** Fuentes que se muestran para carga manual (excluye las derivadas de NOI). */
const visibleSources = computed(() =>
    props.sources.filter((s) => !AUTO_DERIVED_CODES.includes(s.code)),
);
</script>

<template>
    <div class="space-y-5">
        <SectionHeader
            eyebrow="Etapa 1"
            title="Archivos y periodo"
            description='Carga los archivos del mes. Las fuentes marcadas "Necesaria" son obligatorias para activar la carga de registros; las marcadas "Para el reporte" son necesarias para generar la radiografía financiera.'
        />
        <AutomaticPeriodInfo v-if="period?.is_derived" :period="period" />
        <template v-else>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <SourceUploadCard
                    v-for="source in visibleSources"
                    :key="source.id"
                    :source="source"
                    :upload="uploadsBySource[source.code]"
                    :selected-period-id="selectedPeriodId"
                    :disabled="
                        !selectedPeriodId || period?.is_derived || uploading
                    "
                    @upload="emit('upload', $event)"
                    @delete="emit('delete', $event)"
                />
            </div>
        </template>
    </div>
</template>
