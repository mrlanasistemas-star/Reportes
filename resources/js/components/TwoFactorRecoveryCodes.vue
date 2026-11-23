<script setup lang="ts">
/**
 * TwoFactorRecoveryCodes — tarjeta de códigos de recuperación de la verificación en dos pasos.
 *
 * Se usa en pages/settings/Security cuando la verificación en dos pasos está
 * activa. Permite mostrar/ocultar los códigos y regenerarlos (POST a
 * regenerateRecoveryCodes). Los códigos se obtienen con useTwoFactorAuth.
 * Sin props ni emits.
 */
import { Form } from '@inertiajs/vue3';
import { Eye, EyeOff, LockKeyhole, RefreshCw } from 'lucide-vue-next';
import { nextTick, onMounted, ref, useTemplateRef } from 'vue';
import AlertError from '@/components/AlertError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useTwoFactorAuth } from '@/composables/useTwoFactorAuth';
import { regenerateRecoveryCodes } from '@/routes/two-factor';

const { recoveryCodesList, fetchRecoveryCodes, errors } = useTwoFactorAuth();
/** true = la lista de códigos está visible. */
const isRecoveryCodesVisible = ref<boolean>(false);
/** Sección con los códigos; se desplaza a la vista al mostrarlos. */
const recoveryCodeSectionRef = useTemplateRef('recoveryCodeSectionRef');

/**
 * Muestra u oculta los códigos. Si aún no se han descargado, los pide antes de
 * mostrarlos y luego desplaza la vista hasta ellos.
 */
const toggleRecoveryCodesVisibility = async () => {
    if (!isRecoveryCodesVisible.value && !recoveryCodesList.value.length) {
        await fetchRecoveryCodes();
    }

    isRecoveryCodesVisible.value = !isRecoveryCodesVisible.value;

    if (isRecoveryCodesVisible.value) {
        await nextTick();
        recoveryCodeSectionRef.value?.scrollIntoView({ behavior: 'smooth' });
    }
};

/** Al montar, descarga los códigos si todavía no están en memoria. */
onMounted(async () => {
    if (!recoveryCodesList.value.length) {
        await fetchRecoveryCodes();
    }
});
</script>

<template>
    <Card class="w-full gap-4 rounded-xl py-5 shadow-none">
        <CardHeader>
            <CardTitle class="flex gap-3">
                <LockKeyhole class="size-4" />Códigos de recuperación
            </CardTitle>
            <CardDescription>
                Te permiten entrar si pierdes o cambias tu teléfono. Guárdalos
                en un lugar seguro (por ejemplo, un gestor de contraseñas).
            </CardDescription>
        </CardHeader>
        <CardContent>
            <div
                class="flex flex-col gap-3 select-none sm:flex-row sm:items-center sm:justify-between"
            >
                <Button
                    variant="outline"
                    @click="toggleRecoveryCodesVisibility"
                    class="w-fit"
                >
                    <component
                        :is="isRecoveryCodesVisible ? EyeOff : Eye"
                        class="size-4"
                    />
                    {{
                        isRecoveryCodesVisible
                            ? 'Ocultar códigos'
                            : 'Ver códigos'
                    }}
                </Button>

                <Form
                    v-if="isRecoveryCodesVisible && recoveryCodesList.length"
                    v-bind="regenerateRecoveryCodes.form()"
                    method="post"
                    :options="{ preserveScroll: true }"
                    @success="fetchRecoveryCodes"
                    #default="{ processing }"
                >
                    <Button
                        variant="secondary"
                        type="submit"
                        :disabled="processing"
                    >
                        <RefreshCw /> Generar códigos nuevos
                    </Button>
                </Form>
            </div>
            <div
                :class="[
                    'relative overflow-hidden transition-all duration-300',
                    isRecoveryCodesVisible
                        ? 'h-auto opacity-100'
                        : 'h-0 opacity-0',
                ]"
            >
                <div v-if="errors?.length" class="mt-6">
                    <AlertError :errors="errors" />
                </div>
                <div v-else class="mt-3 space-y-3">
                    <div
                        ref="recoveryCodeSectionRef"
                        class="grid gap-1 rounded-lg bg-muted p-4 font-mono text-sm"
                    >
                        <div v-if="!recoveryCodesList.length" class="space-y-2">
                            <div
                                v-for="n in 8"
                                :key="n"
                                class="app-skeleton h-4 rounded bg-muted-foreground/20"
                            ></div>
                        </div>
                        <div
                            v-else
                            v-for="(code, index) in recoveryCodesList"
                            :key="index"
                        >
                            {{ code }}
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground select-none">
                        Cada código sirve una sola vez y se elimina al usarlo.
                        Si se te acaban, pulsa
                        <span class="font-bold">Generar códigos nuevos</span>
                        (los anteriores dejarán de funcionar).
                    </p>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
