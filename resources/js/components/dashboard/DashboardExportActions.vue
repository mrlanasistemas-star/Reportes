<script setup lang="ts">
import { FileSpreadsheet, FileText, LoaderCircle } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { ref } from 'vue'
import { buttonVariants } from '@/components/ui/button'
import { hideDownloadLoading, showDownloadLoading } from '@/lib/downloadOverlay'
import { cn } from '@/lib/utils'

// Botones "Exportar PDF / Excel" del Dashboard (Fase 7.2/7.3/8.5). Descarga vía
// fetch con spinner en el botón y, desde el 27-oct-2026, también la pantalla de
// carga de descargas (downloadOverlay) — mismo feedback que cualquier otro PDF/Excel
// del sistema. Muestra el mensaje real del servidor si algo falla (403 de alcance,
// periodo sin radiografía, referencia de error), en vez de abrir una pestaña con HTML.
const props = defineProps<{
    pdfUrl: string
    excelUrl: string
    scopeLabel: string
    disabled?: boolean
}>()

type Kind = 'pdf' | 'excel'
const busy = ref<Kind | null>(null)

function filenameFrom(resp: Response, fallback: string): string {
    const cd = resp.headers.get('content-disposition') ?? ''
    const star = /filename\*=UTF-8''([^;]+)/i.exec(cd)

    if (star) {
        return decodeURIComponent(star[1])
    }

    const plain = /filename="?([^";]+)"?/i.exec(cd)

    return plain ? plain[1] : fallback
}

function messageFromHtml(html: string): string {
    const text = new DOMParser().parseFromString(html, 'text/html').body.textContent ?? ''

    return text.replace(/\s+/g, ' ').trim().slice(0, 400)
}

async function download(kind: Kind) {
    if (busy.value) {
        return
    }

    busy.value = kind
    const url = kind === 'pdf' ? props.pdfUrl : props.excelUrl
    showDownloadLoading(kind)

    try {
        const resp = await fetch(url, { credentials: 'same-origin', headers: { Accept: kind === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' } })

        if (!resp.ok) {
            const body = await resp.text()
            let message = ''

            try {
                message = JSON.parse(body)?.message ?? ''
            } catch {
                message = messageFromHtml(body)
            }

            throw new Error(message || `El servidor respondió ${resp.status}.`)
        }

        const blob = await resp.blob()
        const href = URL.createObjectURL(blob)
        const a = document.createElement('a')
        a.href = href
        a.download = filenameFrom(resp, kind === 'pdf' ? 'dashboard.pdf' : 'dashboard.xlsx')
        document.body.appendChild(a)
        a.click()
        a.remove()
        setTimeout(() => URL.revokeObjectURL(href), 10_000)
    } catch (e) {
        Swal.fire({
            icon: 'error',
            title: kind === 'pdf' ? 'No se pudo generar el PDF' : 'No se pudo generar el Excel',
            text: e instanceof TypeError
                ? 'Sin conexión con el servidor. Revisa tu red e intenta de nuevo.'
                : (e as Error)?.message || 'Intenta nuevamente en unos segundos.',
            confirmButtonText: 'Entendido',
        })
    } finally {
        hideDownloadLoading()
        busy.value = null
    }
}

const btn = cn(buttonVariants({ variant: 'outline', size: 'lg' }), 'h-10 rounded-xl px-3.5 font-bold')
</script>

<template>
    <div class="flex items-center gap-2" role="group" :aria-label="`Exportar el Dashboard de ${scopeLabel}`">
        <button
            type="button"
            :class="[btn, 'hover:border-danger-border hover:text-danger']"
            :disabled="disabled || busy !== null"
            :aria-busy="busy === 'pdf'"
            :title="`PDF ejecutivo — ${scopeLabel}`"
            @click="download('pdf')"
        >
            <LoaderCircle v-if="busy === 'pdf'" class="size-4 animate-spin" aria-hidden="true" />
            <FileText v-else class="size-4" aria-hidden="true" />
            {{ busy === 'pdf' ? 'Generando PDF…' : 'Exportar PDF' }}
        </button>
        <button
            type="button"
            :class="[btn, 'hover:border-success-border hover:text-success']"
            :disabled="disabled || busy !== null"
            :aria-busy="busy === 'excel'"
            :title="`Excel con resumen y desglose — ${scopeLabel}`"
            @click="download('excel')"
        >
            <LoaderCircle v-if="busy === 'excel'" class="size-4 animate-spin" aria-hidden="true" />
            <FileSpreadsheet v-else class="size-4" aria-hidden="true" />
            {{ busy === 'excel' ? 'Generando Excel…' : 'Exportar Excel' }}
        </button>
    </div>
</template>
