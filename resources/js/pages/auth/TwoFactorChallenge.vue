<script setup lang="ts">
/**
 * Pantalla de verificación en dos pasos (Fortify, twoFactorChallengeView).
 * Pide el código de 6 dígitos de la app de autenticación o, alternativamente,
 * un código de recuperación. Envía a la ruta Wayfinder two-factor/login (store).
 * El título/descripción del layout cambian según el modo con setLayoutProps.
 */
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { computed, ref, watchEffect } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { store } from '@/routes/two-factor/login';
import type { TwoFactorConfigContent } from '@/types';

/** true = se está capturando un código de recuperación en lugar del OTP. */
const showRecoveryInput = ref<boolean>(false);

/** Textos (título, descripción y botón de alternar) según el modo activo: código OTP o de recuperación. */
const authConfigContent = computed<TwoFactorConfigContent>(() => {
    if (showRecoveryInput.value) {
        return {
            title: 'Código de recuperación',
            description:
                'Escribe uno de los códigos de recuperación que guardaste al activar la verificación en dos pasos.',
            buttonText: 'usar el código de mi app de autenticación',
        };
    }

    return {
        title: 'Código de verificación',
        description:
            'Abre tu app de autenticación y escribe el código de 6 dígitos que muestra para MR LANA.',
        buttonText: 'usar un código de recuperación',
    };
});

/** Mantiene sincronizados el título y la descripción del AuthLayout con el modo activo. */
watchEffect(() => {
    setLayoutProps({
        title: authConfigContent.value.title,
        description: authConfigContent.value.description,
    });
});

/**
 * Cambia entre modo OTP y modo código de recuperación, limpiando errores del
 * formulario y el código capturado para no enviar datos del modo anterior.
 * @param clearErrors función del slot de <Form> que borra los errores visibles.
 */
const toggleRecoveryMode = (clearErrors: () => void): void => {
    showRecoveryInput.value = !showRecoveryInput.value;
    clearErrors();
    code.value = '';
};

/** Código OTP de 6 dígitos capturado en los recuadros. */
const code = ref<string>('');
</script>

<template>
    <Head title="Verificación en dos pasos" />

    <div class="space-y-6">
        <template v-if="!showRecoveryInput">
            <!-- Modo código OTP de la app de autenticación -->
            <Form
                v-bind="store.form()"
                class="space-y-4"
                reset-on-error
                @error="code = ''"
                #default="{ errors, processing, clearErrors }"
            >
                <input type="hidden" name="code" :value="code" />
                <div
                    class="flex flex-col items-center justify-center space-y-3 text-center"
                >
                    <div class="flex w-full items-center justify-center">
                        <InputOTP
                            id="otp"
                            v-model="code"
                            aria-label="Código de 6 dígitos de tu app de autenticación"
                            :maxlength="6"
                            :disabled="processing"
                            autofocus
                        >
                            <InputOTPGroup>
                                <InputOTPSlot
                                    v-for="index in 6"
                                    :key="index"
                                    :index="index - 1"
                                />
                            </InputOTPGroup>
                        </InputOTP>
                    </div>
                    <InputError :message="errors.code" />
                </div>
                <Button type="submit" class="w-full" :disabled="processing">{{
                    processing ? 'Verificando…' : 'Continuar'
                }}</Button>
                <div class="text-center text-sm text-muted-foreground">
                    <span>o bien, </span>
                    <button
                        type="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                        @click="() => toggleRecoveryMode(clearErrors)"
                    >
                        {{ authConfigContent.buttonText }}
                    </button>
                </div>
            </Form>
        </template>

        <template v-else>
            <!-- Modo código de recuperación -->
            <Form
                v-bind="store.form()"
                class="space-y-4"
                reset-on-error
                #default="{ errors, processing, clearErrors }"
            >
                <Input
                    name="recovery_code"
                    type="text"
                    placeholder="Código de recuperación"
                    aria-label="Código de recuperación"
                    autocomplete="off"
                    autocapitalize="off"
                    spellcheck="false"
                    :autofocus="showRecoveryInput"
                    required
                />
                <InputError :message="errors.recovery_code" />
                <Button type="submit" class="w-full" :disabled="processing">{{
                    processing ? 'Verificando…' : 'Continuar'
                }}</Button>

                <div class="text-center text-sm text-muted-foreground">
                    <span>o bien, </span>
                    <button
                        type="button"
                        class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                        @click="() => toggleRecoveryMode(clearErrors)"
                    >
                        {{ authConfigContent.buttonText }}
                    </button>
                </div>
            </Form>
        </template>
    </div>
</template>
