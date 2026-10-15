<script setup lang="ts">
// Buscador y filtros (rol / estado de acceso / sucursal) del módulo Usuarios.
import { Search, X } from 'lucide-vue-next'
import { computed } from 'vue'
import type { AccessState } from './users'
import { ACCESS_META } from './users'

defineProps<{ roles: { key: string; name: string }[]; branches: string[] }>()
const search = defineModel<string>('search', { required: true })
const role = defineModel<string>('role', { required: true })
const access = defineModel<'' | AccessState>('access', { required: true })
const branch = defineModel<string>('branch', { required: true })

const active = computed(() => search.value !== '' || role.value !== '' || access.value !== '' || branch.value !== '')

function clear() {
    search.value = ''
    role.value = ''
    access.value = ''
    branch.value = ''
}

const accessOptions = Object.entries(ACCESS_META) as [AccessState, { label: string }][]
</script>

<template>
    <div class="flex flex-col gap-2 rounded-xl border bg-card p-2 shadow-soft-xs lg:flex-row lg:items-center">
        <label class="relative flex-1">
            <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <input v-model="search" type="search" placeholder="Buscar por nombre, correo o colaborador…" class="app-input h-10 rounded-lg pl-9 text-sm" aria-label="Buscar usuarios">
        </label>
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3 lg:flex">
            <select v-model="role" class="app-input h-10 rounded-lg pr-8 text-sm lg:w-44" aria-label="Filtrar por rol">
                <option value="">Todos los roles</option>
                <option v-for="r in roles" :key="r.key" :value="r.key">{{ r.name }}</option>
            </select>
            <select v-model="access" class="app-input h-10 rounded-lg pr-8 text-sm lg:w-56" aria-label="Filtrar por estado de acceso">
                <option value="">Todos los estados</option>
                <option v-for="[key, meta] in accessOptions" :key="key" :value="key">{{ meta.label }}</option>
            </select>
            <select v-model="branch" class="app-input h-10 rounded-lg pr-8 text-sm lg:w-52" aria-label="Filtrar por sucursal">
                <option value="">Todas las sucursales</option>
                <option value="__none__">Sin sucursal</option>
                <option v-for="b in branches" :key="b" :value="b">{{ b }}</option>
            </select>
        </div>
        <button v-if="active" type="button" class="inline-flex h-10 items-center gap-1 rounded-lg px-3 text-xs font-semibold text-muted-foreground hover:bg-muted hover:text-foreground" @click="clear">
            <X class="size-3.5" /> Limpiar
        </button>
    </div>
</template>
