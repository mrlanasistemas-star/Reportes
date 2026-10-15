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
    <section class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-950 via-indigo-950 to-slate-900 px-5 py-5 text-white shadow-lg sm:px-7">
        <div class="pointer-events-none absolute -right-20 -top-24 size-64 rounded-full bg-indigo-500/20 blur-3xl" />
        <div class="pointer-events-none absolute -bottom-24 left-1/4 size-56 rounded-full bg-emerald-500/10 blur-3xl" />

        <div class="relative flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0 space-y-2">
                <Link href="/okr" class="inline-flex items-center gap-1 text-[11px] font-semibold text-indigo-200/80 transition hover:text-white"><ArrowLeft class="size-3.5" /> Dashboard OKR</Link>
                <h1 class="text-2xl font-bold tracking-tight sm:text-[28px]">
                    Seguimiento OKR — {{ isBranch ? (objective.branch?.name ?? 'Sucursal') : (objective.employee?.full_name ?? 'Colaborador') }}
                </h1>
                <p class="max-w-3xl text-sm text-slate-300">{{ objective.title }}</p>
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="rounded-full bg-white/10 px-2.5 py-1 font-semibold ring-1 ring-white/15">{{ isBranch ? 'Sucursal completa' : 'Gestor individual' }}</span>
                    <OkrStatusBadge kind="lifecycle" :value="objective.lifecycle_status" />
                    <OkrStatusBadge v-if="objective.health_status" kind="health" :value="objective.health_status" />
                    <span class="flex items-center gap-1 text-slate-300"><CalendarRange class="size-3.5" /> {{ formatFriendlyDate(objective.start_date) }} – {{ formatFriendlyDate(objective.end_date) }}</span>
                    <!-- Evaluado (con SU puesto) y responsable del seguimiento, nunca mezclados. -->
                    <span v-if="!isBranch && objective.subject?.full_name" class="flex items-center gap-1 font-semibold text-white" title="Colaborador evaluado por este OKR."><UserRound class="size-3.5" /> Evaluado: {{ objective.subject.full_name }}<template v-if="objective.subject?.position"> · {{ objective.subject.position }}</template></span>
                    <span class="text-slate-300" title="Usuario del sistema que administra el seguimiento; no es la persona evaluada.">Seguimiento a cargo de: {{ objective.responsible?.name ?? "—" }}</span>
                </div>
                <div class="flex max-w-md items-center gap-2 pt-1">
                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-white/10"><div class="h-full rounded-full bg-gradient-to-r from-indigo-400 to-emerald-400 transition-all duration-700" :style="{ width: `${progressPct}%` }" /></div>
                    <span class="text-[11px] font-semibold tabular-nums text-slate-300">Semana {{ objective.current_week || 0 }} de {{ objective.duration_weeks }}</span>
                </div>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <Button v-if="objective.lifecycle_status === 'draft' && canAssign" class="h-10 gap-2 rounded-xl bg-emerald-500 text-white shadow-lg shadow-emerald-500/30 hover:bg-emerald-400" @click="emit('activate')">
                    <Rocket class="size-4" /> Activar OKR
                </Button>
                <Button v-if="objective.lifecycle_status === 'draft' && canUpdate" variant="outline" class="h-10 gap-2 rounded-xl border-white/20 bg-white/5 text-white hover:bg-white/15 hover:text-white" @click="emit('edit')"><Settings2 class="size-4" /> Configurar KR</Button>
                <Button class="h-10 gap-2 rounded-xl bg-white text-slate-900 hover:bg-indigo-50" @click="emit('letter')"><FileSignature class="size-4" /> Revisar carta</Button>
                <Button v-if="canUploadPlacement" variant="outline" class="h-10 gap-2 rounded-xl border-white/20 bg-white/5 text-white hover:bg-white/15 hover:text-white" @click="emit('upload')"><Upload class="size-4" /> Cargar colocación</Button>
                <Button v-if="objective.lifecycle_status === 'active' || objective.lifecycle_status === 'closed'" variant="outline" class="h-10 gap-2 rounded-xl border-white/20 bg-white/5 text-white hover:bg-white/15 hover:text-white" @click="emit('warning')"><ShieldAlert class="size-4" /> Evaluar Warning</Button>
                <Button v-if="canCheckin && objective.lifecycle_status === 'active'" variant="outline" class="h-10 gap-2 rounded-xl border-white/20 bg-white/5 text-white hover:bg-white/15 hover:text-white" @click="emit('checkin')"><ClipboardCheck class="size-4" /> Registrar seguimiento</Button>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="icon" class="size-10 rounded-xl border-white/20 bg-white/5 text-white hover:bg-white/15 hover:text-white" aria-label="Más acciones"><MoreHorizontal class="size-4" /></Button>
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
