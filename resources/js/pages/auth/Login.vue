<script setup lang="ts">
import { ref } from 'vue'
import { Form, Head } from '@inertiajs/vue3'
import { Eye, EyeOff, Lock, Mail } from 'lucide-vue-next'
import AuthAsidePanel from '@/components/auth/AuthAsidePanel.vue'
import AuthFormPanel from '@/components/auth/AuthFormPanel.vue'
import InputError from '@/components/InputError.vue'
import TextLink from '@/components/TextLink.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Spinner } from '@/components/ui/spinner'
import { store } from '@/routes/login'
import { request } from '@/routes/password'

defineProps<{
    status?: string
    canResetPassword: boolean
}>()

const showPassword = ref(false)
</script>

<template>
    <!-- robots/description ya salen correctos desde el HTML servido por PHP
         (resources/views/app.blade.php) — decidido ahí por ruta, no aquí, porque
         un crawler que no ejecute JavaScript nunca vería un override hecho acá. -->
    <Head title="Iniciar sesión" />

    <div class="grid min-h-svh lg:grid-cols-2">
        <AuthAsidePanel />

        <AuthFormPanel
            mobile-title="Bienvenido"
            desktop-title="Bienvenido de vuelta"
            desktop-subtitle="Ingresa tus credenciales para continuar."
            :status="status"
        >
            <Form v-bind="store.form()" :reset-on-success="['password']" v-slot="{ errors, processing }" class="space-y-5">
                <div class="space-y-2">
                    <Label for="email">Correo electrónico</Label>
                    <div class="group relative">
                        <Mail
                            class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground transition-colors duration-200 group-focus-within:text-indigo-500"
                        />
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            required
                            autofocus
                            autocomplete="email"
                            placeholder="correo@ejemplo.com"
                            class="h-11 rounded-xl pl-10 transition duration-200 focus-visible:ring-2 focus-visible:ring-indigo-500/40"
                        />
                    </div>
                    <InputError :message="errors.email" />
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Contraseña</Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-xs font-semibold text-indigo-600 transition duration-200 hover:text-indigo-500 hover:underline dark:text-indigo-400"
                        >
                            ¿Olvidaste tu contraseña?
                        </TextLink>
                    </div>
                    <div class="group relative">
                        <Lock
                            class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground transition-colors duration-200 group-focus-within:text-indigo-500"
                        />
                        <Input
                            id="password"
                            :type="showPassword ? 'text' : 'password'"
                            name="password"
                            required
                            autocomplete="current-password"
                            placeholder="••••••••"
                            class="h-11 rounded-xl pr-11 pl-10 transition duration-200 focus-visible:ring-2 focus-visible:ring-indigo-500/40"
                        />
                        <button
                            type="button"
                            class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-muted-foreground transition duration-200 hover:scale-110 hover:text-indigo-500 active:scale-95"
                            :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                            @click="showPassword = !showPassword"
                        >
                            <EyeOff v-if="showPassword" class="size-4" />
                            <Eye v-else class="size-4" />
                        </button>
                    </div>
                    <InputError :message="errors.password" />
                </div>

                <Button
                    type="submit"
                    :disabled="processing"
                    data-test="login-button"
                    class="group relative h-11 w-full overflow-hidden rounded-xl bg-indigo-600 font-bold shadow-lg shadow-indigo-600/20 transition duration-200 hover:-translate-y-0.5 hover:bg-indigo-500 hover:shadow-xl hover:shadow-indigo-600/30 active:translate-y-0"
                >
                    <span
                        class="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/25 to-transparent transition-transform duration-700 group-hover:translate-x-full"
                    ></span>
                    <span class="relative flex items-center justify-center gap-2">
                        <Spinner v-if="processing" class="size-4" />
                        <span>{{ processing ? 'Validando…' : 'Iniciar sesión' }}</span>
                    </span>
                </Button>
            </Form>
        </AuthFormPanel>
    </div>
</template>
