<script setup lang="ts">
// Usuarios → Importar usuarios (solo administradores). Flujo controlado en 3
// pasos: subir el directorio → previsualizar y confirmar el lote → resultado +
// descarga ÚNICA del reporte de credenciales. Nada se crea hasta confirmar; la
// importación es idempotente (el backend no recrea cuentas existentes) y las
// contraseñas NUNCA se muestran en pantalla (solo viajan en el archivo descargable).
import { useForm } from '@inertiajs/vue3'
import { AlertTriangle, CheckCircle2, Download, FileSpreadsheet, Loader2, ShieldAlert, Upload, UserPlus } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog'

type PreviewRow = {
    noi: string | null
    name: string
    branch_name: string
    branch_id: number | null
    position: string
    email: string
    role: string
    status: 'create' | 'needs_review' | 'exists' | 'error'
    observations: string[]
}
type ImportPreview = { environment: string; original_name: string; summary: Record<string, number>; rows: PreviewRow[] }
type ResultRow = { name: string; branch: string; position: string; email: string; creation: string; access: string; invitation: string; observations: string }
type ImportResult = { summary: Record<string, number>; rows: ResultRow[]; has_credentials: boolean }

const props = defineProps<{
    urls: { preview: string; confirm: string; credentials: string }
    environment: string
    preview: ImportPreview | null
    result: ImportResult | null
    canInvite: boolean
}>()
const open = defineModel<boolean>('open', { default: false })

const step = computed<'upload' | 'preview' | 'result'>(() => (props.result ? 'result' : props.preview ? 'preview' : 'upload'))

const STATUS_META: Record<PreviewRow['status'], { label: string; tone: string; selectable: boolean }> = {
    create: { label: 'Nueva', tone: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300', selectable: true },
    needs_review: { label: 'Revisar', tone: 'bg-amber-500/10 text-amber-700 dark:text-amber-300', selectable: true },
    exists: { label: 'Ya existe', tone: 'bg-sky-500/10 text-sky-700 dark:text-sky-300', selectable: false },
    error: { label: 'Error', tone: 'bg-rose-500/10 text-rose-700 dark:text-rose-300', selectable: false },
}

const uploadForm = useForm({ file: null as File | null })
const confirmForm = useForm({ emails: [] as string[], confirmed: false, send_invitations: false })
const selected = ref<Set<string>>(new Set())
const fileName = ref('')

// Al llegar una vista previa, se preseleccionan las cuentas creables (nueva/revisar).
watch(() => props.preview, (p) => {
    if (p) {
        selected.value = new Set(p.rows.filter((r) => STATUS_META[r.status].selectable).map((r) => r.email.toLowerCase()))
        confirmForm.confirmed = false
    }
}, { immediate: true })

const selectableRows = computed(() => props.preview?.rows.filter((r) => STATUS_META[r.status].selectable) ?? [])
const allSelected = computed(() => selectableRows.value.length > 0 && selectableRows.value.every((r) => selected.value.has(r.email.toLowerCase())))

function toggleRow(email: string) {
    const key = email.toLowerCase()
    const next = new Set(selected.value)
    next.has(key) ? next.delete(key) : next.add(key)
    selected.value = next
}
function toggleAll() {
    selected.value = allSelected.value ? new Set() : new Set(selectableRows.value.map((r) => r.email.toLowerCase()))
}

function onFile(e: Event) {
    const f = (e.target as HTMLInputElement).files?.[0] ?? null
    uploadForm.file = f
    fileName.value = f?.name ?? ''
}

function submitUpload() {
    if (!uploadForm.file) {
        return
    }
    uploadForm.post(props.urls.preview, { forceFormData: true, preserveScroll: true, preserveState: true })
}

function submitConfirm() {
    confirmForm.emails = [...selected.value]
    confirmForm.post(props.urls.confirm, { preserveScroll: true, preserveState: true })
}

function downloadCredentials() {
    window.location.href = props.urls.credentials
}

function close() {
    open.value = false
}

const isProd = computed(() => props.environment === 'production')
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92vh] w-[96vw] max-w-4xl overflow-y-auto p-0 sm:max-w-4xl">
            <div class="border-b border-border bg-card px-6 py-5">
                <DialogTitle class="flex items-center gap-2 text-lg font-semibold text-foreground">
                    <FileSpreadsheet class="size-5 text-primary" /> Importar usuarios del directorio de gerencias
                </DialogTitle>
                <DialogDescription class="mt-1 text-sm text-muted-foreground">
                    Sube el Excel, revisa la vista previa y confirma el lote. No se crea nada hasta confirmar; las cuentas que ya existen no se modifican.
                </DialogDescription>
            </div>

            <!-- Entorno -->
            <div class="px-6 pt-4">
                <div class="flex items-center gap-2 rounded-xl border px-3 py-2 text-xs font-semibold" :class="isProd ? 'border-rose-300 bg-rose-500/10 text-rose-700 dark:text-rose-300' : 'border-amber-300 bg-amber-500/10 text-amber-700 dark:text-amber-300'">
                    <ShieldAlert class="size-4 shrink-0" />
                    Entorno: <span class="font-black uppercase">{{ environment }}</span>
                    <span class="font-normal">— confirma que es el correcto antes de crear cuentas reales.</span>
                </div>
            </div>

            <!-- PASO 1: subir -->
            <div v-if="step === 'upload'" class="space-y-4 p-6">
                <label class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-border bg-muted/30 px-6 py-10 text-center transition hover:border-primary/40">
                    <Upload class="size-7 text-muted-foreground" />
                    <span class="text-sm font-semibold text-foreground">{{ fileName || 'Selecciona el archivo .xlsx del directorio' }}</span>
                    <span class="text-xs text-muted-foreground">DIRECTORIO_GERENCIAS_MRLANA.xlsx — hoja «Directorio»</span>
                    <input type="file" accept=".xlsx,.xls" class="hidden" @change="onFile">
                </label>
                <p v-if="uploadForm.errors.file" class="app-field-error" role="alert">{{ uploadForm.errors.file }}</p>
                <div class="flex justify-end gap-2">
                    <Button type="button" variant="outline" class="h-10 rounded-xl" @click="close">Cancelar</Button>
                    <Button type="button" class="h-10 gap-1.5 rounded-xl" :disabled="!uploadForm.file || uploadForm.processing" @click="submitUpload">
                        <Loader2 v-if="uploadForm.processing" class="size-4 animate-spin" /> Leer y previsualizar
                    </Button>
                </div>
            </div>

            <!-- PASO 2: previsualizar -->
            <div v-else-if="step === 'preview' && preview" class="space-y-4 p-6">
                <div class="flex flex-wrap gap-2 text-xs font-semibold">
                    <span class="rounded-full bg-muted px-2.5 py-1">{{ preview.summary.total }} en el archivo</span>
                    <span class="rounded-full bg-emerald-500/10 px-2.5 py-1 text-emerald-700 dark:text-emerald-300">{{ preview.summary.create }} nuevas</span>
                    <span class="rounded-full bg-amber-500/10 px-2.5 py-1 text-amber-700 dark:text-amber-300">{{ preview.summary.review }} a revisar</span>
                    <span class="rounded-full bg-sky-500/10 px-2.5 py-1 text-sky-700 dark:text-sky-300">{{ preview.summary.exists }} ya existen</span>
                    <span class="rounded-full bg-rose-500/10 px-2.5 py-1 text-rose-700 dark:text-rose-300">{{ preview.summary.error }} con error</span>
                </div>

                <div class="overflow-x-auto rounded-xl border border-border">
                    <table class="w-full min-w-[720px] text-left text-xs">
                        <thead class="bg-muted/50 text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2"><input type="checkbox" class="accent-primary" :checked="allSelected" @change="toggleAll"></th>
                                <th class="px-3 py-2">Estado</th>
                                <th class="px-3 py-2">NOI</th>
                                <th class="px-3 py-2">Nombre</th>
                                <th class="px-3 py-2">Sucursal</th>
                                <th class="px-3 py-2">Puesto</th>
                                <th class="px-3 py-2">Correo</th>
                                <th class="px-3 py-2">Observaciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(r, i) in preview.rows" :key="i" class="border-t border-border align-top">
                                <td class="px-3 py-2">
                                    <input type="checkbox" class="accent-primary" :disabled="!STATUS_META[r.status].selectable" :checked="selected.has(r.email.toLowerCase())" @change="toggleRow(r.email)">
                                </td>
                                <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-[11px] font-bold" :class="STATUS_META[r.status].tone">{{ STATUS_META[r.status].label }}</span></td>
                                <td class="px-3 py-2 tabular-nums">{{ r.noi ?? '—' }}</td>
                                <td class="px-3 py-2 font-semibold text-foreground">{{ r.name }}</td>
                                <td class="px-3 py-2">{{ r.branch_name }}</td>
                                <td class="px-3 py-2">{{ r.position }}</td>
                                <td class="px-3 py-2 break-all">{{ r.email }}</td>
                                <td class="px-3 py-2 text-[11px] text-muted-foreground">{{ r.observations.join(' · ') || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="space-y-2 rounded-xl border border-border bg-muted/30 p-3">
                    <label class="flex cursor-pointer items-center gap-2 text-xs text-foreground">
                        <input v-model="confirmForm.send_invitations" type="checkbox" class="accent-primary" :disabled="!canInvite">
                        Enviar también el correo con credenciales a cada cuenta (solo si el SMTP ya está verificado)
                    </label>
                    <label class="flex cursor-pointer items-start gap-2 text-xs font-semibold text-foreground">
                        <input v-model="confirmForm.confirmed" type="checkbox" class="mt-0.5 accent-primary">
                        <span>Confirmo que el entorno (<span class="uppercase">{{ environment }}</span>) y el lote de <span class="tabular-nums">{{ selected.size }}</span> cuenta(s) son los definitivos.</span>
                    </label>
                </div>

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="outline" class="h-10 rounded-xl" @click="close">Cancelar</Button>
                    <Button type="button" class="h-10 gap-1.5 rounded-xl" :disabled="confirmForm.processing || !confirmForm.confirmed || selected.size === 0" @click="submitConfirm">
                        <Loader2 v-if="confirmForm.processing" class="size-4 animate-spin" /><UserPlus v-else class="size-4" />
                        Crear {{ selected.size }} cuenta(s)
                    </Button>
                </div>
            </div>

            <!-- PASO 3: resultado -->
            <div v-else-if="step === 'result' && result" class="space-y-4 p-6">
                <div class="flex items-center gap-2 rounded-xl border border-emerald-300 bg-emerald-500/10 px-4 py-3 text-sm font-semibold text-emerald-700 dark:text-emerald-300">
                    <CheckCircle2 class="size-5" />
                    {{ result.summary.created }} creadas · {{ result.summary.existing }} ya existían · {{ result.summary.invalid }} no creadas
                </div>

                <div v-if="result.has_credentials" class="flex flex-col gap-2 rounded-xl border border-border bg-muted/30 p-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-start gap-2 text-xs text-muted-foreground">
                        <AlertTriangle class="mt-0.5 size-4 shrink-0 text-amber-500" />
                        <span>El reporte con las contraseñas temporales se descarga <strong class="text-foreground">una sola vez</strong>. Guárdalo en un lugar seguro; si se pierde, deberás restablecer la contraseña (no se puede recuperar).</span>
                    </div>
                    <Button type="button" class="h-10 shrink-0 gap-1.5 rounded-xl" @click="downloadCredentials">
                        <Download class="size-4" /> Descargar credenciales
                    </Button>
                </div>

                <div class="overflow-x-auto rounded-xl border border-border">
                    <table class="w-full min-w-[640px] text-left text-xs">
                        <thead class="bg-muted/50 text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2">Nombre</th>
                                <th class="px-3 py-2">Sucursal</th>
                                <th class="px-3 py-2">Correo</th>
                                <th class="px-3 py-2">Creación</th>
                                <th class="px-3 py-2">Acceso</th>
                                <th class="px-3 py-2">Invitación</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(r, i) in result.rows" :key="i" class="border-t border-border">
                                <td class="px-3 py-2 font-semibold text-foreground">{{ r.name }}</td>
                                <td class="px-3 py-2">{{ r.branch }}</td>
                                <td class="px-3 py-2 break-all">{{ r.email }}</td>
                                <td class="px-3 py-2">{{ r.creation }}</td>
                                <td class="px-3 py-2">{{ r.access }}</td>
                                <td class="px-3 py-2">{{ r.invitation }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="flex justify-end">
                    <Button type="button" class="h-10 rounded-xl" @click="close">Listo</Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
