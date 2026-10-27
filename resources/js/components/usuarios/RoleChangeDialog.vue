<script setup lang="ts">
// Cambiar el rol de un usuario: opciones como tarjetas seleccionables (tipo
// radio) con nombre, descripción y nº de módulos. Los roles que el actor no
// puede conceder se muestran deshabilitados; el backend vuelve a validar.
import { router } from '@inertiajs/vue3'
import { Check, LayoutGrid, Loader2, Lock, ShieldCheck, UserCog } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog'
import type { UserRoleOption, UserRow } from './users'

const props = defineProps<{ user: UserRow | null; roles: UserRoleOption[] }>()
const open = defineModel<boolean>('open', { default: false })

const selected = ref<string | null>(null)
const processing = ref(false)

const options = computed(() => props.roles.map((r) => {
    const current = r.key === props.user?.role

    return { ...r, current, disabled: current || !r.grantable }
}))
const hasChoices = computed(() => options.value.some((o) => !o.disabled))
const selectedRole = computed(() => options.value.find((o) => o.key === selected.value) ?? null)

watch(open, (v) => {
    if (v) {
        selected.value = null
        processing.value = false
    }
})

function choose(key: string, disabled: boolean) {
    if (!disabled && !processing.value) {
        selected.value = key
    }
}

function modulesLabel(n: number): string {
    return n === 1 ? '1 módulo' : `${n} módulos`
}

function submit() {
    if (!props.user || !selected.value) {
        return
    }

    router.put(props.user.urls.update_role, { role: selected.value }, {
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
        <DialogContent class="max-h-[92vh] w-[96vw] max-w-2xl overflow-y-auto p-0 sm:max-w-2xl">
            <div class="border-b border-border px-6 py-4">
                <DialogTitle class="flex items-center gap-2 text-base font-black"><UserCog class="size-4 text-primary" /> Cambiar rol</DialogTitle>
                <DialogDescription class="mt-1 text-xs text-muted-foreground">
                    {{ user?.name }} — rol actual: <strong class="text-foreground">{{ user?.role_name }}</strong>. El cambio aplica en su siguiente acción.
                </DialogDescription>
            </div>

            <div class="p-6">
                <p v-if="!hasChoices" class="rounded-xl bg-muted/50 px-4 py-3 text-sm text-muted-foreground">
                    No hay otro rol que puedas asignar a este usuario.
                </p>
                <div v-else role="radiogroup" aria-label="Nuevo rol" class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="r in options" :key="r.key" type="button" role="radio"
                        :aria-checked="selected === r.key" :aria-disabled="r.disabled" :disabled="r.disabled || processing"
                        class="relative flex flex-col gap-1.5 rounded-xl border p-3 text-left transition focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        :class="selected === r.key
                            ? 'border-primary bg-primary/5 ring-1 ring-primary/30'
                            : r.disabled ? 'cursor-not-allowed border-border bg-muted/30 opacity-70' : 'border-border hover:border-primary/40 hover:bg-muted/40'"
                        :title="r.current ? 'Es su rol actual.' : !r.grantable ? 'Tu rol no puede conceder este rol: tiene permisos que tú no tienes.' : ''"
                        @click="choose(r.key, r.disabled)"
                    >
                        <span class="flex items-start justify-between gap-2">
                            <span class="flex items-center gap-1.5 text-sm font-bold text-foreground">
                                <ShieldCheck v-if="r.key === 'admin'" class="size-4 text-primary" /> {{ r.name }}
                            </span>
                            <span
                                class="flex size-5 shrink-0 items-center justify-center rounded-full border"
                                :class="selected === r.key ? 'border-primary bg-primary text-primary-foreground' : 'border-border'"
                                aria-hidden="true"
                            >
                                <Check v-if="selected === r.key" class="size-3" />
                            </span>
                        </span>
                        <span class="line-clamp-2 text-xs text-muted-foreground">{{ r.description || 'Sin descripción.' }}</span>
                        <span class="mt-0.5 flex flex-wrap items-center gap-1.5 text-[11px]">
                            <span class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-semibold text-foreground"><LayoutGrid class="size-3" /> {{ modulesLabel(r.modules_count) }}</span>
                            <span class="rounded-full bg-muted px-2 py-0.5 text-muted-foreground">{{ r.is_system ? 'Del sistema' : 'Personalizado' }}</span>
                            <span v-if="r.current" class="rounded-full bg-sky-500/10 px-2 py-0.5 font-semibold text-sky-700 dark:text-sky-300">Rol actual</span>
                            <span v-else-if="!r.grantable" class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 font-semibold text-amber-700 dark:text-amber-300"><Lock class="size-3" /> No puedes asignarlo</span>
                        </span>
                    </button>
                </div>
            </div>

            <div class="flex flex-col-reverse gap-2 border-t border-border px-6 py-4 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-xs text-muted-foreground">
                    <template v-if="selectedRole">Nuevo rol: <strong class="text-foreground">{{ selectedRole.name }}</strong></template>
                    <template v-else-if="hasChoices">Elige el nuevo rol.</template>
                </p>
                <div class="flex justify-end gap-2">
                    <Button type="button" variant="outline" class="h-10 rounded-xl" :disabled="processing" @click="open = false">Cancelar</Button>
                    <Button type="button" class="h-10 gap-1.5 rounded-xl" :disabled="processing || !selected" @click="submit">
                        <Loader2 v-if="processing" class="size-4 animate-spin" /> {{ processing ? 'Cambiando…' : 'Cambiar rol' }}
                    </Button>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
