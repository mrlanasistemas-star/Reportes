<script setup lang="ts">
// Restablecer acceso (prioridad 8): contraseña generada (se muestra una sola
// vez) o manual con confirmación. Cierra las sesiones abiertas del usuario.
import { useForm } from '@inertiajs/vue3'
import { KeyRound, Loader2 } from 'lucide-vue-next'
import { computed, watch } from 'vue'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog'
import PasswordFields from './PasswordFields.vue'
import type { UserRow } from './users'

const props = defineProps<{ user: UserRow | null }>()
const open = defineModel<boolean>('open', { default: false })

const form = useForm({ password_mode: 'auto' as 'auto' | 'manual', password: '', password_confirmation: '' })

watch(open, (v) => {
    if (v) {
        form.reset()
        form.clearErrors()
    }
})

const canSubmit = computed(() => form.password_mode === 'auto' || (form.password.length >= 8 && form.password === form.password_confirmation))

function submit() {
    if (!props.user) {
        return
    }

    form.post(props.user.urls.reset_password, { preserveScroll: true, onSuccess: () => {
        open.value = false
    } })
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="w-[96vw] max-w-lg p-0 sm:max-w-lg">
            <div class="space-y-1 border-b border-border px-6 py-5">
                <DialogTitle class="flex items-center gap-2 text-base font-black"><KeyRound class="size-5 text-primary" /> Restablecer acceso de {{ user?.name }}</DialogTitle>
                <DialogDescription class="text-xs text-muted-foreground">La contraseña actual dejará de funcionar y se cerrarán sus sesiones abiertas.</DialogDescription>
            </div>
            <div class="space-y-4 p-6">
                <div class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="m in [{ key: 'auto', title: 'Generar contraseña', hint: 'La verás una sola vez para copiarla.' }, { key: 'manual', title: 'Escribirla yo', hint: 'Con confirmación.' }] as const"
                        :key="m.key" type="button"
                        class="rounded-xl border p-2.5 text-left transition"
                        :class="form.password_mode === m.key ? 'border-primary/50 bg-primary/5 ring-1 ring-primary/30' : 'border-border hover:border-primary/30'"
                        @click="form.password_mode = m.key"
                    >
                        <span class="block text-xs font-bold text-foreground">{{ m.title }}</span>
                        <span class="block text-[11px] text-muted-foreground">{{ m.hint }}</span>
                    </button>
                </div>
                <PasswordFields v-if="form.password_mode === 'manual'" v-model:password="form.password" v-model:confirmation="form.password_confirmation" :error="form.errors.password" />
            </div>
            <div class="flex justify-end gap-2 border-t border-border px-6 py-4">
                <Button type="button" variant="outline" class="h-10 rounded-xl" @click="open = false">Cancelar</Button>
                <Button type="button" class="h-10 gap-1.5 rounded-xl" :disabled="form.processing || !canSubmit" @click="submit">
                    <Loader2 v-if="form.processing" class="size-4 animate-spin" /> Restablecer contraseña
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>
