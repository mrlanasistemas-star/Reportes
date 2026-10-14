<script setup lang="ts">
import { FileSpreadsheet, Loader2, UploadCloud } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    subiendo: boolean;
    progreso: { actual: number; total: number; nombre: string } | null;
    maxMb: number;
}>();

const emit = defineEmits<{ archivos: [File[]] }>();

const arrastrando = ref(false);
const input = ref<HTMLInputElement | null>(null);

function recibir(lista: FileList | null | undefined) {
    const archivos = Array.from(lista ?? []);

    if (archivos.length) {
        emit('archivos', archivos);
    }

    if (input.value) {
        input.value.value = '';
    }
}

function soltar(e: DragEvent) {
    arrastrando.value = false;
    recibir(e.dataTransfer?.files);
}
</script>

<template>
    <div
        role="button"
        tabindex="0"
        aria-label="Seleccionar o arrastrar archivos de Excel"
        class="flex cursor-pointer flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed px-6 py-10 text-center transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
        :class="
            arrastrando
                ? 'border-primary bg-primary/5'
                : 'border-border hover:border-primary/60 hover:bg-muted/40'
        "
        @click="!subiendo && input?.click()"
        @keydown.enter.prevent="!subiendo && input?.click()"
        @keydown.space.prevent="!subiendo && input?.click()"
        @dragover.prevent="arrastrando = true"
        @dragleave.prevent="arrastrando = false"
        @drop.prevent="soltar"
    >
        <input
            ref="input"
            type="file"
            multiple
            accept=".xlsx,.xlsm,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
            class="hidden"
            @change="recibir(($event.target as HTMLInputElement).files)"
        />
        <template v-if="subiendo && progreso">
            <Loader2 class="size-10 animate-spin text-primary" />
            <div>
                <p class="font-medium">
                    Leyendo archivo {{ progreso.actual }} de
                    {{ progreso.total }}…
                </p>
                <p class="max-w-md truncate text-sm text-muted-foreground">
                    {{ progreso.nombre }}
                </p>
            </div>
            <div
                class="h-1.5 w-full max-w-sm overflow-hidden rounded-full bg-muted"
            >
                <div
                    class="h-full rounded-full bg-primary transition-all"
                    :style="{
                        width: `${Math.round(((progreso.actual - 1) / progreso.total) * 100)}%`,
                    }"
                />
            </div>
        </template>
        <template v-else>
            <div
                class="flex size-14 items-center justify-center rounded-full bg-primary/10"
            >
                <UploadCloud class="size-7 text-primary" />
            </div>
            <div>
                <p class="font-medium">
                    Arrastra aquí tus archivos o
                    <span class="text-primary underline underline-offset-4"
                        >selecciónalos</span
                    >
                </p>
                <p class="mt-1 text-sm text-muted-foreground">
                    Puedes elegir todos a la vez: las carteras «Saldos por
                    Cliente», el «Ingresos Cobranza» de la semana y, si lo
                    tienes, el «Ingresos Cobranza» histórico (semanas
                    anteriores). Las fechas se leen del contenido, no del
                    nombre.
                </p>
            </div>
            <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                <FileSpreadsheet class="size-3.5" /> Excel .xlsx · máximo
                {{ maxMb }} MB por archivo
            </p>
        </template>
    </div>
</template>
