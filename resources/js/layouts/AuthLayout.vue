<script setup lang="ts">
/**
 * Layout de las pantallas de autenticación (excepto Login y ForgotPassword,
 * que traen su propio diseño). Delega en AuthSimpleLayout. Asignado desde app.ts.
 */
import AuthLayout from '@/layouts/auth/AuthSimpleLayout.vue';

/** Props: título y descripción que se muestran encima del formulario. */
const { title = '', description = '' } = defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <AuthLayout :title="title" :description="description">
        <slot />
    </AuthLayout>
</template>
