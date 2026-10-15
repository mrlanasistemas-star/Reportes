<script setup lang="ts">
import { FileSpreadsheet, FolderClock, Info } from 'lucide-vue-next'
import type { LaborRosterSummary } from '@/types/asignaciones'

defineProps<{
    roster: LaborRosterSummary
}>()
</script>

<template>
    <div class="grid gap-2 text-xs md:grid-cols-3">
        <div class="flex items-start gap-2 rounded-xl border border-border bg-muted/30 px-3 py-2.5">
            <FileSpreadsheet class="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
            <div class="min-w-0">
                <p class="font-semibold text-foreground">Presencia: nómina NOI</p>
                <p class="mt-0.5 text-muted-foreground">
                    {{ roster.fuentes.ambos }} en normal y fiscal · {{ roster.fuentes.solo_normal }} solo normal · {{ roster.fuentes.solo_fiscal }} solo fiscal.
                    Cada persona cuenta una vez.
                </p>
            </div>
        </div>
        <div class="flex items-start gap-2 rounded-xl border border-border bg-muted/30 px-3 py-2.5">
            <FolderClock class="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
            <div class="min-w-0">
                <p class="font-semibold text-foreground">Vigencia: directorio laboral</p>
                <p class="mt-0.5 break-words text-muted-foreground">
                    <template v-if="roster.directorio?.vigente">{{ roster.directorio.vigente }}</template>
                    <template v-else>Sin directorio con corte válido para este periodo: ninguna ausencia se confirma como baja por directorio.</template>
                </p>
            </div>
        </div>
        <div class="flex items-start gap-2 rounded-xl border border-border bg-muted/30 px-3 py-2.5">
            <Info class="mt-0.5 size-3.5 shrink-0 text-muted-foreground" />
            <div class="min-w-0">
                <p class="font-semibold text-foreground">Evidencia posterior (solo informativa)</p>
                <p class="mt-0.5 break-words text-muted-foreground">
                    <template v-if="roster.directorio?.posterior">{{ roster.directorio.posterior }}. Un corte posterior no confirma bajas de este periodo.</template>
                    <template v-else>No hay directorios con corte posterior al periodo.</template>
                </p>
            </div>
        </div>
    </div>
</template>
