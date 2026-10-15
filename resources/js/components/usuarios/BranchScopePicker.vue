<script setup lang="ts">
// Fase 6.4 — selector de alcance por sucursal: «Todas las sucursales» o una
// lista explícita. Solo ofrece lo que quien edita puede conceder (el backend
// lo vuelve a validar: nadie concede un alcance mayor al propio).
import { Building2, Check, Globe2 } from 'lucide-vue-next'
import { computed } from 'vue'
import type { BranchOption, BranchScopeMode } from './users'

const props = defineProps<{
    branches: BranchOption[]
    /** El actor tiene alcance general → puede conceder «Todas». */
    canGrantAll: boolean
    error?: string | null
}>()
const mode = defineModel<BranchScopeMode>('mode', { required: true })
const branchIds = defineModel<number[]>('branchIds', { required: true })

const allSelected = computed(() => props.branches.length > 0 && props.branches.every((b) => branchIds.value.includes(b.id)))

function toggle(id: number) {
    branchIds.value = branchIds.value.includes(id) ? branchIds.value.filter((x) => x !== id) : [...branchIds.value, id]
}

function toggleAll() {
    branchIds.value = allSelected.value ? [] : props.branches.map((b) => b.id)
}
</script>

<template>
    <div class="space-y-2">
        <div class="grid gap-2 sm:grid-cols-2">
            <button
                type="button" :disabled="!canGrantAll"
                class="flex items-start gap-2 rounded-xl border p-2.5 text-left transition disabled:cursor-not-allowed disabled:opacity-50"
                :class="mode === 'all' ? 'border-primary/50 bg-primary/5 ring-1 ring-primary/30' : 'border-border hover:border-primary/30'"
                :title="canGrantAll ? '' : 'Solo quien tiene alcance general puede conceder todas las sucursales.'"
                @click="mode = 'all'"
            >
                <Globe2 class="mt-0.5 size-4 shrink-0 text-primary" />
                <span>
                    <span class="block text-xs font-bold text-foreground">Todas las sucursales</span>
                    <span class="block text-[11px] leading-4 text-muted-foreground">Alcance general: incluye el consolidado y los reportes generales.</span>
                </span>
            </button>
            <button
                type="button"
                class="flex items-start gap-2 rounded-xl border p-2.5 text-left transition"
                :class="mode === 'branches' ? 'border-primary/50 bg-primary/5 ring-1 ring-primary/30' : 'border-border hover:border-primary/30'"
                @click="mode = 'branches'"
            >
                <Building2 class="mt-0.5 size-4 shrink-0 text-primary" />
                <span>
                    <span class="block text-xs font-bold text-foreground">Sucursales específicas</span>
                    <span class="block text-[11px] leading-4 text-muted-foreground">Solo consulta y exporta la información de las sucursales elegidas.</span>
                </span>
            </button>
        </div>

        <div v-if="mode === 'branches'" class="rounded-xl border border-border p-3">
            <div class="mb-2 flex items-center justify-between gap-2">
                <p class="text-[11px] font-bold text-foreground">{{ branchIds.length }} de {{ branches.length }} sucursal(es)</p>
                <button v-if="branches.length > 1" type="button" class="text-[11px] font-semibold text-primary hover:underline" @click="toggleAll">
                    {{ allSelected ? 'Quitar todas' : 'Marcar todas' }}
                </button>
            </div>
            <div class="grid gap-1.5 sm:grid-cols-2 lg:grid-cols-3">
                <button
                    v-for="b in branches" :key="b.id" type="button"
                    class="flex items-center gap-1.5 rounded-lg border px-2 py-1.5 text-left text-xs transition"
                    :class="branchIds.includes(b.id) ? 'border-primary/50 bg-primary/5 font-semibold text-foreground' : 'border-border text-muted-foreground hover:border-primary/30'"
                    :aria-pressed="branchIds.includes(b.id)"
                    @click="toggle(b.id)"
                >
                    <Check class="size-3.5 shrink-0" :class="branchIds.includes(b.id) ? 'text-primary' : 'text-transparent'" /> {{ b.name }}
                </button>
            </div>
            <p v-if="branches.length === 0" class="text-[11px] text-muted-foreground">No tienes sucursales que puedas asignar.</p>
        </div>
        <p v-if="error" class="text-xs font-semibold text-rose-600">{{ error }}</p>
    </div>
</template>
