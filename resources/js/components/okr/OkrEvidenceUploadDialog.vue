<script setup lang="ts">
// "Subir archivo" — Dialog (docs/imagenesOKR/11.png), nunca un dropzone
// gigante embebido en la página. Almacenamiento privado y autorizaciones ya
// corregidas se conservan (ver EvidenceController).
import { ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import { Upload } from 'lucide-vue-next'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'

const props = defineProps<{ objectiveId: number }>()
const open = defineModel<boolean>('open', { default: false })
const emit = defineEmits<{ (e: 'saved'): void }>()

const form = useForm({ file: null as File | null, comment: '', okr_key_result_id: null as number | null })
const isDragging = ref(false)

watch(open, (isOpen) => { if (isOpen) { form.reset(); form.clearErrors() } })

function pickFile(file: File | undefined | null) { form.file = file ?? null }
function onFileChange(e: Event) { pickFile((e.target as HTMLInputElement).files?.[0]) }
function onDrop(e: DragEvent) { isDragging.value = false; pickFile(e.dataTransfer?.files?.[0]) }

function submit() {
    form.post(`/okr/${props.objectiveId}/evidences`, {
        onSuccess: () => { open.value = false; emit('saved') },
    })
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle>Subir archivo</DialogTitle>
                <DialogDescription>Adjunta evidencia o el archivo fuente cuando el dato no esté disponible automáticamente.</DialogDescription>
            </DialogHeader>

            <div class="space-y-3">
                <label
                    class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed p-6 text-center transition-colors"
                    :class="isDragging ? 'border-primary bg-primary/5' : 'border-border hover:border-primary/40 hover:bg-muted/30'"
                    @dragover.prevent="isDragging = true" @dragleave.prevent="isDragging = false" @drop.prevent="onDrop"
                >
                    <Upload class="size-5 text-muted-foreground" />
                    <p class="text-sm font-semibold text-foreground">{{ form.file ? form.file.name : 'Arrastra un archivo o haz clic' }}</p>
                    <input type="file" class="hidden" @change="onFileChange">
                </label>
                <input v-model="form.comment" placeholder="Comentario (opcional)" class="app-input">
                <p v-if="form.errors.file" class="text-xs font-semibold text-destructive">{{ form.errors.file }}</p>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="open = false">Cancelar</Button>
                <Button :disabled="!form.file || form.processing" @click="submit">Subir archivo</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
