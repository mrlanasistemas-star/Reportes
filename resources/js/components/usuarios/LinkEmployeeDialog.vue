<script setup lang="ts">
// Vincular (o desvincular) el colaborador de un usuario: buscador en cliente
// por nombre, número de empleado, sucursal o puesto. Se ofrecen solo personas
// libres o la ya vinculada a ESTE usuario (misma regla que el backend, que
// vuelve a validar). Define la sucursal y los OKR propios del usuario; no
// modifica los datos del colaborador.
import { router } from '@inertiajs/vue3'
import { Check, Link2, Loader2, RotateCw, Search, Unlink } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import type { LinkableEmployee, UserRow } from './users'

const MAX_RESULTS = 100

const props = defineProps<{ user: UserRow | null; employees: LinkableEmployee[] | undefined }>()
const open = defineModel<boolean>('open', { default: false })

const search = ref('')
const selected = ref<number | null>(null)
const processing = ref(false)
const retrying = ref(false)

const loading = computed(() => props.employees === undefined)

function normalize(v: string): string {
    return v.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim()
}

// Personas libres o la vinculada a este usuario (nunca una ligada a otra cuenta).
const available = computed(() => {
    const userId = props.user?.id

    return (props.employees ?? [])
        .filter((e) => e.linked_user_id === null || e.linked_user_id === userId)
        .map((e) => ({ ...e, haystack: normalize(`${e.full_name} ${e.employee_code ?? ''} ${e.current_branch ?? ''} ${e.position ?? ''}`) }))
})

// Opción actual: la persona ligada a este usuario (el id expuesto es el canónico
// de la persona; el usuario puede estar ligado a otro registro histórico del grupo).
const currentId = computed<number | null>(() => {
    const u = props.user

    if (!u || u.employee_id === null) {
        return null
    }

    return available.value.find((e) => e.linked_user_id === u.id)?.id ?? available.value.find((e) => e.id === u.employee_id)?.id ?? u.employee_id
})

const matches = computed(() => {
    const terms = normalize(search.value).split(/\s+/).filter(Boolean)

    return terms.length === 0 ? available.value : available.value.filter((e) => terms.every((t) => e.haystack.includes(t)))
})
const shown = computed(() => matches.value.slice(0, MAX_RESULTS))
const changed = computed(() => selected.value !== currentId.value)
const selectedEmployee = computed(() => available.value.find((e) => e.id === selected.value) ?? null)

watch(open, (v) => {
    if (v) {
        search.value = ''
        selected.value = currentId.value
        processing.value = false
    }
})

// Si la lista llega después de abrir el diálogo (prop diferida), marcar la opción actual.
watch(loading, (isLoading, wasLoading) => {
    if (open.value && wasLoading && !isLoading) {
        selected.value = currentId.value
    }
})

function retry() {
    retrying.value = true
    router.reload({ only: ['employees'], onFinish: () => {
        retrying.value = false
    } })
}

function submit() {
    if (!props.user || !changed.value) {
        return
    }

    router.put(props.user.urls.link_employee, { employee_id: selected.value }, {
        preserveScroll: true,
        onStart: () => {
            processing.value = true
        },
        onSuccess: () => {
            open.value = false
        },
        onFinish: () => {
            processing.value = false
        },
    })
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="flex max-h-[92vh] w-[96vw] max-w-2xl flex-col overflow-hidden p-0 sm:max-w-2xl">
            <div class="border-b border-border px-6 py-4">
                <DialogTitle class="flex items-center gap-2 text-base font-black"><Link2 class="size-4 text-primary" /> Colaborador vinculado</DialogTitle>
                <DialogDescription class="mt-1 text-xs text-muted-foreground">
                    {{ user?.name }} — hoy: <strong class="text-foreground">{{ user?.employee_name ?? 'Sin vincular' }}</strong>.
                    Define la sucursal y los OKR propios del usuario. No modifica los datos del colaborador.
                </DialogDescription>
            </div>

            <div class="space-y-3 px-6 pt-4">
                <div class="relative">
                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input v-model="search" type="search" class="h-10 pl-9" placeholder="Buscar por nombre, número de empleado, sucursal o puesto…" aria-label="Buscar colaborador" :disabled="loading" />
                </div>
                <p v-if="!loading" class="text-[11px] text-muted-foreground">
                    {{ matches.length }} colaborador(es) disponible(s){{ matches.length > MAX_RESULTS ? ` — se muestran los primeros ${MAX_RESULTS}; afina la búsqueda.` : '.' }}
                    Las personas ya vinculadas a otro usuario no aparecen.
                </p>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-6 py-3">
                <div v-if="loading" class="flex flex-col items-center justify-center gap-3 py-10 text-sm text-muted-foreground">
                    <Loader2 class="size-5 animate-spin text-primary" />
                    Cargando colaboradores…
                    <Button type="button" variant="outline" size="sm" class="gap-1.5" :disabled="retrying" @click="retry">
                        <RotateCw class="size-3.5" :class="retrying ? 'animate-spin' : ''" /> Reintentar
                    </Button>
                </div>

                <div v-else role="radiogroup" aria-label="Colaborador" class="space-y-1.5">
                    <button
                        type="button" role="radio" :aria-checked="selected === null"
                        class="flex w-full items-center gap-3 rounded-xl border px-3 py-2.5 text-left transition focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        :class="selected === null ? 'border-primary bg-primary/5 ring-1 ring-primary/30' : 'border-dashed border-border hover:bg-muted/40'"
                        :disabled="processing" @click="selected = null"
                    >
                        <Unlink class="size-4 shrink-0 text-muted-foreground" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-semibold text-foreground">Sin vincular</span>
                            <span class="block text-[11px] text-muted-foreground">El usuario no queda ligado a ningún colaborador.</span>
                        </span>
                        <span v-if="currentId === null" class="rounded-full bg-sky-500/10 px-2 py-0.5 text-[11px] font-semibold text-sky-700 dark:text-sky-300">Actual</span>
                        <Check v-if="selected === null" class="size-4 shrink-0 text-primary" />
                    </button>

                    <button
                        v-for="e in shown" :key="e.id" type="button" role="radio" :aria-checked="selected === e.id"
                        class="flex w-full items-center gap-3 rounded-xl border px-3 py-2.5 text-left transition focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        :class="selected === e.id ? 'border-primary bg-primary/5 ring-1 ring-primary/30' : 'border-border hover:bg-muted/40'"
                        :disabled="processing" @click="selected = e.id"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="flex flex-wrap items-center gap-1.5 text-sm font-semibold text-foreground">
                                <span class="truncate">{{ e.full_name }}</span>
                                <span v-if="!e.is_active" class="rounded-full bg-rose-500/10 px-1.5 py-0.5 text-[10px] font-bold text-rose-700 dark:text-rose-300">Inactivo</span>
                            </span>
                            <span class="block truncate text-[11px] text-muted-foreground">
                                {{ [e.employee_code ? `NOI ${e.employee_code}` : null, e.current_branch ?? 'Sin sucursal vigente', e.position].filter(Boolean).join(' · ') }}
                            </span>
                        </span>
                        <span v-if="e.id === currentId" class="rounded-full bg-sky-500/10 px-2 py-0.5 text-[11px] font-semibold text-sky-700 dark:text-sky-300">Actual</span>
                        <Check v-if="selected === e.id" class="size-4 shrink-0 text-primary" />
                    </button>

                    <p v-if="shown.length === 0" class="py-6 text-center text-sm text-muted-foreground">Ningún colaborador coincide con «{{ search }}».</p>
                </div>
            </div>

            <div class="flex flex-col-reverse gap-2 border-t border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-muted-foreground">
                    <template v-if="!changed">Sin cambios.</template>
                    <template v-else-if="selectedEmployee">Se vinculará a <strong class="text-foreground">{{ selectedEmployee.full_name }}</strong>.</template>
                    <template v-else>Se quitará el vínculo.</template>
                </p>
                <div class="flex justify-end gap-2">
                    <Button type="button" variant="outline" class="h-10 rounded-xl" :disabled="processing" @click="open = false">Cancelar</Button>
                    <Button type="button" class="h-10 gap-1.5 rounded-xl" :disabled="processing || loading || !changed" @click="submit">
                        <Loader2 v-if="processing" class="size-4 animate-spin" /> {{ processing ? 'Guardando…' : 'Guardar' }}
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
