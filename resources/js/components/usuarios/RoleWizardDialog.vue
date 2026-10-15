<script setup lang="ts">
// Asistente de rol (prioridad 8): paso 1 nombre + descripción + nivel base,
// paso 2 módulos que puede usar, paso 3 acciones dentro de cada módulo. Al
// quitar un módulo se quitan sus acciones (el backend hace lo mismo: nunca
// quedan privilegios residuales).
import { useForm } from '@inertiajs/vue3'
import { ArrowLeft, ArrowRight, Check, Loader2, ShieldPlus } from 'lucide-vue-next'
import { computed, ref, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog'
import RolePermissionMatrix from './RolePermissionMatrix.vue'
import { BASE_LEVELS, actionsIn, summaryText } from './roles'
import type { ActionOption, Grantable, ModuleOption, RoleInfo } from './roles'

const props = defineProps<{ role: RoleInfo | null; storeUrl: string; modules: ModuleOption[]; actions: ActionOption[]; grantable: Grantable }>()
const open = defineModel<boolean>('open', { default: false })

const STEPS = ['Nombre', 'Módulos', 'Acciones'] as const
const step = ref(0)

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

    step.value = 0
    form.clearErrors()
    form.name = props.role?.name ?? ''
    form.description = props.role?.description ?? ''
    form.base_level = props.role?.base_level ?? 'colaborador'
    form.modules = [...(props.role?.modules ?? [])]
    form.actions = JSON.parse(JSON.stringify(props.role?.actions ?? {}))
})

function canGrantModule(key: string): boolean {
    return props.grantable.modules.includes(key) || form.modules.includes(key)
}

function toggleModule(key: string) {
    if (form.modules.includes(key)) {
        form.modules = form.modules.filter((m) => m !== key)
        const next = { ...form.actions }
        delete next[key]
        form.actions = next
    } else if (canGrantModule(key)) {
        form.modules = [...form.modules, key]
    }
}

function toggleAction(module: string, action: string) {
    const current = form.actions[module] ?? []
    form.actions = { ...form.actions, [module]: current.includes(action) ? current.filter((a) => a !== action) : [...current, action] }
}

function setAll(module: string, on: boolean) {
    const allowed = actionsIn(props.actions, module).map((a) => a.action)
        .filter((a) => props.grantable.actions[module]?.includes(a) || form.actions[module]?.includes(a))
    form.actions = { ...form.actions, [module]: on ? allowed : [] }
}

const summary = computed(() => summaryText(form.name.trim(), form.modules, form.actions))
const canNext = computed(() => (step.value === 0 ? form.name.trim().length >= 3 : true))

// Si el backend rechaza algo, vuelve al paso donde está el campo con error.
watch(() => form.errors, (errors) => {
    if (errors.name || errors.base_level || errors.description) {
        step.value = 0
    } else if (errors.modules) {
        step.value = 1
    }
}, { deep: true })

function submit() {
    const options = { preserveScroll: true, onSuccess: () => {
        open.value = false
    } }

    if (isEdit.value && props.role?.update_url) {
        form.put(props.role.update_url, options)
    } else {
        form.post(props.storeUrl, options)
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92vh] w-[96vw] max-w-4xl overflow-y-auto p-0 sm:max-w-4xl">
            <div class="bg-gradient-to-br from-indigo-600 via-violet-600 to-fuchsia-600 px-6 py-5 text-white">
                <DialogTitle class="flex items-center gap-2 text-lg font-black"><ShieldPlus class="size-5" /> {{ isEdit ? `Editar rol «${role?.name}»` : 'Nuevo rol' }}</DialogTitle>
                <DialogDescription class="mt-1 text-sm text-white/80">Los cambios aplican de inmediato a todos los usuarios con este rol.</DialogDescription>
                <ol class="mt-4 flex gap-2">
                    <li v-for="(label, i) in STEPS" :key="label" class="flex items-center gap-2 text-xs font-bold" :class="i <= step ? 'text-white' : 'text-white/50'">
                        <span class="flex size-6 items-center justify-center rounded-full border" :class="i < step ? 'border-white bg-white text-violet-700' : i === step ? 'border-white' : 'border-white/40'">
                            <Check v-if="i < step" class="size-3.5" /><template v-else>{{ i + 1 }}</template>
                        </span>
                        {{ label }}
                        <span v-if="i < STEPS.length - 1" class="mx-1 h-px w-6 bg-white/40" />
                    </li>
                </ol>
            </div>

            <div class="space-y-5 p-6">
                <template v-if="step === 0">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="space-y-1">
                            <span class="text-xs font-bold text-foreground">Nombre del rol</span>
                            <input v-model="form.name" maxlength="80" placeholder="Ej. Gerente de Sucursal" class="app-input h-10 text-sm">
                            <span v-if="form.errors.name" class="text-xs font-semibold text-rose-600">{{ form.errors.name }}</span>
                        </label>
                        <label class="space-y-1">
                            <span class="text-xs font-bold text-foreground">Descripción <span class="font-normal text-muted-foreground">(opcional)</span></span>
                            <input v-model="form.description" maxlength="255" placeholder="Para qué sirve este rol" class="app-input h-10 text-sm">
                        </label>
                    </div>
                    <div class="space-y-2">
                        <p class="text-xs font-bold text-foreground">Nivel base <span class="font-normal text-muted-foreground">— qué información ve en OKR y documentos</span></p>
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
                </template>

                <template v-else-if="step === 1">
                    <p class="text-xs text-muted-foreground">Elige los módulos que este rol puede abrir (columna «Ver» de la matriz). {{ form.modules.length }} de {{ modules.length }} elegidos.</p>
                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        <button
                            v-for="m in modules" :key="m.key" type="button" :disabled="!canGrantModule(m.key)"
                            class="flex items-start gap-2.5 rounded-2xl border p-3 text-left transition disabled:cursor-not-allowed disabled:opacity-50"
                            :class="form.modules.includes(m.key) ? 'border-emerald-400/60 bg-emerald-500/5' : 'border-border hover:border-emerald-300'"
                            :title="canGrantModule(m.key) ? m.description : 'Tu propio rol no tiene este módulo; no puedes otorgarlo.'"
                            @click="toggleModule(m.key)"
                        >
                            <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-md border transition" :class="form.modules.includes(m.key) ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-border'">
                                <Check v-if="form.modules.includes(m.key)" class="size-3.5" />
                            </span>
                            <span>
                                <span class="block text-sm font-semibold text-foreground">{{ m.label }}</span>
                                <span class="block text-[11px] leading-4 text-muted-foreground">{{ m.description }}</span>
                            </span>
                        </button>
                    </div>
                    <p v-if="form.errors.modules" class="text-xs font-semibold text-rose-600">{{ form.errors.modules }}</p>
                    <p v-if="!form.modules.length" class="text-[11px] font-semibold text-amber-700 dark:text-amber-300">Sin módulos, solo verá la Guía del sistema y su Configuración.</p>
                </template>

                <template v-else>
                    <p class="text-xs text-muted-foreground">Marca las acciones permitidas dentro de cada módulo elegido. «—» significa que ese módulo no tiene esa operación.</p>
                    <div v-if="form.modules.length" class="flex flex-wrap gap-2">
                        <span v-for="key in form.modules" :key="key" class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-1 text-[11px] font-semibold text-foreground">
                            {{ modules.find((m) => m.key === key)?.label }}
                            <button v-if="actionsIn(actions, key).length" type="button" class="text-primary hover:underline" @click="setAll(key, true)">todas</button>
                            <button v-if="actionsIn(actions, key).length" type="button" class="text-muted-foreground hover:underline" @click="setAll(key, false)">ninguna</button>
                        </span>
                    </div>
                    <RolePermissionMatrix
                        v-if="form.modules.length"
                        :modules="form.modules" :actions="form.actions" :module-catalog="modules" :action-catalog="actions"
                        :rows="form.modules" editable :grantable="grantable" @toggle="toggleAction"
                    />
                    <p v-else class="text-sm text-muted-foreground">No elegiste módulos en el paso 2.</p>
                </template>

                <p class="rounded-xl bg-violet-500/5 px-3 py-2 text-sm font-semibold text-violet-800 dark:text-violet-200">{{ summary }}</p>
            </div>

            <div class="flex items-center justify-between gap-2 border-t border-border px-6 py-4">
                <Button type="button" variant="outline" class="h-10 gap-1.5 rounded-xl" @click="step === 0 ? (open = false) : step--">
                    <ArrowLeft v-if="step > 0" class="size-4" /> {{ step === 0 ? 'Cancelar' : 'Atrás' }}
                </Button>
                <Button v-if="step < STEPS.length - 1" type="button" class="h-10 gap-1.5 rounded-xl" :disabled="!canNext" @click="step++">
                    Siguiente <ArrowRight class="size-4" />
                </Button>
                <Button v-else type="button" class="h-10 gap-1.5 rounded-xl" :disabled="form.processing || form.name.trim().length < 3" @click="submit">
                    <Loader2 v-if="form.processing" class="size-4 animate-spin" /> {{ isEdit ? 'Guardar cambios' : 'Crear rol' }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
