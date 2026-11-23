<script setup lang="ts">
/**
 * OkrEvidenceUploadDialog — diálogo "Subir archivo" para adjuntar evidencia a
 * un OKR (docs/imagenesOKR/11.png), nunca un dropzone gigante embebido en la
 * página. El almacenamiento privado y las autorizaciones viven en
 * EvidenceController. Se usa en pages/Okr/Show.
 *
 * Props:
 * - objectiveId: id del OKR al que se sube la evidencia.
 *
 * v-model:
 * - open: abre/cierra el diálogo.
 *
 * Emits:
 * - saved: la evidencia se subió correctamente (el diálogo ya se cerró).
 */
import { useForm } from '@inertiajs/vue3';
import { Upload } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{ objectiveId: number }>();
const open = defineModel<boolean>('open', { default: false });
const emit = defineEmits<{ (e: 'saved'): void }>();

/** Formulario Inertia: archivo, comentario y KR opcional. */
const form = useForm({
    file: null as File | null,
    comment: '',
    okr_key_result_id: null as number | null,
});
/** true mientras se arrastra un archivo sobre la zona de carga. */
const isDragging = ref(false);

/** Al abrir el diálogo limpia el formulario y sus errores. */
watch(open, (isOpen) => {
    if (isOpen) {
        form.reset();
        form.clearErrors();
    }
});

/**
 * Guarda el archivo elegido en el formulario.
 * @param file archivo seleccionado o arrastrado (o nada).
 */
function pickFile(file: File | undefined | null) {
    form.file = file ?? null;
}
/**
 * Toma el primer archivo del selector de archivos.
 * @param e evento change del input.
 */
function onFileChange(e: Event) {
    pickFile((e.target as HTMLInputElement).files?.[0]);
}
/**
 * Toma el primer archivo soltado sobre la zona de carga.
 * @param e evento drop.
 */
function onDrop(e: DragEvent) {
    isDragging.value = false;
    pickFile(e.dataTransfer?.files?.[0]);
}

/** Sube la evidencia a /okr/{id}/evidences; al terminar cierra y emite saved. */
function submit() {
    form.post(`/okr/${props.objectiveId}/evidences`, {
        onSuccess: () => {
            open.value = false;
            emit('saved');
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle>Subir archivo</DialogTitle>
                <DialogDescription
                    >Adjunta evidencia o el archivo fuente cuando el dato no
                    esté disponible automáticamente.</DialogDescription
                >
            </DialogHeader>

            <div class="space-y-3">
                <!-- El input de archivo es sr-only (no "hidden"): así sigue en el orden de
                     tabulación y Enter/Espacio abre el selector desde el teclado. -->
                <label
                    class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-6 text-center transition-colors focus-within:ring-2 focus-within:ring-ring/50"
                    :class="
                        isDragging
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:border-primary/40 hover:bg-muted/30'
                    "
                    @dragover.prevent="isDragging = true"
                    @dragleave.prevent="isDragging = false"
                    @drop.prevent="onDrop"
                >
                    <Upload
                        class="size-5 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <p class="text-sm font-semibold text-foreground">
                        {{
                            form.file
                                ? form.file.name
                                : 'Arrastra un archivo o haz clic'
                        }}
                    </p>
                    <input type="file" class="sr-only" @change="onFileChange" />
                </label>
                <label for="okr-evidence-comment" class="sr-only"
                    >Comentario</label
                >
                <input
                    id="okr-evidence-comment"
                    v-model="form.comment"
                    maxlength="1000"
                    placeholder="Comentario (opcional)"
                    class="app-input"
                />
                <p
                    v-if="form.errors.file"
                    role="alert"
                    class="text-xs font-semibold text-destructive"
                >
                    {{ form.errors.file }}
                </p>
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.processing"
                    @click="open = false"
                    >Cancelar</Button
                >
                <Button
                    type="button"
                    :disabled="!form.file || form.processing"
                    @click="submit"
                    >{{
                        form.processing ? 'Subiendo…' : 'Subir archivo'
                    }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
