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
                            class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground transition-colors duration-200 group-focus-within:text-primary"
                            aria-hidden="true"
                        />
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            autocomplete="off"
                            autofocus
                            placeholder="correo@ejemplo.com"
                            class="h-11 rounded-xl pl-10"
                            :aria-invalid="!!errors.email"
                        />
                    </div>
                    <InputError :message="errors.email" />
                </div>

                <Button
                    type="submit"
                    :disabled="processing"
                    data-test="email-password-reset-link-button"
                    size="lg"
                    class="h-11 w-full rounded-xl text-[0.95rem] font-semibold"
                >
                    <Spinner v-if="processing" class="size-4" />
                    <span>{{ processing ? 'Enviando enlace…' : 'Enviar enlace de recuperación' }}</span>
                </Button>
            </Form>

            <template #footer>
                <p class="mt-6 text-center text-sm text-muted-foreground">
                    ¿Recordaste tu contraseña?
                    <TextLink :href="login()" class="font-semibold text-primary underline-offset-4 hover:underline">
                        Volver a iniciar sesión
                    </TextLink>
                </p>
            </template>
        </AuthFormPanel>
    </div>
</template>
