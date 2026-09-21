<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { ArrowLeft, FileSpreadsheet, FileText, Loader2 } from 'lucide-vue-next'
import { ref } from 'vue'
import { useToast } from '@/composables/useToast'

defineProps<{
    labelA: string
    labelB: string
    scopeLabel: string
    excelUrl: string | null
    pdfUrl: string | null
    canExport: boolean
    backUrl: string
}>()

const toast = useToast()
const downloadingExcel = ref(false)
const downloadingPdf = ref(false)

/**
 * Descarga por fetch/blob en vez de navegación `<a href>` (retoma 21-sep-2026,
 * punto 10) — un `<a href>` normal, si el backend responde 500 (texto plano, no
 * archivo), hace que el navegador NAVEGUE a esa respuesta y reemplace toda la
 * página del comparativo por el mensaje de error crudo. Con fetch(), un fallo
 * de export SOLO muestra un toast — el comparativo (que es un proceso
 * completamente distinto, ver comparativoData()) nunca desaparece de pantalla.
 */
async function downloadFile(url: string | null, kind: 'excel' | 'pdf') {
    if (!url) {
        return
    }

    const busy = kind === 'excel' ? downloadingExcel : downloadingPdf
    const label = kind === 'excel' ? 'Excel' : 'PDF'
    busy.value = true

    try {
        const resp = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })

        if (!resp.ok) {
            const text = await resp.text().catch(() => '')
            toast.error(text?.trim().slice(0, 200) || `No se pudo generar el ${label}. El comparativo sigue disponible.`)

            return
        }

        const blob = await resp.blob()
        const disposition = resp.headers.get('Content-Disposition') ?? ''
        const match = /filename="?([^"]+)"?/i.exec(disposition)
        const filename = match?.[1] ?? `comparativo.${kind === 'excel' ? 'xlsx' : 'pdf'}`

        const blobUrl = URL.createObjectURL(blob)
        const link = document.createElement('a')
        link.href = blobUrl
        link.download = filename
        document.body.appendChild(link)
        link.click()
        link.remove()
        URL.revokeObjectURL(blobUrl)
    } catch {
        toast.error(`No se pudo descargar el ${label}. El comparativo sigue disponible.`)
    } finally {
        busy.value = false
    }
}
</script>

<template>
    <div class="rounded-2xl border bg-white p-6 shadow-sm dark:bg-card">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <Link :href="backUrl" class="mb-2 inline-flex items-center gap-1.5 text-xs font-bold text-slate-500 hover:text-indigo-600 dark:text-slate-400 dark:hover:text-indigo-400">
                    <ArrowLeft class="size-3.5" /> Volver a Reportes mensuales
                </Link>
                <p class="text-xs font-black uppercase tracking-widest text-indigo-600 dark:text-indigo-400">Comparativo financiero</p>
                <h1 class="mt-1 text-2xl font-black text-slate-950 dark:text-slate-50">
                    {{ labelA }} <span class="font-medium text-slate-400 dark:text-slate-500">vs</span> {{ labelB }}
                </h1>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Analiza variaciones financieras entre periodos. Alcance: <span class="font-semibold text-slate-700 dark:text-slate-200">{{ scopeLabel }}</span></p>
            </div>
            <div class="flex shrink-0 gap-2">
                <button type="button" :disabled="!canExport || downloadingExcel" @click="downloadFile(excelUrl, 'excel')"
                   :class="canExport && !downloadingExcel ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-slate-300 dark:bg-slate-700 opacity-60 cursor-not-allowed'"
                   class="inline-flex h-10 items-center gap-2 rounded-xl px-4 text-sm font-bold text-white transition">
                    <Loader2 v-if="downloadingExcel" class="size-4 animate-spin" />
                    <FileSpreadsheet v-else class="size-4" /> Excel
                </button>
                <button type="button" :disabled="!canExport || downloadingPdf" @click="downloadFile(pdfUrl, 'pdf')"
                   :class="canExport && !downloadingPdf ? 'bg-rose-600 hover:bg-rose-500' : 'bg-slate-300 dark:bg-slate-700 opacity-60 cursor-not-allowed'"
                   class="inline-flex h-10 items-center gap-2 rounded-xl px-4 text-sm font-bold text-white transition">
                    <Loader2 v-if="downloadingPdf" class="size-4 animate-spin" />
                    <FileText v-else class="size-4" /> PDF
                </button>
            </div>
        </div>
    </div>
</template>
