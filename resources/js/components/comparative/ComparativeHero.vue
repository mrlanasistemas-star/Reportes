<script setup lang="ts">
import { Link } from '@inertiajs/vue3'
import { FileSpreadsheet, FileText, ArrowLeft } from 'lucide-vue-next'

defineProps<{
    labelA: string
    labelB: string
    scopeLabel: string
    excelUrl: string | null
    pdfUrl: string | null
    canExport: boolean
    backUrl: string
}>()
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
                <a :href="canExport ? excelUrl ?? '#' : '#'"
                   :class="canExport ? 'bg-emerald-600 hover:bg-emerald-500' : 'bg-slate-300 dark:bg-slate-700 pointer-events-none opacity-50'"
                   class="inline-flex h-10 items-center gap-2 rounded-xl px-4 text-sm font-bold text-white transition">
                    <FileSpreadsheet class="size-4" /> Excel
                </a>
                <a :href="canExport ? pdfUrl ?? '#' : '#'"
                   :class="canExport ? 'bg-rose-600 hover:bg-rose-500' : 'bg-slate-300 dark:bg-slate-700 pointer-events-none opacity-50'"
                   class="inline-flex h-10 items-center gap-2 rounded-xl px-4 text-sm font-bold text-white transition">
                    <FileText class="size-4" /> PDF
                </a>
            </div>
        </div>
    </div>
</template>
