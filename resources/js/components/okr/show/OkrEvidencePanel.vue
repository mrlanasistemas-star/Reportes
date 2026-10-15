<script setup lang="ts">
// Evidencias y check-ins (8.10 §7, 19-oct-2026): lo que respalda el
// seguimiento semana a semana. Compacto: las 5 evidencias y 4 check-ins más
// recientes, con acceso directo para subir/registrar.
import { ClipboardCheck, Download, FolderOpen, Upload } from 'lucide-vue-next'
import { Button } from '@/components/ui/button'
import { formatFriendlyDate } from '@/lib/okrFormat'

defineProps<{ objective: any; evidences: any[]; checkIns: any[]; canUploadEvidence: boolean; canCheckin: boolean }>()
const emit = defineEmits<{ evidence: []; checkin: [] }>()
</script>

<template>
    <section class="rounded-2xl border border-border bg-card shadow-sm">
        <header class="flex items-center gap-2 border-b border-border px-4 py-3">
            <span class="flex size-9 items-center justify-center rounded-xl bg-sky-500/10 text-sky-600 dark:text-sky-300"><FolderOpen class="size-4" /></span>
            <div>
                <p class="text-sm font-bold text-foreground">Evidencias y check-ins</p>
                <p class="text-[11px] text-muted-foreground">Respaldo del seguimiento semanal.</p>
            </div>
        </header>
        <div class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-2">
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold text-foreground">Evidencias <span class="font-normal text-muted-foreground">({{ evidences.length }})</span></p>
                    <Button v-if="canUploadEvidence" size="sm" variant="outline" class="h-8 gap-1 rounded-lg text-xs" @click="emit('evidence')"><Upload class="size-3.5" /> Subir evidencia</Button>
                </div>
                <a v-for="e in evidences.slice(0, 5)" :key="e.id" :href="`/okr/evidences/${e.id}/download`" class="flex items-center justify-between gap-2 rounded-lg border border-border px-3 py-2 text-xs transition hover:border-primary/40 hover:bg-muted/40">
                    <span class="min-w-0">
                        <span class="block truncate font-semibold text-foreground">{{ e.original_name }}</span>
                        <span class="block text-[11px] text-muted-foreground">{{ e.week_number ? `Semana ${e.week_number} · ` : '' }}{{ e.uploader }} · {{ formatFriendlyDate(e.created_at?.slice(0, 10)) }}</span>
                    </span>
                    <Download class="size-3.5 shrink-0 text-primary" />
                </a>
                <p v-if="!evidences.length" class="rounded-lg border border-dashed border-border px-3 py-3 text-xs text-muted-foreground">Sin evidencias todavía.</p>
                <p v-else-if="evidences.length > 5" class="text-[11px] text-muted-foreground">+{{ evidences.length - 5 }} evidencia(s) anteriores.</p>
            </div>
            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-xs font-bold text-foreground">Check-ins <span class="font-normal text-muted-foreground">({{ checkIns.length }})</span></p>
                    <Button v-if="canCheckin && objective.lifecycle_status === 'active'" size="sm" variant="outline" class="h-8 gap-1 rounded-lg text-xs" @click="emit('checkin')"><ClipboardCheck class="size-3.5" /> Registrar seguimiento</Button>
                </div>
                <div v-for="c in checkIns.slice(0, 4)" :key="c.id" class="rounded-lg border border-border px-3 py-2 text-xs">
                    <p class="font-semibold text-foreground">Semana {{ c.week_number }} · {{ c.user }} <span class="font-normal text-muted-foreground">· {{ formatFriendlyDate(c.check_in_date) }}</span></p>
                    <p v-if="c.main_blocker" class="text-muted-foreground">Bloqueo: {{ c.main_blocker }}</p>
                    <p v-if="c.corrective_action" class="text-muted-foreground">Acción: {{ c.corrective_action }}</p>
                </div>
                <p v-if="!checkIns.length" class="rounded-lg border border-dashed border-border px-3 py-3 text-xs text-muted-foreground">Sin check-ins todavía.</p>
            </div>
        </div>
    </section>
</template>
