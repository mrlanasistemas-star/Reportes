<script setup lang="ts">
/**
 * ExportButtons — Excel y PDF del periodo seleccionado. Sin `employeeId`
 * exporta la lista tal como se ve (mismas personas, mismo orden); con
 * `employeeId` exporta la ficha de esa persona. La descarga usa downloadFile()
 * (pantalla de carga y errores en español, igual que el resto del sistema).
 */
import { FileSpreadsheet, FileText } from 'lucide-vue-next';
import { ref } from 'vue';
import { downloadFile } from '@/lib/downloadOverlay';

const props = defineProps<{
    periodId: number | null;
    ids?: number[];
    employeeId?: number | null;
    disabled?: boolean;
}>();

const busy = ref<'excel' | 'pdf' | null>(null);

async function run(format: 'excel' | 'pdf'): Promise<void> {
    if (!props.periodId || busy.value) {
        return;
    }

    const q = new URLSearchParams();

    if (props.employeeId) {
        q.set('colaborador', String(props.employeeId));
    } else if (props.ids?.length) {
        q.set('ids', props.ids.join(','));
    }

    busy.value = format;

    try {
        await downloadFile(
            `/datos-duros/periodos/${props.periodId}/exportar/${format}${q.size ? `?${q}` : ''}`,
        );
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div class="flex shrink-0 items-center gap-1.5">
        <button
            type="button"
            :disabled="disabled || !periodId || !!busy"
            class="inline-flex h-8 items-center gap-1.5 rounded-xl border border-success/30 bg-success-soft px-3 text-xs font-bold text-success transition hover:-translate-y-px hover:shadow-soft-xs disabled:pointer-events-none disabled:opacity-50"
            :title="
                employeeId
                    ? 'Descargar la ficha en Excel'
                    : 'Descargar en Excel lo que se ve en pantalla'
            "
            @click="run('excel')"
        >
            <FileSpreadsheet class="size-3.5" aria-hidden="true" />
            Excel
        </button>
        <button
            type="button"
            :disabled="disabled || !periodId || !!busy"
            class="inline-flex h-8 items-center gap-1.5 rounded-xl border border-danger/30 bg-danger-soft px-3 text-xs font-bold text-danger transition hover:-translate-y-px hover:shadow-soft-xs disabled:pointer-events-none disabled:opacity-50"
            :title="
                employeeId
                    ? 'Descargar la ficha en PDF'
                    : 'Descargar en PDF lo que se ve en pantalla'
            "
            @click="run('pdf')"
        >
            <FileText class="size-3.5" aria-hidden="true" />
            PDF
        </button>
    </div>
</template>
