<script setup lang="ts">
// Crear / editar un rol (16-oct-2026): nombre, descripción, nivel base, módulos
// que ve y acciones sensibles que puede hacer dentro de cada módulo.
import { useForm } from '@inertiajs/vue3'
import { Check, Loader2, ShieldPlus } from 'lucide-vue-next'
import { computed, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { BASE_LEVELS } from './roles'
import type { ActionOption, ModuleOption, RoleInfo } from './roles'

const props = defineProps<{ role: RoleInfo | null; modules: ModuleOption[]; actions: ActionOption[] }>()
const open = defineModel<boolean>('open', { default: false })

const form = useForm({
    name: '',
    description: '',
    base_level: 'colaborador' as string,
    modules: [] as string[],
    actions: {} as Record<string, string[]>,
})

const isEdit = computed(() => props.role !== null)
const isSystem = computed(() => props.role?.is_system ?? false)

watch(open, (v) => {
    if (!v) {
        return
    }

    form.clearErrors()
    form.name = props.role?.name ?? ''
    form.description = props.role?.description ?? ''
    form.base_level = props.role?.base_level ?? 'colaborador'
    form.modules = [...(props.role?.modules ?? [])]
    form.actions = JSON.parse(JSON.stringify(props.role?.actions ?? {}))
})

function toggleModule(key: string) {
    if (form.modules.includes(key)) {
        form.modules = form.modules.filter((m) => m !== key)
        delete form.actions[key] // sin módulo no hay acciones dentro de él
    } else {
        form.modules = [...form.modules, key]
    }
}

function actionsOf(module: string): ActionOption[] {
    return props.actions.filter((a) => a.module === module)
}

function hasAction(module: string, action: string): boolean {
    return form.actions[module]?.includes(action) ?? false
}

function toggleAction(module: string, action: string) {
    const current = form.actions[module] ?? []
    form.actions = { ...form.actions, [module]: current.includes(action) ? current.filter((a) => a !== action) : [...current, action] }
}

function submit() {
    const options = { preserveScroll: true, onSuccess: () => {
 open.value = false 
} }

    if (isEdit.value && props.role) {
        form.put(`/okr/responsibles/roles/${props.role.key}`, options)
    } else {
        form.post('/okr/responsibles/roles', options)
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92vh] w-[96vw] max-w-2xl overflow-y-auto p-0 sm:max-w-2xl">
            <div class="bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600 px-6 py-5 text-white">
                <DialogTitle class="flex items-center gap-2 text-lg font-black"><ShieldPlus class="size-5" /> {{ isEdit ? `Editar rol «${role?.name}»` : 'Nuevo rol' }}</DialogTitle>
                <p class="mt-1 text-sm text-white/80">Define qué módulos ve y qué acciones sensibles puede hacer. Aplica de inmediato a todos los usuarios con este rol.</p>
            </div>

            <div class="space-y-5 p-6">
                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="space-y-1">
                        <span class="text-xs font-bold text-foreground">Nombre del rol</span>
                        <input v-model="form.name" maxlength="80" placeholder="Ej. Supervisor de zona" class="app-input h-10 text-sm">
                        <span v-if="form.errors.name" class="text-xs font-semibold text-rose-600">{{ form.errors.name }}</span>
                    </label>
                    <label class="space-y-1">
                        <span class="text-xs font-bold text-foreground">Descripción <span class="font-normal text-muted-foreground">(opcional)</span></span>
                        <input v-model="form.description" maxlength="255" placeholder="Para qué sirve este rol" class="app-input h-10 text-sm">
                    </label>
                </div>

                <div class="space-y-2">
                    <p class="text-xs font-bold text-foreground">Nivel base <span class="font-normal text-muted-foreground">— cómo se comporta en OKR y documentos</span></p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <button
                            v-for="lvl in BASE_LEVELS" :key="lvl.key" type="button" :disabled="isSystem"
                            class="rounded-2xl border p-3 text-left transition disabled:cursor-not-allowed disabled:opacity-60"
                            :class="form.base_level === lvl.key ? 'border-violet-400 bg-violet-500/5 ring-2 ring-violet-400/30' : 'border-border hover:border-violet-300'"
                            @click="form.base_level = lvl.key"
                        >
                            <span class="flex items-center justify-between text-sm font-bold text-foreground">{{ lvl.label }} <Check v-if="form.base_level === lvl.key" class="size-4 text-violet-600" /></span>
                            <span class="mt-0.5 block text-[11px] leading-4 text-muted-foreground">{{ lvl.hint }}</span>
                        </button>
                    </div>
                    <p v-if="isSystem" class="text-[11px] text-muted-foreground">Es un rol del sistema: su nivel base no cambia, pero sí sus módulos y acciones.</p>
                    <span v-if="form.errors.base_level" class="text-xs font-semibold text-rose-600">{{ form.errors.base_level }}</span>
                </div>

                <div class="space-y-2">
                    <p class="text-xs font-bold text-foreground">Módulos que ve <span class="font-normal text-muted-foreground">({{ form.modules.length }} de {{ modules.length }})</span></p>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <div
                            v-for="m in modules" :key="m.key"
                            class="rounded-2xl border p-3 transition"
                            :class="form.modules.includes(m.key) ? 'border-emerald-400/60 bg-emerald-500/5' : 'border-border'"
                        >
                            <button type="button" class="flex w-full items-start gap-2.5 text-left" @click="toggleModule(m.key)">
                                <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-md border transition" :class="form.modules.includes(m.key) ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-border'">
                                    <Check v-if="form.modules.includes(m.key)" class="size-3.5" />
                                </span>
                                <span>
                                    <span class="block text-sm font-semibold text-foreground">{{ m.label }}</span>
                                    <span class="block text-[11px] leading-4 text-muted-foreground">{{ m.description }}</span>
                                </span>
                            </button>
                            <div v-if="form.modules.includes(m.key) && actionsOf(m.key).length" class="mt-2 space-y-1 border-t border-border pt-2 pl-7">
                                <label v-for="a in actionsOf(m.key)" :key="a.action" class="flex cursor-pointer items-center gap-2 text-[11px] font-semibold text-foreground">
                                    <input type="checkbox" class="accent-violet-600" :checked="hasAction(m.key, a.action)" @change="toggleAction(m.key, a.action)">
                                    {{ a.label }}
                                </label>
                            </div>
                        </div>
                    </div>
                    <p v-if="!form.modules.length" class="text-[11px] font-semibold text-amber-700 dark:text-amber-300">Sin módulos, solo verá la Guía del sistema y su Configuración.</p>
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-border px-6 py-4">
                <Button type="button" variant="outline" class="h-10 rounded-xl" @click="open = false">Cancelar</Button>
                <Button type="button" class="h-10 gap-1.5 rounded-xl" :disabled="form.processing || form.name.trim().length < 3" @click="submit">
                    <Loader2 v-if="form.processing" class="size-4 animate-spin" /> {{ isEdit ? 'Guardar cambios' : 'Crear rol' }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
