<script setup lang="ts">
// Módulo ROLES Y PERMISOS (prioridad 8) — página orquestadora, separada de
// Usuarios. Listado de roles, matriz visual del rol elegido y asistente de 3 pasos.
import { Head } from '@inertiajs/vue3'
import { Grid3x3, Plus, Users } from 'lucide-vue-next'
import { computed, nextTick, ref } from 'vue'
import AppPageHeader from '@/components/app/AppPageHeader.vue'
import { Button } from '@/components/ui/button'
import RoleManager from '@/components/usuarios/RoleManager.vue'
import RolePermissionMatrix from '@/components/usuarios/RolePermissionMatrix.vue'
import type { ActionOption, Grantable, ModuleOption, RoleInfo } from '@/components/usuarios/roles'
import RoleWizardDialog from '@/components/usuarios/RoleWizardDialog.vue'
import { useAdminFlash } from '@/components/usuarios/useAdminFlash'
import AppLayout from '@/layouts/AppLayout.vue'

defineOptions({ layout: AppLayout })

const props = defineProps<{
    roles: RoleInfo[]
    module_catalog: ModuleOption[]
    action_catalog: ActionOption[]
    columns: Record<string, string>
    store_url: string
    users_url: string | null
    can: { create: boolean }
    grantable: Grantable
}>()

useAdminFlash()

const selectedKey = ref<string | null>(props.roles.find((r) => r.key !== 'admin')?.key ?? props.roles[0]?.key ?? null)
const selected = computed(() => props.roles.find((r) => r.key === selectedKey.value) ?? null)

const wizardOpen = ref(false)
const editing = ref<RoleInfo | null>(null)

// Al elegir "Ver permisos" se lleva la vista a la matriz (en móvil queda muy abajo).
async function select(role: RoleInfo) {
    selectedKey.value = role.key
    await nextTick()
    document.getElementById('matriz-permisos')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function create() {
    editing.value = null
    wizardOpen.value = true
}

function edit(role: RoleInfo) {
    editing.value = role
    wizardOpen.value = true
}
</script>

<template>
    <Head title="Roles y permisos" />
    <div class="w-full space-y-6 px-4 py-6 sm:px-6 lg:px-8">
        <AppPageHeader kicker="Administración" title="Roles y permisos" subtitle="Qué módulos puede usar cada rol y qué acciones puede hacer dentro de ellos. Cada permiso se valida también en el servidor.">
            <template #actions>
                <a v-if="users_url" :href="users_url" class="inline-flex h-10 items-center gap-2 rounded-lg border border-border bg-card px-4 text-sm font-semibold text-foreground shadow-soft-xs transition hover:bg-muted">
                    <Users class="size-4" /> Usuarios
                </a>
                <Button v-if="can.create" type="button" class="h-10 gap-2 px-4" @click="create">
                    <Plus class="size-4" /> Nuevo rol
                </Button>
            </template>
        </AppPageHeader>

        <RoleManager :roles="roles" :selected-key="selectedKey" @edit="edit" @select="select" />

        <section v-if="selected" id="matriz-permisos" class="app-section space-y-4 p-4 sm:p-5">
            <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p class="app-kicker">Permisos del rol</p>
                    <h2 class="mt-0.5 flex items-center gap-2 text-lg font-semibold text-foreground"><Grid3x3 class="size-4 text-primary" /> {{ selected.name }}</h2>
                </div>
                <p class="text-sm text-muted-foreground">{{ selected.key === 'admin' ? 'El Administrador siempre tiene todo.' : selected.summary }}</p>
            </div>
            <RolePermissionMatrix :modules="selected.modules" :actions="selected.actions" :module-catalog="module_catalog" :action-catalog="action_catalog" />
        </section>

        <RoleWizardDialog v-model:open="wizardOpen" :role="editing" :store-url="store_url" :modules="module_catalog" :actions="action_catalog" :grantable="grantable" />
    </div>
</template>
