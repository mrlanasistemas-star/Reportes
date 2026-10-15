<script setup lang="ts">
// Fase 6.4 — editar el alcance por sucursal de un usuario existente. El cambio
// aplica desde su siguiente petición (el backend no guarda caché de alcance).
import { useForm } from '@inertiajs/vue3'
import { Loader2, MapPinned } from 'lucide-vue-next'
import { computed, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog'
import BranchScopePicker from './BranchScopePicker.vue'
import type { BranchOption, BranchScopeMode, UserRow } from './users'

const props = defineProps<{ user: UserRow | null; branches: BranchOption[]; canGrantAll: boolean }>()
const open = defineModel<boolean>('open', { default: false })

const form = useForm({ branch_scope: 'branches' as BranchScopeMode, branch_ids: [] as number[] })

watch(open, (v) => {
    if (v && props.user) {
        form.clearErrors()
        const scope = props.user.data_scope
        form.branch_scope = scope.general && props.canGrantAll ? 'all' : 'branches'
        form.branch_ids = scope.general ? [] : [...scope.branch_ids]
    }
})

const canSubmit = computed(() => form.branch_scope === 'all' || form.branch_ids.length > 0)

function submit() {
    if (!props.user) {
        return
    }

    form.put(props.user.urls.update_branch_scope, { preserveScroll: true, onSuccess: () => {
        open.value = false
    } })
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="max-h-[92vh] w-[96vw] max-w-xl overflow-y-auto p-0 sm:max-w-xl">
            <div class="border-b border-border px-6 py-4">
                <DialogTitle class="flex items-center gap-2 text-base font-black"><MapPinned class="size-4 text-primary" /> Alcance por sucursal</DialogTitle>
                <DialogDescription class="mt-1 text-xs text-muted-foreground">
                    {{ user?.name }} — hoy: <strong class="text-foreground">{{ user?.data_scope.label }}</strong>.
                    Define de qué sucursales puede consultar y exportar información (Dashboard, Reportes, Colaboradores, OKR y documentos).
                </DialogDescription>
            </div>
            <div class="p-6">
                <BranchScopePicker
                    v-model:mode="form.branch_scope" v-model:branch-ids="form.branch_ids" :branches="branches" :can-grant-all="canGrantAll"
                    :error="form.errors.branch_ids || form.errors.branch_scope"
                />
            </div>
            <div class="flex justify-end gap-2 border-t border-border px-6 py-4">
                <Button type="button" variant="outline" class="h-10 rounded-xl" @click="open = false">Cancelar</Button>
                <Button type="button" class="h-10 gap-1.5 rounded-xl" :disabled="form.processing || !canSubmit" @click="submit">
                    <Loader2 v-if="form.processing" class="size-4 animate-spin" /> Guardar alcance
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
