<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3'
import { Mail } from 'lucide-vue-next'
import AuthAsidePanel from '@/components/auth/AuthAsidePanel.vue'
import AuthFormPanel from '@/components/auth/AuthFormPanel.vue'
import InputError from '@/components/InputError.vue'
import TextLink from '@/components/TextLink.vue'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Spinner } from '@/components/ui/spinner'
import { login } from '@/routes'
import { email } from '@/routes/password'

defineProps<{
    status?: string
}>()
</script>

<template>
    <Head title="Recuperar contraseña" />

    <div class="grid min-h-svh lg:grid-cols-2">
        <AuthAsidePanel />

        <AuthFormPanel
            mobile-title="Recuperar contraseña"
            mobile-subtitle="Ingresa tu correo y te enviaremos un enlace para restablecer tu acceso."
            desktop-title="Recuperar contraseña"
            desktop-subtitle="Ingresa tu correo y te enviaremos un enlace para restablecer tu acceso."
            :status="status"
        >
            <Form v-bind="email.form()" v-slot="{ errors, processing }" class="space-y-5">
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
                            autocomplete="off"
                            autofocus
                            placeholder="correo@ejemplo.com"
                            class="h-11 rounded-xl pl-10 transition duration-200 focus-visible:ring-2 focus-visible:ring-indigo-500/40"
                        />
                    </div>
                    <InputError :message="errors.email" />
                </div>

                <Button
                    type="submit"
                    :disabled="processing"
                    data-test="email-password-reset-link-button"
                    class="group relative h-11 w-full overflow-hidden rounded-xl bg-indigo-600 font-bold shadow-lg shadow-indigo-600/20 transition duration-200 hover:-translate-y-0.5 hover:bg-indigo-500 hover:shadow-xl hover:shadow-indigo-600/30 active:translate-y-0"
                >
                    <span
                        class="absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/25 to-transparent transition-transform duration-700 group-hover:translate-x-full"
                    ></span>
                    <span class="relative flex items-center justify-center gap-2">
                        <Spinner v-if="processing" class="size-4" />
                        <span>{{ processing ? 'Enviando enlace…' : 'Enviar enlace de recuperación' }}</span>
                    </span>
                </Button>
            </Form>

            <template #footer>
                <p class="mt-6 text-center text-sm text-muted-foreground">
                    ¿Recordaste tu contraseña?
                    <TextLink :href="login()" class="font-semibold text-indigo-600 transition duration-200 hover:text-indigo-500 hover:underline dark:text-indigo-400">
                        Volver a iniciar sesión
                    </TextLink>
                </p>
            </template>
        </AuthFormPanel>
    </div>
</template>
