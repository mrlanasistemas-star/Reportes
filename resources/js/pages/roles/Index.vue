<script setup lang="ts">
// Módulo ROLES Y PERMISOS (prioridad 8) — página orquestadora, separada de
// Usuarios. Listado de roles, matriz visual del rol elegido y asistente de 3 pasos.
import { Head } from '@inertiajs/vue3'
import { Grid3x3, Plus, Users } from 'lucide-vue-next'
import { computed, ref } from 'vue'
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
        <AppPageHeader title="Roles y permisos" subtitle="Qué módulos puede usar cada rol y qué acciones puede hacer dentro de ellos. Cada permiso se valida también en el servidor.">
            <template #actions>
                <a v-if="users_url" :href="users_url" class="inline-flex h-11 items-center gap-2 rounded-xl border border-border px-4 text-sm font-semibold text-foreground transition hover:bg-muted">
                    <Users class="size-4" /> Usuarios
                </a>
                <Button v-if="can.create" type="button" class="h-11 gap-2 rounded-xl bg-gradient-to-r from-indigo-600 to-violet-600 text-white shadow-md hover:shadow-lg" @click="create">
                    <Plus class="size-4" /> Nuevo rol
                </Button>
            </template>
        </AppPageHeader>

        <RoleManager :roles="roles" :selected-key="selectedKey" @edit="edit" @select="(r) => (selectedKey = r.key)" />

        <section v-if="selected" class="app-card space-y-3 p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 text-sm font-bold text-foreground"><Grid3x3 class="size-4 text-violet-600" /> Matriz de permisos — {{ selected.name }}</h2>
                <p class="text-xs text-muted-foreground">{{ selected.key === 'admin' ? 'El Administrador siempre tiene todo.' : selected.summary }}</p>
            </div>
            <RolePermissionMatrix :modules="selected.modules" :actions="selected.actions" :module-catalog="module_catalog" :action-catalog="action_catalog" />
        </section>

        <RoleWizardDialog v-model:open="wizardOpen" :role="editing" :store-url="store_url" :modules="module_catalog" :actions="action_catalog" :grantable="grantable" />
    </div>
</template>
