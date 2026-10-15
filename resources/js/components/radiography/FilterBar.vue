<script setup lang="ts">
import { Filter, Search, X } from 'lucide-vue-next'
import { computed, ref } from 'vue'

defineProps<{
    branchOptions: string[]
    productOptions: string[]
    bucketOptions: string[]
    gestorOptions: string[]
    categoriaOptions: string[]
}>()

const branch = defineModel<string>('branch', { default: '' })
const product = defineModel<string>('product', { default: '' })
const bucket = defineModel<string>('bucket', { default: '' })
const gestor = defineModel<string>('gestor', { default: '' })
const categoria = defineModel<string>('categoria', { default: '' })

const gestorSearch = ref('')

const activeCount = computed(() => [branch.value, product.value, bucket.value, gestor.value, categoria.value].filter(Boolean).length)

function clearAll() {
    branch.value = ''
    product.value = ''
    bucket.value = ''
    gestor.value = ''
    categoria.value = ''
    gestorSearch.value = ''
}

const selectClass =
    'rounded-xl border border-border bg-card px-3 py-1.5 text-xs font-semibold text-foreground/80 shadow-sm transition focus:border-primary/40 focus:outline-none focus:ring-2 focus:ring-ring/30     '
</script>

<template>
    <div class="rounded-2xl border bg-card p-4 shadow-sm">
        <div class="flex flex-wrap items-center gap-2.5">
            <span class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                <Filter class="size-3.5 text-primary" /> Filtros
            </span>

            <select v-model="branch" :class="selectClass">
                <option value="">Todas las sucursales</option>
                <option v-for="b in branchOptions" :key="b" :value="b">{{ b }}</option>
            </select>

            <select v-model="product" :class="selectClass">
                <option value="">Todos los productos</option>
                <option v-for="p in productOptions" :key="p" :value="p">{{ p }}</option>
            </select>

            <select v-model="bucket" :class="selectClass">
                <option value="">Toda la mora</option>
                <option v-for="b in bucketOptions" :key="b" :value="b">{{ b }}</option>
            </select>

            <select v-model="categoria" :class="selectClass">
                <option value="">Todas las categorías</option>
                <option v-for="c in categoriaOptions" :key="c" :value="c">{{ c }}</option>
            </select>

            <div class="relative">
                <select v-model="gestor" :class="[selectClass, 'max-w-[180px]']">
                    <option value="">Sin gestor seleccionado</option>
                    <option v-for="g in gestorOptions.filter((o) => !gestorSearch.trim() || o.toLowerCase().includes(gestorSearch.trim().toLowerCase()))" :key="g" :value="g">
                        {{ g }}
                    </option>
                </select>
            </div>

            <div v-if="gestorOptions.length > 12" class="relative">
                <Search class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                <input
                    v-model="gestorSearch"
                    type="text"
                    placeholder="Buscar gestor…"
                    class="w-36 rounded-xl border border-border bg-card py-1.5 pl-8 pr-2 text-xs focus:border-primary/40 focus:outline-none focus:ring-2 focus:ring-ring/30"
                />
            </div>

            <span v-if="activeCount > 0" class="inline-flex items-center gap-1 rounded-full bg-accent px-2.5 py-1 text-[11px] font-semibold text-primary">
                {{ activeCount }} filtro{{ activeCount > 1 ? 's' : '' }} activo{{ activeCount > 1 ? 's' : '' }}
            </span>

            <button
                v-if="activeCount > 0"
                type="button"
                @click="clearAll"
                class="ml-auto inline-flex items-center gap-1.5 rounded-xl bg-muted px-3 py-1.5 text-xs font-bold text-muted-foreground transition hover:bg-muted/80"
            >
                <X class="size-3.5" /> Limpiar filtros
            </button>
        </div>
    </div>
</template>
