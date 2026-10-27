<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3'
import { CircleAlert, Eye, EyeOff, Lock, Mail } from 'lucide-vue-next'
import { ref } from 'vue'
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
    <!-- robots/description/canonical/OG/JSON-LD salen del HTML servido por PHP
         (resources/views/app.blade.php + config/seo.php). Aquí solo el título, y
         IDÉNTICO al del servidor para que Inertia no lo cambie al montar. -->
    <Head title="Reportes Mr Lana — Iniciar sesión" />

    <div class="grid min-h-svh lg:grid-cols-2">
        <AuthAsidePanel />

        <AuthFormPanel
            mobile-title="Iniciar sesión"
            desktop-title="Iniciar sesión"
            desktop-subtitle="Entra a Reportes Mr Lana con el correo y la contraseña que te asignó tu administrador."
            :status="status"
        >
            <Form v-bind="store.form()" :reset-on-success="['password']" v-slot="{ errors, processing }" class="app-stagger space-y-5" style="--stagger-offset: 120ms" novalidate>
                <!-- Resumen del error de acceso (credenciales, cuenta bloqueada, demasiados intentos) -->
                <div v-if="errors.email" role="alert" class="app-banner app-banner-danger">
                    <CircleAlert aria-hidden="true" />
                    <p>{{ errors.email }}</p>
                </div>

                <div class="space-y-2">
                    <Label for="email">Correo electrónico</Label>
                    <div class="group relative">
                        <Mail
                            class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground transition-colors duration-200 group-focus-within:text-primary"
                            aria-hidden="true"
                        />
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            required
                            autofocus
                            autocomplete="email"
                            placeholder="correo@ejemplo.com"
                            class="h-11 rounded-xl pl-10"
                            :aria-invalid="!!errors.email"
                        />
                    </div>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Contraseña</Label>
                        <TextLink
                            v-if="canResetPassword"
                            :href="request()"
                            class="text-xs font-semibold text-primary underline-offset-4 hover:underline"
                        >
                            ¿Olvidaste tu contraseña?
                        </TextLink>
                    </div>
                    <div class="group relative">
                        <Lock
                            class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground transition-colors duration-200 group-focus-within:text-primary"
                            aria-hidden="true"
                        />
                        <Input
                            id="password"
                            :type="showPassword ? 'text' : 'password'"
                            name="password"
                            required
                            autocomplete="current-password"
                            placeholder="••••••••"
                            class="h-11 rounded-xl pr-11 pl-10"
                            :aria-invalid="!!errors.password"
                        />
                        <button
                            type="button"
                            class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-xl text-muted-foreground transition-colors hover:text-foreground"
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
                    size="lg"
                    class="h-11 w-full rounded-xl text-[0.95rem] font-semibold shadow-md shadow-primary/20 hover:-translate-y-px"
                >
                    <Spinner v-if="processing" class="size-4" />
                    <span>{{ processing ? 'Entrando…' : 'Entrar' }}</span>
                </Button>
            </Form>
        </AuthFormPanel>
    </div>
</template>
