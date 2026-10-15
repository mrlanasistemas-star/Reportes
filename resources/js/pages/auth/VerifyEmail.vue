<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

defineOptions({
    layout: {
        title: 'Verifica tu correo',
        description:
            'Te enviamos un enlace a tu correo. Ábrelo para confirmar tu dirección y continuar.',
    },
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head title="Verificación de correo" />

    <div
        v-if="status === 'verification-link-sent'"
        role="status"
        class="app-banner app-banner-success mb-4"
    >
        Te enviamos un nuevo enlace de verificación. Revisa también la carpeta de spam.
    </div>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            {{ processing ? 'Enviando…' : 'Reenviar correo de verificación' }}
        </Button>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            Cerrar sesión
        </TextLink>
    </Form>
</template>
