<script setup lang="ts">
/**
 * ComparativeHero — encabezado del comparativo financiero entre dos periodos.
 *
 * Se usa en pages/ReportesMensuales/ComparativePreview. Muestra el enlace de
 * regreso, el título "Periodo A vs Periodo B", el alcance y los botones para
 * descargar el comparativo en Excel y PDF.
 *
 * Props:
 * - labelA / labelB: nombres de los periodos comparados (A = actual, B = comparado).
 * - scopeLabel: descripción del alcance (General, sucursal o gestor).
 * - excelUrl / pdfUrl: rutas de exportación (null = no disponible).
 * - canExport: habilita o deshabilita los botones de descarga.
 * - backUrl: ruta para volver a Reportes mensuales.
 */
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, FileSpreadsheet, FileText, Loader2 } from 'lucide-vue-next';
import { ref } from 'vue';
import { useToast } from '@/composables/useToast';

defineProps<{
    labelA: string;
    labelB: string;
    scopeLabel: string;
    excelUrl: string | null;
    pdfUrl: string | null;
    canExport: boolean;
    backUrl: string;
}>();

/** Notificaciones tipo toast para informar fallos de descarga. */
const toast = useToast();
/** true mientras se descarga el Excel. */
const downloadingExcel = ref(false);
/** true mientras se descarga el PDF. */
const downloadingPdf = ref(false);

/**
 * Descarga por fetch/blob en vez de navegación `<a href>` (retoma 21-sep-2026,
 * punto 10) — un `<a href>` normal, si el backend responde 500 (texto plano, no
 * archivo), hace que el navegador NAVEGUE a esa respuesta y reemplace toda la
 * página del comparativo por el mensaje de error crudo. Con fetch(), un fallo
 * de export SOLO muestra un toast — el comparativo (que es un proceso
 * completamente distinto, ver comparativoData()) nunca desaparece de pantalla.
 *
 * @param url Ruta de exportación; si es null no hace nada.
 * @param kind Tipo de archivo a descargar ('excel' o 'pdf').
 */
async function downloadFile(url: string | null, kind: 'excel' | 'pdf') {
    if (!url) {
        return;
    }

    const busy = kind === 'excel' ? downloadingExcel : downloadingPdf;
    const label = kind === 'excel' ? 'Excel' : 'PDF';
    busy.value = true;

    try {
        const resp = await fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });

        if (!resp.ok) {
            const text = await resp.text().catch(() => '');
            toast.error(
                text?.trim().slice(0, 200) ||
                    `No se pudo generar el ${label}. El comparativo sigue disponible.`,
            );

            return;
        }

        const blob = await resp.blob();
        const disposition = resp.headers.get('Content-Disposition') ?? '';
        const match = /filename="?([^"]+)"?/i.exec(disposition);
        const filename =
            match?.[1] ?? `comparativo.${kind === 'excel' ? 'xlsx' : 'pdf'}`;

        const blobUrl = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = blobUrl;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        // Se libera después: revocar en el mismo tick puede cancelar la descarga en
        // algunos navegadores (mismo criterio que DashboardExportActions).
        setTimeout(() => URL.revokeObjectURL(blobUrl), 10_000);
    } catch {
        toast.error(
            `No se pudo descargar el ${label}. El comparativo sigue disponible.`,
        );
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="rounded-2xl border bg-card p-5 shadow-soft-xs sm:p-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <Link
                    :href="backUrl"
                    class="mb-2 inline-flex items-center gap-1.5 text-xs font-bold text-muted-foreground hover:text-primary"
                >
                    <ArrowLeft class="size-3.5" /> Volver a Reportes mensuales
                </Link>
                <p class="app-kicker">Comparativo financiero</p>
                <h1 class="app-title mt-1">
                    {{ labelA }}
                    <span class="font-medium text-muted-foreground">vs</span>
                    {{ labelB }}
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Analiza variaciones financieras entre periodos. Alcance:
                    <span class="font-semibold text-foreground/80">{{
                        scopeLabel
                    }}</span>
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
                <button
                    type="button"
                    :disabled="!canExport || downloadingExcel"
                    @click="downloadFile(excelUrl, 'excel')"
                    :class="
                        canExport && !downloadingExcel
                            ? 'bg-primary text-primary-foreground hover:bg-primary/90'
                            : 'cursor-not-allowed bg-muted text-muted-foreground'
                    "
                    class="inline-flex h-10 items-center gap-2 rounded-lg px-4 text-sm font-semibold transition"
                >
                    <Loader2
                        v-if="downloadingExcel"
                        class="size-4 animate-spin"
                    />
                    <FileSpreadsheet v-else class="size-4" />
                    {{ downloadingExcel ? 'Descargando…' : 'Descargar Excel' }}
                </button>
                <button
                    type="button"
                    :disabled="!canExport || downloadingPdf"
                    @click="downloadFile(pdfUrl, 'pdf')"
                    :class="
                        canExport && !downloadingPdf
                            ? 'bg-card text-foreground hover:bg-muted'
                            : 'cursor-not-allowed bg-muted text-muted-foreground'
                    "
                    class="inline-flex h-10 items-center gap-2 rounded-lg border border-border px-4 text-sm font-semibold transition"
                >
                    <Loader2
                        v-if="downloadingPdf"
                        class="size-4 animate-spin"
                    />
                    <FileText v-else class="size-4 text-danger" />
                    {{ downloadingPdf ? 'Descargando…' : 'Descargar PDF' }}
                </button>
            </div>
        </div>
    </div>
</template>
