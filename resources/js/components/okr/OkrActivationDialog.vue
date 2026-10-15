<script setup lang="ts">
// Activación con revisión previa (15-oct-2026): antes de activar se muestra,
// por cada OKR (sucursal y cada gestor), si está listo o qué le falta. Nada se
// activa "a medias" sin que el usuario lo vea; el que no esté listo se queda
// en borrador con su motivo. Sin pantallas de carga globales: spinner local.
import { router } from '@inertiajs/vue3'
import { AlertTriangle, CheckCircle2, Info, Loader2, Rocket } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { computed, ref, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { okrRequest } from '@/lib/okrHttp'

type Readiness = { id: number; label: string; scope: string; status: string; ready: boolean; issues: string[]; notes: string[] }

const props = defineProps<{ objectiveId: number }>()
const emit = defineEmits<{ configure: [objectiveId: number] }>()
const open = defineModel<boolean>('open', { default: false })

const loading = ref(false)
const submitting = ref<'all' | 'single' | null>(null)
const main = ref<Readiness | null>(null)
const children = ref<Readiness[]>([])
const error = ref<string | null>(null)

async function load() {
    loading.value = true
    error.value = null
    const res = await okrRequest<{ objective: Readiness; children: Readiness[] }>(`/okr/${props.objectiveId}/activation-check`)
    loading.value = false

    if (!res.ok) {
        error.value = res.message

        return
    }

    main.value = res.data.objective
    children.value = res.data.children
}

watch(open, (v) => v && load())

const draftChildren = computed(() => children.value.filter((c) => c.status === 'draft'))
const readyChildren = computed(() => draftChildren.value.filter((c) => c.ready))
const pendingChildren = computed(() => draftChildren.value.filter((c) => !c.ready))

function activate(includeChildren: boolean) {
    submitting.value = includeChildren ? 'all' : 'single'
    router.post(`/okr/${props.objectiveId}/activate`, { include_children: includeChildren }, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false
        },
        onError: (errors) => {
            Swal.fire({ icon: 'error', title: 'No se pudo activar', text: String(Object.values(errors)[0] ?? 'Revisa los datos del OKR.') })
        },
        onFinish: () => {
            submitting.value = null
        },
    })
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[90vh] w-[96vw] max-w-2xl overflow-y-auto sm:max-w-2xl">
            <DialogTitle class="flex items-center gap-2 text-base font-bold"><Rocket class="size-5 text-primary" /> Activar OKR</DialogTitle>
            <p class="-mt-1 text-xs text-muted-foreground">Al activar se congela la línea base de cada OKR con Reportería. Revisa qué está listo antes de continuar.</p>

            <div v-if="loading" class="space-y-2 py-2">
                <div v-for="n in 3" :key="n" class="h-14 animate-pulse rounded-xl bg-muted" />
            </div>
            <p v-else-if="error" class="rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">{{ error }}</p>

            <div v-else-if="main" class="space-y-3">
                <div v-for="item in [main, ...draftChildren]" :key="item.id" class="rounded-xl border p-3 transition" :class="item.ready ? 'border-emerald-500/30 bg-emerald-500/5' : 'border-amber-500/40 bg-amber-500/5'">
                    <div class="flex items-center justify-between gap-2">
                        <p class="flex items-center gap-2 text-sm font-semibold text-foreground">
                            <CheckCircle2 v-if="item.ready" class="size-4 text-emerald-600" /><AlertTriangle v-else class="size-4 text-amber-600" />
                            {{ item.label }}
                            <span class="text-[10px] font-normal uppercase text-muted-foreground">{{ item.scope === 'branch' ? 'Sucursal' : 'Individual' }}</span>
                        </p>
                        <span class="text-[11px] font-bold" :class="item.ready ? 'text-emerald-700 dark:text-emerald-300' : 'text-amber-700 dark:text-amber-300'">{{ item.ready ? 'Listo' : (item.status === 'draft' ? 'Pendiente' : 'No aplica') }}</span>
                    </div>
                    <ul v-if="item.issues.length" class="mt-1.5 space-y-0.5 pl-6 text-xs text-amber-800 dark:text-amber-200">
                        <li v-for="issue in item.issues" :key="issue" class="list-disc">{{ issue }}</li>
                    </ul>
                    <ul v-if="item.notes.length" class="mt-1 space-y-0.5 pl-6 text-[11px] text-muted-foreground">
                        <li v-for="note in item.notes" :key="note" class="list-disc">{{ note }}</li>
                    </ul>
                    <button v-if="!item.ready && item.issues.some((i) => i.includes('Key Results'))" type="button" class="mt-2 text-xs font-bold text-primary hover:underline" @click="emit('configure', item.id)">Configurar Key Results →</button>
                </div>

                <p v-if="pendingChildren.length" class="flex items-start gap-2 rounded-xl bg-muted/40 p-3 text-xs text-muted-foreground">
                    <Info class="mt-0.5 size-4 shrink-0" />
                    <span>{{ pendingChildren.length }} OKR individual(es) seguirán en <strong>borrador</strong> hasta completar lo indicado. Puedes activarlos después desde su detalle.</span>
                </p>

                <div class="flex flex-wrap justify-end gap-2 border-t border-border pt-3">
                    <Button variant="ghost" class="rounded-xl" :disabled="!!submitting" @click="open = false">Cancelar</Button>
                    <Button v-if="draftChildren.length" variant="outline" class="gap-1.5 rounded-xl" :disabled="!main.ready || !!submitting" @click="activate(false)">
                        <Loader2 v-if="submitting === 'single'" class="size-4 animate-spin" /> Solo {{ main.scope === 'branch' ? 'la sucursal' : 'este OKR' }}
                    </Button>
                    <Button class="gap-1.5 rounded-xl bg-emerald-600 text-white hover:bg-emerald-500" :disabled="!main.ready || !!submitting" @click="activate(draftChildren.length > 0)">
                        <Loader2 v-if="submitting === 'all'" class="size-4 animate-spin" /><Rocket v-else class="size-4" />
                        {{ submitting === 'all' ? 'Activando…' : (readyChildren.length ? `Activar sucursal + ${readyChildren.length} listo(s)` : 'Activar') }}
                    </Button>
                </div>
                <p v-if="!main.ready" class="text-right text-[11px] font-semibold text-amber-700 dark:text-amber-300">Corrige los pendientes de "{{ main.label }}" para poder activarlo.</p>
            </div>
        </DialogContent>
    </Dialog>
</template>
