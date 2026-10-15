<script setup lang="ts">
// Contraseña manual con confirmación + botón "Generar" (prioridad 8). La
// contraseña viaja al backend, que SIEMPRE la guarda con hash.
import { Eye, EyeOff, Sparkles } from 'lucide-vue-next'
import { ref } from 'vue'
import { generatePassword } from './users'

defineProps<{ error?: string | null; confirmationError?: string | null }>()
const password = defineModel<string>('password', { required: true })
const confirmation = defineModel<string>('confirmation', { required: true })
const visible = ref(false)

function generate() {
    const value = generatePassword()
    password.value = value
    confirmation.value = value
    visible.value = true
}
</script>

<template>
    <div class="space-y-2">
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="space-y-1">
                <span class="text-xs font-bold text-foreground">Contraseña</span>
                <span class="relative block">
                    <input v-model="password" :type="visible ? 'text' : 'password'" autocomplete="new-password" placeholder="Mínimo 8 caracteres" class="app-input h-10 pr-10 text-sm">
                    <button type="button" class="absolute inset-y-0 right-2 flex items-center text-muted-foreground hover:text-foreground" :aria-label="visible ? 'Ocultar contraseña' : 'Mostrar contraseña'" @click="visible = !visible">
                        <EyeOff v-if="visible" class="size-4" /><Eye v-else class="size-4" />
                    </button>
                </span>
                <span v-if="error" class="text-xs font-semibold text-rose-600">{{ error }}</span>
            </label>
            <label class="space-y-1">
                <span class="text-xs font-bold text-foreground">Confirmar contraseña</span>
                <input v-model="confirmation" :type="visible ? 'text' : 'password'" autocomplete="new-password" placeholder="Repite la contraseña" class="app-input h-10 text-sm">
                <span v-if="confirmationError" class="text-xs font-semibold text-rose-600">{{ confirmationError }}</span>
                <span v-else-if="confirmation && confirmation !== password" class="text-xs font-semibold text-amber-600">Todavía no coincide con la contraseña.</span>
            </label>
        </div>
        <button type="button" class="inline-flex items-center gap-1.5 text-xs font-bold text-primary hover:underline" @click="generate">
            <Sparkles class="size-3.5" /> Generar una contraseña segura
        </button>
    </div>
</template>
