<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { CheckCircle2, KeyRound, ShieldCheck, Smartphone } from 'lucide-vue-next';
import { onUnmounted, ref } from 'vue';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import SettingsCard from '@/components/settings/SettingsCard.vue';
import TwoFactorRecoveryCodes from '@/components/TwoFactorRecoveryCodes.vue';
import TwoFactorSetupModal from '@/components/TwoFactorSetupModal.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { edit } from '@/routes/security';
import { disable, enable } from '@/routes/two-factor';

type Props = {
    canManageTwoFactor?: boolean;
    requiresConfirmation?: boolean;
    twoFactorEnabled?: boolean;
};

withDefaults(defineProps<Props>(), {
    canManageTwoFactor: false,
    requiresConfirmation: false,
    twoFactorEnabled: false,
});

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Seguridad',
                href: edit(),
            },
        ],
    },
});

const { hasSetupData, clearTwoFactorAuthData } = useTwoFactorAuth();
const showSetupModal = ref<boolean>(false);

onUnmounted(() => clearTwoFactorAuthData());
</script>

<template>
    <Head title="Seguridad" />

    <h2 class="sr-only">Configuración de seguridad</h2>

    <Form
        v-bind="SecurityController.update.form()"
        :options="{ preserveScroll: true }"
        reset-on-success
        :reset-on-error="['password', 'password_confirmation', 'current_password']"
        v-slot="{ errors, processing, recentlySuccessful }"
    >
        <SettingsCard
            :icon="KeyRound"
            title="Cambiar contraseña"
            description="Usa una contraseña larga y que no uses en otros sitios. Al guardarla seguirás con la sesión abierta."
        >
            <div class="grid gap-5 md:grid-cols-2">
                <div class="grid content-start gap-2 md:col-span-2 md:max-w-[calc(50%-0.625rem)]">
                    <Label for="current_password">Contraseña actual</Label>
                    <PasswordInput
                        id="current_password"
                        name="current_password"
                        class="w-full"
                        autocomplete="current-password"
                        placeholder="Escribe tu contraseña actual"
                        :aria-invalid="!!errors.current_password"
                    />
                    <InputError :message="errors.current_password" />
                </div>

                <div class="grid content-start gap-2">
                    <Label for="password">Nueva contraseña</Label>
                    <PasswordInput
                        id="password"
                        name="password"
                        class="w-full"
                        autocomplete="new-password"
                        placeholder="Escribe tu nueva contraseña"
                        :aria-invalid="!!errors.password"
                    />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid content-start gap-2">
                    <Label for="password_confirmation">Confirmar nueva contraseña</Label>
                    <PasswordInput
                        id="password_confirmation"
                        name="password_confirmation"
                        class="w-full"
                        autocomplete="new-password"
                        placeholder="Repite la nueva contraseña"
                        :aria-invalid="!!errors.password_confirmation"
                    />
                    <InputError :message="errors.password_confirmation" />
                </div>
            </div>

            <template #footer>
                <Transition
                    enter-active-class="transition ease-in-out duration-300"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out duration-300"
                    leave-to-class="opacity-0"
                >
                    <p v-show="recentlySuccessful" role="status" class="flex items-center gap-1.5 text-sm font-medium text-success">
                        <CheckCircle2 class="size-4" aria-hidden="true" />
                        Contraseña actualizada.
                    </p>
                </Transition>
                <Button type="submit" :disabled="processing" data-test="update-password-button">
                    {{ processing ? 'Guardando…' : 'Guardar contraseña' }}
                </Button>
            </template>
        </SettingsCard>
    </Form>

    <SettingsCard
        v-if="canManageTwoFactor"
        :icon="Smartphone"
        title="Verificación en dos pasos"
        description="Además de tu contraseña, al iniciar sesión se te pedirá un código de 6 dígitos de una app de autenticación (Google Authenticator, Microsoft Authenticator, etc.)."
    >
        <template #badge>
            <span :class="['app-pill', twoFactorEnabled ? 'app-pill-success' : 'app-pill-muted']">
                {{ twoFactorEnabled ? 'Activada' : 'Desactivada' }}
            </span>
        </template>

        <template v-if="!twoFactorEnabled">
            <div class="app-banner app-banner-info">
                <ShieldCheck aria-hidden="true" />
                <p>Recomendado para administradores: si alguien obtiene tu contraseña, no podrá entrar sin el código de tu teléfono.</p>
            </div>

            <div class="mt-4">
                <Button v-if="hasSetupData" @click="showSetupModal = true">
                    <ShieldCheck class="size-4" />
                    Continuar configuración
                </Button>

                <Form v-else v-bind="enable.form()" @success="showSetupModal = true" #default="{ processing }">
                    <Button type="submit" :disabled="processing">
                        <ShieldCheck class="size-4" />
                        {{ processing ? 'Preparando…' : 'Activar verificación en dos pasos' }}
                    </Button>
                </Form>
            </div>
        </template>

        <template v-else>
            <div class="app-banner app-banner-success">
                <CheckCircle2 aria-hidden="true" />
                <p>Tu cuenta pide un código de tu app de autenticación cada vez que inicias sesión.</p>
            </div>

            <div class="mt-5">
                <TwoFactorRecoveryCodes />
            </div>

            <div class="app-danger-zone mt-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <p class="text-sm font-semibold text-foreground">Desactivar verificación en dos pasos</p>
                    <p class="app-help">Tu cuenta volverá a protegerse solo con la contraseña.</p>
                </div>
                <Form v-bind="disable.form()" #default="{ processing }">
                    <Button variant="destructive" type="submit" :disabled="processing">
                        {{ processing ? 'Desactivando…' : 'Desactivar' }}
                    </Button>
                </Form>
            </div>
        </template>

        <TwoFactorSetupModal
            v-model:isOpen="showSetupModal"
            :requiresConfirmation="requiresConfirmation"
            :twoFactorEnabled="twoFactorEnabled"
        />
    </SettingsCard>
</template>
