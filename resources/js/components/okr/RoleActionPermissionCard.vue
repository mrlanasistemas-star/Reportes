<script setup lang="ts">
import { router } from '@inertiajs/vue3'
import { ShieldCheck } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { computed, ref, watch } from 'vue'
import OkrHelpTooltip from '@/components/okr/OkrHelpTooltip.vue'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'

// Permisos por ACCIÓN (15-oct-2026, sección 7 de la auditoría) — complementa a
// "Módulos por rol": ver un módulo no implica poder eliminar o exportar dentro
// de él. El backend vuelve a comprobar esto en cada endpoint sensible (ver
// ActionPermissionService) — esta tarjeta nunca es la única barrera.
type ActionOption = { module: string; action: string; label: string }
type Matrix = Record<string, Record<string, string[]>>

const props = defineProps<{
    actions: ActionOption[]
    permissions: Matrix
}>()

const roles = [
    { key: 'gerencial', label: 'Gerencial' },
    { key: 'colaborador', label: 'Colaborador' },
] as const

const draft = ref<Matrix>(clone(props.permissions))
const saving = ref(false)

watch(() => props.permissions, (value) => {
    draft.value = clone(value)
})

const dirty = computed(() => JSON.stringify(normalize(draft.value)) !== JSON.stringify(normalize(props.permissions)))

function clone(m: Matrix): Matrix {
    const out: Matrix = {}
    for (const r of roles) {
        out[r.key] = {}
        for (const a of props.actions) {
            out[r.key][a.module] = [...(m[r.key]?.[a.module] ?? [])]
        }
    }
    return out
}

function normalize(m: Matrix): Matrix {
    const out: Matrix = {}
    for (const r of roles) {
        out[r.key] = {}
        for (const a of props.actions) {
            out[r.key][a.module] = [...(m[r.key]?.[a.module] ?? [])].sort()
        }
    }
    return out
}

function has(role: string, item: ActionOption): boolean {
    return draft.value[role]?.[item.module]?.includes(item.action) ?? false
}

function toggle(role: string, item: ActionOption, value: boolean | 'indeterminate') {
    const current = draft.value[role]?.[item.module] ?? []
    draft.value[role] ??= {}
    draft.value[role][item.module] = value === true ? [...new Set([...current, item.action])] : current.filter((a) => a !== item.action)
}

function save() {
    Swal.fire({
        icon: 'question',
        title: '¿Guardar permisos por acción?',
        text: 'Aplica de inmediato a todos los usuarios con ese rol, incluso a quienes ya tienen sesión abierta.',
        showCancelButton: true,
        confirmButtonText: 'Guardar',
        cancelButtonText: 'Cancelar',
        confirmButtonColor: '#4f46e5',
    }).then((r) => {
        if (!r.isConfirmed) {
            return
        }

        saving.value = true
        router.put('/okr/responsibles/action-permissions', { actions: draft.value }, {
            preserveScroll: true,
            onSuccess: () => Swal.fire({ icon: 'success', title: 'Permisos actualizados', timer: 1600, showConfirmButton: false }),
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
                <ShieldCheck class="size-4 text-primary" />
                <h2 class="text-sm font-bold text-foreground">Permisos por acción</h2>
                <OkrHelpTooltip text="Dentro de un módulo al que un rol ya tiene acceso, estas acciones son irreversibles o sensibles (eliminar, exportar) — márcalas aparte. El Administrador siempre puede todo." />
            </div>
            <div class="flex items-center gap-2">
                <Button v-if="dirty" type="button" variant="outline" class="h-9" :disabled="saving" @click="draft = clone(permissions)">Descartar</Button>
                <Button type="button" class="h-9" :disabled="!dirty || saving" @click="save">Guardar cambios</Button>
            </div>
        </div>

        <div class="app-table-content">
            <table class="w-full min-w-[560px] text-sm">
                <thead class="border-b border-border bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold">Acción</th>
                        <th class="px-4 py-3 text-center font-semibold">Administrador</th>
                        <th v-for="role in roles" :key="role.key" class="px-4 py-3 text-center font-semibold">{{ role.label }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="item in actions" :key="`${item.module}.${item.action}`" class="border-t border-border transition hover:bg-muted/30">
                        <td class="px-4 py-3 font-semibold text-foreground">{{ item.label }}</td>
                        <td class="px-4 py-3 text-center">
                            <Checkbox :model-value="true" disabled class="mx-auto" :aria-label="`Administrador — ${item.label}`" title="El Administrador siempre puede todo" />
                        </td>
                        <td v-for="role in roles" :key="role.key" class="px-4 py-3 text-center">
                            <Checkbox
                                class="mx-auto cursor-pointer"
                                :model-value="has(role.key, item)"
                                :aria-label="`${role.label} — ${item.label}`"
                                @update:model-value="(v) => toggle(role.key, item, v)"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-if="!actions.length" class="px-4 py-3 text-xs text-muted-foreground">No hay acciones configurables todavía.</p>
        </div>
    </div>
</template>
