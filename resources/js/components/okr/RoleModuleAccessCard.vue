<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { LayoutGrid } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { computed, ref, watch } from 'vue'
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'

// Módulos por rol (14-oct-2026) — qué módulos del menú ve cada rol. La
// autorización real la aplica el backend (EnsureModuleAccess); el admin
// siempre ve todo y no se puede editar.
type ModuleOption = { key: string; label: string; description: string }
type Matrix = Record<string, string[]>

const props = defineProps<{
    modules: ModuleOption[]
    access: Matrix
}>()

const roles = [
    { key: 'gerencial', label: 'Gerencial' },
    { key: 'colaborador', label: 'Colaborador' },
] as const

const draft = ref<Matrix>(clone(props.access))
const saving = ref(false)

watch(() => props.access, (value) => {
    draft.value = clone(value)
})

const dirty = computed(() => JSON.stringify(normalize(draft.value)) !== JSON.stringify(normalize(props.access)))

function clone(m: Matrix): Matrix {
    return Object.fromEntries(roles.map((r) => [r.key, [...(m[r.key] ?? [])]]))
}

function normalize(m: Matrix): Matrix {
    return Object.fromEntries(roles.map((r) => [r.key, [...(m[r.key] ?? [])].sort()]))
}

function has(role: string, module: string): boolean {
    return draft.value[role]?.includes(module) ?? false
}

function toggle(role: string, module: string, value: boolean | 'indeterminate') {
    const current = draft.value[role] ?? []
    draft.value[role] = value === true ? [...new Set([...current, module])] : current.filter((m) => m !== module)
}

function save() {
    const empty = roles.filter((r) => (draft.value[r.key] ?? []).length === 0).map((r) => r.label)
    const warning = empty.length
        ? `<p style="margin-top:.5rem;color:#b45309">${empty.join(' y ')} no tendrá ningún módulo: solo verá Guía del sistema y Configuración.</p>`
        : ''

    Swal.fire({
        icon: 'question',
        title: '¿Guardar módulos por rol?',
        html: `<p>Aplica de inmediato a todos los usuarios con ese rol, incluso a quienes ya tienen sesión abierta.</p>${warning}`,
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#4f46e5',
    }).then((r) => {
        if (!r.isConfirmed) {
            return
        }

        saving.value = true
        router.put('/okr/responsibles/module-access', { access: draft.value }, {
            preserveScroll: true,
            onSuccess: () => Swal.fire({ icon: 'success', title: 'Módulos actualizados', timer: 1600, showConfirmButton: false }),
            onError: () => Swal.fire({ icon: 'error', title: 'No se pudo guardar', text: 'Revisa la selección e inténtalo de nuevo.' }),
            onFinish: () => {
                saving.value = false
            },
        })
    })
}
</script>

<template>
    <div class="app-table-wrap">
        <div class="app-table-toolbar">
            <div class="flex items-center gap-2">
                <LayoutGrid class="size-4 text-primary" />
                <h2 class="text-sm font-bold text-foreground">Módulos por rol</h2>
                <OkrHelpTooltip text="Marca qué módulos puede ver cada rol. El Administrador siempre ve todo. Guía del sistema y Configuración están disponibles para todos." />
            </div>
            <div class="flex items-center gap-2">
                <Button v-if="dirty" type="button" variant="outline" class="h-9" :disabled="saving" @click="draft = clone(access)">Descartar</Button>
                <Button type="button" class="h-9" :disabled="!dirty || saving" @click="save">Guardar cambios</Button>
            </div>
        </div>

        <div class="app-table-content">
            <table class="w-full min-w-[560px] text-sm">
                <thead class="border-b border-border bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold">Módulo</th>
                        <th class="px-4 py-3 text-center font-semibold">Administrador</th>
                        <th v-for="role in roles" :key="role.key" class="px-4 py-3 text-center font-semibold">{{ role.label }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="mod in modules" :key="mod.key" class="border-t border-border transition hover:bg-muted/30">
                        <td class="px-4 py-3">
                            <p class="font-semibold text-foreground">{{ mod.label }}</p>
                            <p class="text-xs text-muted-foreground">{{ mod.description }}</p>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <Checkbox :model-value="true" disabled class="mx-auto" :aria-label="`Administrador — ${mod.label}`" title="El Administrador siempre ve todo" />
                        </td>
                        <td v-for="role in roles" :key="role.key" class="px-4 py-3 text-center">
                            <Checkbox
                                class="mx-auto cursor-pointer"
                                :model-value="has(role.key, mod.key)"
                                :aria-label="`${role.label} — ${mod.label}`"
                                @update:model-value="(v) => toggle(role.key, mod.key, v)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
