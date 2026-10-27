<script setup lang="ts">
// Visor integrado de documentos OKR (Fase 5, 19-oct-2026): el PDF EMITIDO (o
// su copia firmada) se ve dentro del sistema, con encabezado, contexto y las
// acciones "Abrir en pestaña nueva" y "Descargar". Si el archivo no existe se
// explica qué falta — nunca un recuadro vacío ni una pantalla de error.
import { Download, ExternalLink, FileSignature, FileWarning, ShieldAlert } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog'
import { formatFriendlyDate } from '@/lib/okrFormat'
import type { OkrViewerDocument } from './okrDocuments'

const props = defineProps<{ document: OkrViewerDocument | null }>()
const open = defineModel<boolean>('open', { default: false })

const loaded = ref(false)
watch(() => [open.value, props.document?.view_url], () => {
    loaded.value = false
})

const title = computed(() => {
    const d = props.document

    if (!d) {
        return ''
    }

    const base = d.kind === 'letter' ? 'Carta Compromiso' : 'Warning'

    return d.signed ? `${base} firmada` : base
})
const downloadHref = computed(() => (props.document?.signed ? `${props.document.download_url}${props.document.download_url.includes('?') ? '&' : '?'}signed=1` : props.document?.download_url))
const missing = computed(() => props.document?.file_available === false && !props.document?.signed)
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex h-[92vh] w-[96vw] max-w-5xl flex-col gap-3 p-4 sm:max-w-5xl sm:p-5">
            <template v-if="document">
                <div class="flex flex-wrap items-start justify-between gap-3 pr-8">
                    <div class="min-w-0">
                        <DialogTitle class="flex items-center gap-2 text-base font-bold">
                            <FileSignature v-if="document.kind === 'letter'" class="size-5 text-primary" />
                            <ShieldAlert v-else class="size-5 text-rose-600" />
                            {{ title }} · {{ document.folio }}
                            <span v-if="document.status === 'voided'" class="rounded-full bg-destructive/10 px-2 py-0.5 text-[10px] font-bold uppercase text-destructive">Anulado</span>
                        </DialogTitle>
                        <DialogDescription class="mt-0.5 text-xs text-muted-foreground">
                            <template v-if="document.collaborator">{{ document.collaborator }}</template>
                            <template v-if="document.date"> · Emitido el {{ formatFriendlyDate(document.date.slice(0, 10)) }}</template>
                            <template v-if="document.detail"> · {{ document.detail }}</template>
                        </DialogDescription>
                    </div>
                    <div v-if="!missing" class="flex shrink-0 gap-2">
                        <a :href="document.view_url" target="_blank" rel="noopener" class="inline-flex h-9 items-center gap-1.5 rounded-xl border border-border px-3 text-xs font-semibold text-foreground transition hover:border-primary/40 hover:shadow-sm"><ExternalLink class="size-4" /> Abrir en pestaña nueva</a>
                        <a :href="downloadHref" class="inline-flex h-9 items-center gap-1.5 rounded-xl bg-primary px-3 text-xs font-semibold text-primary-foreground shadow-sm transition hover:bg-primary/90"><Download class="size-4" /> Descargar</a>
                    </div>
                </div>

                <div v-if="missing" class="flex flex-1 flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-destructive/30 bg-destructive/5 p-8 text-center">
                    <FileWarning class="size-8 text-destructive" />
                    <p class="text-sm font-bold text-foreground">No encontramos el PDF de {{ document.folio }} en el almacenamiento.</p>
                    <p class="max-w-md text-xs text-muted-foreground">El registro y su folio se conservan. Proporciona el folio a Sistemas para restaurar el archivo; no vuelvas a emitir el documento.</p>
                    <Button variant="outline" size="sm" class="mt-1 rounded-lg" @click="open = false">Cerrar</Button>
                </div>

                <div v-else class="relative min-h-0 flex-1 overflow-hidden rounded-xl border border-border bg-muted/30">
                    <div v-if="!loaded" class="absolute inset-0 flex flex-col gap-3 p-6">
                        <div class="h-6 w-1/3 app-skeleton rounded bg-muted" />
                        <div class="h-4 w-2/3 app-skeleton rounded bg-muted" />
                        <div class="flex-1 app-skeleton rounded-lg bg-muted" />
                    </div>
                    <iframe :key="document.view_url" :src="document.view_url" :title="`${title} ${document.folio}`" class="size-full bg-white" @load="loaded = true" />
                </div>
            </template>
        </DialogContent>
    </Dialog>
</template>
