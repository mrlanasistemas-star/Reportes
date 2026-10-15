<script setup lang="ts">
import { Search } from 'lucide-vue-next'
import SelectField from '@/components/forms/SelectField.vue'

type Option = { value: string; label: string }

withDefaults(
    defineProps<{
        placeholder?: string
        categoryOptions?: Option[]
        branchOptions?: Option[]
    }>(),
    { placeholder: 'Buscar colaborador o sucursal...', categoryOptions: () => [], branchOptions: () => [] },
)

const query = defineModel<string>('query', { required: true })
const category = defineModel<string>('category', { default: '' })
const branch = defineModel<string>('branch', { default: '' })
</script>

<template>
    <div class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <div class="relative w-full sm:w-64">
            <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
            <input v-model="query" type="search" class="app-input h-10 pl-10" :placeholder="placeholder" :aria-label="placeholder" />
        </div>
        <div v-if="categoryOptions.length > 1" class="w-full sm:w-60">
            <SelectField v-model="category" :options="[{ value: '', label: 'Todas las categorías' }, ...categoryOptions]" placeholder="Todas las categorías" />
        </div>
        <div v-if="branchOptions.length > 1" class="w-full sm:w-52">
            <SelectField v-model="branch" :options="[{ value: '', label: 'Todas las sucursales' }, ...branchOptions]" placeholder="Todas las sucursales" />
        </div>
    </div>
</template>
