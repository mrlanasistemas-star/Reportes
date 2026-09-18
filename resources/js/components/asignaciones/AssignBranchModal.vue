<script setup lang="ts">
import { PenSquare, Search, X } from 'lucide-vue-next'
import Swal from 'sweetalert2'
import { computed, ref, watch } from 'vue'
import SelectField from '@/components/forms/SelectField.vue'
import type { Branch } from '@/types/asignaciones'

const props = defineProps<{
    open: boolean
    saving: boolean
    employeeName: string
    periodLabel?: string | null
    branches: Branch[]
    initialBranchId?: string
    initialNotes?: string
}>()

const emit = defineEmits<{
    close: []
    save: [payload: { branchId: string; notes: string }]
}>()

const branchQuery = ref('')
const branchId = ref('')
const notes = ref('')

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
return
}

        branchQuery.value = ''
        branchId.value = props.initialBranchId ?? ''
        notes.value = props.initialNotes ?? ''
    },
)

const modalBranches = computed(() => {
    const q = branchQuery.value.trim().toLowerCase()

    if (!q) {
return props.branches
}

    return props.branches.filter((b) => b.name.toLowerCase().includes(q))
})

const modalBranchOptions = computed(() => modalBranches.value.map((b) => ({ value: b.id, label: b.name })))

async function save() {
    if (!branchId.value) {
        await Swal.fire({ title: 'Selecciona una sucursal', icon: 'warning', confirmButtonText: 'Entendido' })

        return
    }

    emit('save', { branchId: branchId.value, notes: notes.value })
}
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-200"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="open"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 backdrop-blur-sm"
                @click.self="$emit('close')"
            >
                <Transition
                    enter-active-class="transition duration-200"
                    enter-from-class="scale-95 opacity-0"
                    enter-to-class="scale-100 opacity-100"
                    leave-active-class="transition duration-150"
                    leave-from-class="scale-100 opacity-100"
                    leave-to-class="scale-95 opacity-0"
                >
                    <div v-if="open" class="w-full max-w-md overflow-hidden rounded-[2rem] bg-white shadow-2xl dark:bg-slate-900">
                        <!-- Header -->
                        <div class="flex items-start justify-between border-b px-6 py-5">
                            <div>
                                <p class="text-xs font-black tracking-widest text-sky-500 uppercase">Asignar sucursal</p>
                                <h2 class="mt-1 truncate text-xl font-black">{{ employeeName }}</h2>
                            </div>
                            <button
                                type="button"
                                class="mt-0.5 rounded-xl p-2 text-slate-400 transition duration-200 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-slate-800 dark:hover:text-slate-200"
                                @click="$emit('close')"
                            >
                                <X class="size-5" />
                            </button>
                        </div>

                        <!-- Body -->
                        <div class="space-y-4 p-6">
                            <div
                                v-if="periodLabel"
                                class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-slate-700 dark:bg-slate-800"
                            >
                                <p class="text-xs text-slate-500 dark:text-slate-400">Periodo</p>
                                <p class="mt-0.5 text-sm font-bold">{{ periodLabel }}</p>
                            </div>

                            <div class="space-y-2">
                                <label class="text-sm font-semibold">Sucursal</label>
                                <div class="relative">
                                    <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <input v-model="branchQuery" type="text" class="app-input h-10 pl-10" placeholder="Filtrar sucursales..." />
                                </div>
                                <SelectField v-model="branchId" :options="modalBranchOptions" placeholder="— Selecciona sucursal —" />
                                <p v-if="!modalBranches.length" class="text-xs text-rose-500">Sin resultados para "{{ branchQuery }}".</p>
                            </div>

                            <div class="space-y-2">
                                <label class="text-sm font-semibold">
                                    Notas <span class="font-normal text-muted-foreground">(opcional)</span>
                                </label>
                                <textarea v-model="notes" class="app-input min-h-[80px] resize-none" placeholder="Razón del ajuste manual, aclaración, etc." />
                            </div>
                        </div>

                        <!-- Footer -->
                        <div class="flex items-center justify-end gap-3 border-t px-6 py-4">
                            <button
                                type="button"
                                class="h-10 rounded-2xl px-4 text-sm font-semibold text-slate-600 transition hover:bg-slate-100"
                                @click="$emit('close')"
                            >
                                Cancelar
                            </button>
                            <button
                                type="button"
                                class="inline-flex h-10 items-center gap-2 rounded-2xl bg-sky-500 px-5 text-sm font-black text-white transition hover:bg-sky-400 disabled:cursor-not-allowed disabled:opacity-50"
                                :disabled="saving"
                                @click="save"
                            >
                                <PenSquare class="size-4" />
                                {{ saving ? 'Guardando...' : 'Guardar asignación' }}
                            </button>
                        </div>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>
