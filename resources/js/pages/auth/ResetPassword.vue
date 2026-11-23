<script setup lang="ts">
/**
 * Pantalla "Crea tu nueva contraseña" (Fortify, resetPasswordView).
 * Se abre desde el enlace del correo de recuperación; recibe el token y el correo
 * y envía la nueva contraseña a la ruta Wayfinder password.update.
 */
import { Form, Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/password';

/** Props del layout de autenticación (título y descripción de la tarjeta). */
defineOptions({
    layout: {
        title: 'Crea tu nueva contraseña',
        description:
            'Escribe una contraseña nueva para tu cuenta. Al guardarla podrás iniciar sesión con ella.',
    },
});

/** Props desde Fortify: token de restablecimiento y correo de la cuenta. */
const props = defineProps<{
    token: string;
    email: string;
}>();

/** Copia local del correo para enlazar el campo del formulario. */
const inputEmail = ref(props.email);
</script>

<template>
    <Head title="Restablecer contraseña" />

    <Form
        v-bind="update.form()"
        :transform="(data) => ({ ...data, token, email })"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-6">
            <div class="grid gap-2">
                <Label for="email">Correo electrónico</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    autocomplete="email"
                    v-model="inputEmail"
                    class="mt-1 block w-full"
                    readonly
                />
                <InputError :message="errors.email" class="mt-2" />
            </div>

            <div class="grid gap-2">
                <Label for="password">Nueva contraseña</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    autofocus
                    placeholder="Nueva contraseña"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">Confirmar contraseña</Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    class="mt-1 block w-full"
                    placeholder="Repite la nueva contraseña"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="mt-4 w-full"
                :disabled="processing"
                data-test="reset-password-button"
            >
                <Spinner v-if="processing" />
                {{ processing ? 'Guardando…' : 'Guardar nueva contraseña' }}
            </Button>
        </div>
    </Form>
</template>
