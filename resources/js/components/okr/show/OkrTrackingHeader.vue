<script setup lang="ts">
// Encabezado de "Seguimiento OKR" (15-oct-2026): qué se está viendo
// (sucursal o gestor), periodo, estado, semanas, responsable, y las 3 acciones
// principales visibles; el resto en un menú secundario.
import { Link } from '@inertiajs/vue3'
import {
    ArrowLeft, CalendarRange, ClipboardCheck, FileSignature, History, MoreHorizontal, PencilLine, RefreshCw, Rocket, Scale, Settings2, ShieldAlert, Trash2, Upload, UserRound,
} from 'lucide-vue-next'
import OkrStatusBadge from '@/components/okr/OkrStatusBadge.vue'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { formatFriendlyDate } from '@/lib/okrFormat'

const props = defineProps<{
    objective: any
    canAssign: boolean
    canUpdate: boolean
    canManage: boolean
    canCheckin: boolean
    canUploadPlacement: boolean
}>()
const emit = defineEmits<{ letter: []; upload: []; checkin: []; activate: []; edit: []; weights: []; refresh: []; destroy: []; warning: [] }>()

const isBranch = props.objective.scope_type === 'branch'
const progressPct = Math.min(100, Math.round(((props.objective.current_week ?? 0) / Math.max(1, props.objective.duration_weeks)) * 100))
</script>

<template>
    <section class="relative rounded-2xl border bg-card px-5 py-5 shadow-soft-xs sm:px-6">

        <div class="relative flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 space-y-2">
                <Link href="/okr" class="inline-flex items-center gap-1 text-xs font-semibold text-muted-foreground transition hover:text-primary"><ArrowLeft class="size-3.5" aria-hidden="true" /> Dashboard OKR</Link>
                <h1 class="app-title">
                    Seguimiento OKR — {{ isBranch ? (objective.branch?.name ?? 'Sucursal') : (objective.employee?.full_name ?? 'Colaborador') }}
                </h1>
                <p class="app-subtitle max-w-3xl">{{ objective.title }}</p>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="rounded-full bg-muted px-2.5 py-1 font-semibold text-foreground ring-1 ring-border">{{ isBranch ? 'Sucursal completa' : 'Gestor individual' }}</span>
                    <OkrStatusBadge kind="lifecycle" :value="objective.lifecycle_status" />
                    <OkrStatusBadge v-if="objective.health_status" kind="health" :value="objective.health_status" />
                    <span class="flex items-center gap-1 text-muted-foreground"><CalendarRange class="size-3.5" /> {{ formatFriendlyDate(objective.start_date) }} – {{ formatFriendlyDate(objective.end_date) }}</span>
                    <!-- Evaluado (con SU puesto) y responsable del seguimiento, nunca mezclados. -->
                    <span v-if="!isBranch && objective.subject?.full_name" class="flex items-center gap-1 font-semibold text-foreground" title="Colaborador evaluado por este OKR."><UserRound class="size-3.5" /> Evaluado: {{ objective.subject.full_name }}<template v-if="objective.subject?.position"> · {{ objective.subject.position }}</template></span>
                    <span class="text-muted-foreground" title="Usuario del sistema que administra el seguimiento; no es la persona evaluada.">Seguimiento a cargo de: {{ objective.responsible?.name ?? "—" }}</span>
                </div>
                <div class="flex max-w-md items-center gap-2 pt-1">
                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted"><div class="h-full rounded-full bg-primary transition-all duration-700" :style="{ width: `${progressPct}%` }" /></div>
                    <span class="text-[11px] font-semibold tabular-nums text-muted-foreground">Semana {{ objective.current_week || 0 }} de {{ objective.duration_weeks }}</span>
                </div>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <Button v-if="objective.lifecycle_status === 'draft' && canAssign" class="h-10 gap-2 rounded-lg" @click="emit('activate')">
                    <Rocket class="size-4" /> Activar OKR
                </Button>
                <Button v-if="objective.lifecycle_status === 'draft' && canUpdate" variant="outline" class="h-10 gap-2 rounded-lg" @click="emit('edit')"><Settings2 class="size-4" /> Configurar KR</Button>
                <Button variant="outline" class="h-10 gap-2 rounded-lg" @click="emit('letter')"><FileSignature class="size-4" /> Revisar carta</Button>
                <Button v-if="canUploadPlacement" variant="outline" class="h-10 gap-2 rounded-lg" @click="emit('upload')"><Upload class="size-4" /> Cargar colocación</Button>
                <Button v-if="objective.lifecycle_status === 'active' || objective.lifecycle_status === 'closed'" variant="outline" class="h-10 gap-2 rounded-lg" @click="emit('warning')"><ShieldAlert class="size-4" /> Evaluar Warning</Button>
                <Button v-if="canCheckin && objective.lifecycle_status === 'active'" variant="outline" class="h-10 gap-2 rounded-lg" @click="emit('checkin')"><ClipboardCheck class="size-4" /> Registrar seguimiento</Button>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="icon" class="size-10 rounded-lg" aria-label="Más acciones"><MoreHorizontal class="size-4" /></Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <DropdownMenuItem v-if="canUpdate && objective.lifecycle_status !== 'closed' && objective.lifecycle_status !== 'cancelled'" @click="emit('edit')"><PencilLine class="size-4" /> {{ objective.lifecycle_status === 'draft' ? 'Configurar Key Results' : 'Editar meta' }}</DropdownMenuItem>
                        <DropdownMenuItem v-if="canUpdate && objective.lifecycle_status === 'active'" @click="emit('weights')"><Scale class="size-4" /> Redistribuir ponderaciones</DropdownMenuItem>
                        <DropdownMenuItem v-if="canUpdate && objective.lifecycle_status === 'active'" @click="emit('refresh')"><RefreshCw class="size-4" /> Recalcular con Reportería</DropdownMenuItem>
                        <DropdownMenuItem as-child><Link href="/okr/history" class="flex w-full items-center gap-2"><History class="size-4" /> Histórico</Link></DropdownMenuItem>
                        <template v-if="canManage && objective.lifecycle_status !== 'cancelled'">
                            <DropdownMenuSeparator />
                            <DropdownMenuItem class="text-destructive focus:text-destructive" @click="emit('destroy')"><Trash2 class="size-4" /> Eliminar OKR</DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    </section>
</template>
